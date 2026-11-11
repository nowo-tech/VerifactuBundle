<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Certificate;

use Nowo\VerifactuBundle\Certificate\Certificate;
use Nowo\VerifactuBundle\Certificate\CertificateProviderInterface;
use Nowo\VerifactuBundle\Certificate\TemporaryCertificateFile;
use Nowo\VerifactuBundle\Client\AeatEndpointResolver;
use Nowo\VerifactuBundle\Client\SoapAeatSubmissionClient;
use Nowo\VerifactuBundle\Client\SoapEnvelopeBuilder;
use Nowo\VerifactuBundle\Client\SoapTransportInterface;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function dirname;

/**
 * The file fallback must leave nothing on disk, whatever the call does, and keep no state between calls (worker mode).
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class TemporaryCertificateFileTest extends TestCase
{
    public function testTheFileIsPrivateAndRemovedAfterTheCall(): void
    {
        $seen = TemporaryCertificateFile::with(Certificate::fromContents('PEM-BYTES', null, 'pem'), static fn (string $path): array => [
            $path,
            (string) file_get_contents($path),
            fileperms($path) & 0o777,
            fileperms(dirname($path)) & 0o777,
        ]);

        self::assertStringEndsWith('.pem', $seen[0]);
        self::assertSame('PEM-BYTES', $seen[1]);
        self::assertSame(0o600, $seen[2]);
        self::assertSame(0o700, $seen[3]);
        self::assertFileDoesNotExist($seen[0]);
        self::assertDirectoryDoesNotExist(dirname($seen[0]));
    }

    public function testNothingIsLeftAfterAnException(): void
    {
        $before = $this->leftovers();
        $leaked = null;

        try {
            TemporaryCertificateFile::with(Certificate::fromContents('P12'), static function (string $path) use (&$leaked): never {
                $leaked = $path;

                throw new RuntimeException('transport failed');
            });
        } catch (RuntimeException $exception) {
            self::assertSame('transport failed', $exception->getMessage());
        }

        self::assertIsString($leaked);
        self::assertFileDoesNotExist($leaked);
        self::assertDirectoryDoesNotExist(dirname($leaked));
        self::assertSame($before, $this->leftovers());
    }

    public function testTwoSequentialCallsUseTheirOwnFilesAndLeaveNothing(): void
    {
        $before = $this->leftovers();

        $first  = TemporaryCertificateFile::with(Certificate::fromContents('ONE', null, 'pem'), static fn (string $path): array => [$path, (string) file_get_contents($path)]);
        $second = TemporaryCertificateFile::with(Certificate::fromContents('TWO', null, 'pem'), static fn (string $path): array => [$path, (string) file_get_contents($path)]);

        self::assertSame('ONE', $first[1]);
        self::assertSame('TWO', $second[1]);
        self::assertNotSame(dirname($first[0]), dirname($second[0]));
        self::assertDirectoryDoesNotExist(dirname($first[0]));
        self::assertDirectoryDoesNotExist(dirname($second[0]));
        self::assertSame($before, $this->leftovers());
    }

    public function testThePendingRegistryHoldsOnlyTheFileInFlight(): void
    {
        $during = TemporaryCertificateFile::with(Certificate::fromContents('PEM', null, 'pem'), static function (string $path): array {
            $pending = TemporaryCertificateFile::pendingCount();
            // What the shutdown handler does after a fatal error in the operation.
            TemporaryCertificateFile::removePending();

            return [$pending, is_file($path), TemporaryCertificateFile::pendingCount()];
        });

        self::assertSame([1, false, 0], $during);
        self::assertSame(0, TemporaryCertificateFile::pendingCount());
    }

    /**
     * Worker mode: 20 000 submissions through a transport that only takes a path (a decorator, a custom transport)
     * all go through the file fallback. Memory stays flat, the pending registry is empty and no file is left.
     */
    public function testTwentyThousandFallbackSubmissionsKeepMemoryFlat(): void
    {
        $transport = new class implements SoapTransportInterface {
            public int $calls = 0;

            public function send(string $endpoint, string $soapAction, string $payload, string $certificatePath, ?string $certificatePassword, int $timeout): array
            {
                ++$this->calls;

                return ['status_code' => is_file($certificatePath) ? 200 : 500, 'body' => '<EstadoRegistro>Correcto</EstadoRegistro>'];
            }
        };
        $certificates = new class implements CertificateProviderInterface {
            public function getCertificate(string $issuerNif): Certificate
            {
                return Certificate::fromContents('P12-FROM-DATABASE', 'db-pass');
            }
        };
        $client   = new SoapAeatSubmissionClient(new SoapEnvelopeBuilder(), new AeatEndpointResolver(), $transport, ['nif' => '89890001K'], 'verifactu', 'sandbox', null, null, certificateProvider: $certificates);
        $record   = new BillingRecord(RecordType::Alta, '89890001K', 'F-1', '09-07-2026', 'F1', '21.00', '121.00', '2026-07-09T16:00:00+02:00', hash: str_repeat('A', 64));
        $before   = $this->leftovers();
        $baseline = 0;

        for ($i = 1; $i <= 20000; ++$i) {
            self::assertTrue($client->submit($record)['success']);
            if ($i === 1000) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }
        gc_collect_cycles();

        self::assertSame(20000, $transport->calls);
        self::assertLessThan(256 * 1024, memory_get_usage() - $baseline, 'Memory must stay flat across fallback calls');
        self::assertSame(0, TemporaryCertificateFile::pendingCount());
        self::assertSame($before, $this->leftovers());
    }

    public function testACertificateGivenByPathIsPassedThrough(): void
    {
        self::assertSame('/run/a.p12', TemporaryCertificateFile::with(Certificate::fromPath('/run/a.p12'), static fn (string $path): string => $path));
    }

    /**
     * @return list<string>
     */
    private function leftovers(): array
    {
        $directories = glob(sys_get_temp_dir() . '/verifactu-cert-*');

        return $directories === false ? [] : $directories;
    }
}
