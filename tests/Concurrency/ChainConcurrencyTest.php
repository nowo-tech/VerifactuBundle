<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Concurrency;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\VerifactuBundle\Entity\BillingRecordHashChain;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Repository\HashChainRepositoryInterface;
use Nowo\VerifactuBundle\Service\BillingRecordProcessor;
use Nowo\VerifactuBundle\Service\ChainVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function count;
use function dirname;
use function sprintf;

use const FILE_IGNORE_NEW_LINES;
use const PHP_BINARY;
use const STDERR;

/**
 * Several PHP processes chain records for the same issuer (or the first records of many new issuers) at the same time
 * on a real server database (MySQL, MariaDB, PostgreSQL). Skipped unless VERIFACTU_TEST_DATABASE_URL is set (the `concurrency` CI job sets it).
 *
 * Every scenario must end with: every record committed (retryable lock errors and ChainTransactionLostException are
 * retried by the worker as a whole unit of work, any other error fails), every worker connection back at nesting level
 * 0, the host's own rows committed exactly once, no two records chained onto the same predecessor, every record
 * reachable from its issuer's head, and, with the record store, a contiguous log that `ChainVerifier` accepts.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[Group('concurrency')]
final class ChainConcurrencyTest extends TestCase
{
    private const RECORDS = 30;

    private const DEADLINE_SECONDS = 300;

    /**
     * Mode, record store, existing head, workers, issuers.
     *
     * @return iterable<string, array{string, string, bool, int, int}>
     */
    public static function scenarios(): iterable
    {
        foreach (['doctrine', 'none'] as $store) {
            foreach (['plain', 'hosttx', 'hosttx_read'] as $mode) {
                foreach ([true, false] as $seeded) {
                    yield sprintf('%s, store %s, %s', $mode, $store, $seeded ? 'existing head' : 'first record') => [$mode, $store, $seeded, 4, 1];
                }
                // 8 processes create the first record of the same 30 new issuers at the same time: the realistic host
                // that deadlocks on MySQL inside its own transaction (audit KW-D-H1).
                yield sprintf('%s, store %s, 8 processes x 30 new issuers', $mode, $store) => [$mode, $store, false, 8, 30];
            }
        }
    }

    #[DataProvider('scenarios')]
    public function testConcurrentProcessesNeverForkTheChain(string $mode, string $store, bool $seeded, int $workers, int $issuers): void
    {
        if (ConcurrencyKernel::databaseUrl() === '') {
            self::markTestSkipped('Set VERIFACTU_TEST_DATABASE_URL (MySQL, MariaDB or PostgreSQL) to run the concurrency tests.');
        }

        $kernel = new ConcurrencyKernel('test', false, $store);
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

        /** @var array<string, list<array{string, string}>> $committed issuer => [previous hash, hash] */
        $committed = [];
        if ($seeded) {
            /** @var BillingRecordProcessor $processor */
            $processor = $container->get(BillingRecordProcessor::SERVICE_NAME);
            $seed      = $processor->process(new BillingRecord(RecordType::Alta, '89890001K', 'SEED', '09-07-2026', 'F1', '21.00', '121.00', '2026-07-09T16:00:00+02:00'));
            self::assertSame([], $seed['errors']);
            $committed['89890001K'][] = ['', (string) $seed['record']->hash];
        }
        $connection = $entityManager->getConnection();
        $connection->close();

        [$lines, $retries] = $this->runWorkers($mode, $store, $workers, $issuers);
        $errors            = array_values(array_filter($lines, static fn (string $l): bool => $l[0] === 'E'));
        $nesting           = array_values(array_filter($lines, static fn (string $l): bool => $l[0] === 'N'));
        foreach (array_filter($lines, static fn (string $l): bool => $l[0] === 'C') as $line) {
            [, $issuer, $previous, $hash] = explode(' ', $line);
            $committed[$issuer][]         = [$previous, $hash];
        }

        self::assertSame([], $errors, 'Only retryable lock errors are allowed');
        self::assertSame(array_fill(0, $workers, 'N 0'), $nesting, 'Every worker connection must end outside any transaction');
        self::assertCount($issuers, $committed);
        $total = $workers * self::RECORDS + ($seeded ? 1 : 0);
        self::assertSame($total, array_sum(array_map('count', $committed)));
        if ($mode !== 'plain') {
            // The host's own write commits exactly once per chained record: a lost transaction was redone as a whole.
            self::assertSame($workers * self::RECORDS, (int) $connection->fetchOne('SELECT COUNT(*) FROM host_invoice'));
        }

        /** @var HashChainRepositoryInterface $heads */
        $heads = $doctrine->getRepository(BillingRecordHashChain::class);
        foreach ($committed as $issuer => $records) {
            $previousHashes = array_column($records, 0);
            self::assertSame($previousHashes, array_unique($previousHashes), 'Two records chained onto the same predecessor (fork) for ' . $issuer);

            $byHash = array_column($records, 0, 1);
            $walk   = 0;
            for ($hash = $heads->getLastState($issuer)?->hash; $hash !== null && $hash !== ''; $hash = $byHash[$hash] ?? null) {
                ++$walk;
            }
            self::assertSame(count($records), $walk, 'Every committed record must be reachable from the chain head of ' . $issuer);

            if ($store === 'doctrine') {
                /** @var ChainVerifier $verifier */
                $verifier = $container->get(ChainVerifier::SERVICE_NAME);
                $result   = $verifier->verify($issuer);
                self::assertSame([], $result->problems, $issuer);
                self::assertSame(count($records), $result->checkedRecords);
                self::assertNull($result->continuesFrom);
            }
        }

        fwrite(STDERR, sprintf("[%s] %d records, %d retried lock errors\n", $this->nameWithDataSet(), $total, $retries));
        $kernel->shutdown();
    }

    /**
     * @return array{list<string>, int}
     */
    private function runWorkers(string $mode, string $store, int $workers, int $issuers): array
    {
        $directory = sys_get_temp_dir() . '/verifactu-concurrency/run-' . bin2hex(random_bytes(4));
        mkdir($directory, 0o777, true);
        $environment = [
            'VERIFACTU_TEST_DATABASE_URL' => ConcurrencyKernel::databaseUrl(),
            'VERIFACTU_TEST_RECORD_STORE' => $store,
            'VERIFACTU_TEST_START_AT'     => (string) (microtime(true) + 3),
        ] + getenv();

        $processes = [];
        for ($worker = 1; $worker <= $workers; ++$worker) {
            $command = [PHP_BINARY, __DIR__ . '/worker.php', $mode, (string) self::RECORDS, (string) $worker, $directory . '/w' . $worker . '.log', (string) $issuers];
            // @phpstan-ignore frankenphp.classic.noUnlimitedIoTimeout (CLI test helper; the deadline below terminates hung workers)
            $process = proc_open($command, [1 => ['file', $directory . '/out' . $worker, 'w'], 2 => ['file', $directory . '/err' . $worker, 'w']], $pipes, dirname(__DIR__, 2), $environment);
            if ($process === false) {
                throw new RuntimeException('Cannot start a worker process.');
            }
            $processes[$worker] = $process;
        }

        $deadline = microtime(true) + self::DEADLINE_SECONDS;
        $lines    = [];
        foreach ($processes as $worker => $process) {
            while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
                usleep(50_000);
            }
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
                self::fail(sprintf('Worker %d did not finish within %d s.', $worker, self::DEADLINE_SECONDS));
            }
            proc_close($process);
            $log = file($directory . '/w' . $worker . '.log', FILE_IGNORE_NEW_LINES);
            self::assertIsArray($log, (string) @file_get_contents($directory . '/err' . $worker));
            $lines = [...$lines, ...$log];
        }

        $retries = count(array_filter($lines, static fn (string $l): bool => $l[0] === 'R'));

        $kept = array_filter($lines, static fn (string $l): bool => $l[0] !== 'R');

        return [array_values($kept), $retries];
    }
}
