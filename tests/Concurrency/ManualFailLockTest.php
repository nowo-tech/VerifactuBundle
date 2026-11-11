<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Concurrency;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\VerifactuBundle\Entity\BillingRecordHashChain;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Model\StoredBillingRecord;
use Nowo\VerifactuBundle\Repository\DoctrineBillingRecordStore;
use Nowo\VerifactuBundle\Repository\HashChainRepositoryInterface;
use Nowo\VerifactuBundle\Service\BillingRecordBatchSubmitter;
use Nowo\VerifactuBundle\Service\BillingRecordProcessor;
use Nowo\VerifactuBundle\Tests\Support\ScriptedBatchClient;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function dirname;

use const PHP_BINARY;

/**
 * LJD3-L3: `submit-pending --fail` and `--retry-failed` take the issuer's chain lock, the lock a claim runs under, on a
 * real server database (MySQL, MariaDB, PostgreSQL): while another process holds the lock (a claim between its SELECT
 * and its UPDATE), the manual change waits for it, so a record is never failed by hand in the middle of a claim and
 * sent while stored failed. Skipped unless VERIFACTU_TEST_DATABASE_URL is set (the `concurrency` CI job sets it).
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[Group('concurrency')]
final class ManualFailLockTest extends TestCase
{
    private const HOLD_MS = 1500;

    public function testManualChangesWaitForTheIssuerChainLock(): void
    {
        if (ConcurrencyKernel::databaseUrl() === '') {
            self::markTestSkipped('Set VERIFACTU_TEST_DATABASE_URL (MySQL, MariaDB or PostgreSQL) to run the concurrency tests.');
        }
        $kernel = new ConcurrencyKernel('test', false, 'doctrine');
        $kernel->boot();
        $container = $kernel->getContainer();
        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $doctrine->getManager();
        $schemaTool    = new SchemaTool($entityManager);
        $metadata      = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
        /** @var BillingRecordProcessor $processor */
        $processor = $container->get(BillingRecordProcessor::SERVICE_NAME);
        self::assertSame([], $processor->process(new BillingRecord(RecordType::Alta, '89890001K', 'L-1', '09-07-2026', 'F1', '21.00', '121.00', '', operationDescription: 'Lock'))['errors']);
        $store = $container->get('nowo_verifactu.billing_record_store');
        self::assertInstanceOf(DoctrineBillingRecordStore::class, $store);
        $heads = $doctrine->getRepository(BillingRecordHashChain::class);
        self::assertInstanceOf(HashChainRepositoryInterface::class, $heads);
        $submitter = new BillingRecordBatchSubmitter($store, new ScriptedBatchClient(), $heads, new EventDispatcher());

        self::assertTrue($this->whileLocked(static fn (): bool => $submitter->failRecord('89890001K', 1, 'by hand')));
        self::assertSame(StoredBillingRecord::STATUS_FAILED, [...$store->findByIssuer('89890001K')][0]->submissionStatus);

        self::assertSame(1, $this->whileLocked(static fn (): int => $submitter->requeueFailed('89890001K')));
        self::assertSame(StoredBillingRecord::STATUS_PENDING, [...$store->findByIssuer('89890001K')][0]->submissionStatus);

        $entityManager->getConnection()->close();
        $kernel->shutdown();
    }

    /**
     * Runs $operation while another process holds the issuer's chain lock, checks it finished only after the release and
     * returns its result.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private function whileLocked(callable $operation): mixed
    {
        $directory = sys_get_temp_dir() . '/verifactu-concurrency/lock-' . bin2hex(random_bytes(4));
        mkdir($directory, 0o777, true);
        $environment = ['VERIFACTU_TEST_DATABASE_URL' => ConcurrencyKernel::databaseUrl(), 'VERIFACTU_TEST_RECORD_STORE' => 'doctrine'] + getenv();
        $command     = [PHP_BINARY, __DIR__ . '/chain-lock-holder.php', '89890001K', $directory, (string) self::HOLD_MS];
        // @phpstan-ignore frankenphp.classic.noUnlimitedIoTimeout (CLI test helper; the deadline below terminates a hung holder)
        $process = proc_open($command, [1 => ['file', $directory . '/out', 'w'], 2 => ['file', $directory . '/err', 'w']], $pipes, dirname(__DIR__, 2), $environment);
        if ($process === false) {
            throw new RuntimeException('Cannot start the lock holder.');
        }
        $deadline = microtime(true) + 60;
        while (!is_file($directory . '/ready') && proc_get_status($process)['running'] && microtime(true) < $deadline) {
            usleep(10_000);
        }
        self::assertFileExists($directory . '/ready', (string) @file_get_contents($directory . '/err'));

        $result   = $operation();
        $finished = microtime(true);

        while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
            usleep(10_000);
        }
        proc_close($process);
        self::assertFileExists($directory . '/released', (string) @file_get_contents($directory . '/err'));
        $released = (float) file_get_contents($directory . '/released');
        self::assertGreaterThan($released, $finished, 'The manual change waited for the chain lock');

        return $result;
    }
}
