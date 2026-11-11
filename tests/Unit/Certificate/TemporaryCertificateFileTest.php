<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Certificate;

use Nowo\VerifactuBundle\Certificate\Certificate;
use Nowo\VerifactuBundle\Certificate\TemporaryCertificateFile;
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
