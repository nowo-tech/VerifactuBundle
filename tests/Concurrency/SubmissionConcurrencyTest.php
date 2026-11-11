<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Concurrency;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Model\StoredBillingRecord;
use Nowo\VerifactuBundle\Repository\BillingRecordStoreInterface;
use Nowo\VerifactuBundle\Service\BillingRecordProcessor;
use Nowo\VerifactuBundle\Service\ChainVerifier;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_slice;
use function count;
use function dirname;
use function sprintf;

use const FILE_IGNORE_NEW_LINES;
use const PHP_BINARY;
use const STDERR;

/**
 * Several processes send the waiting records of one issuer with BillingRecordBatchSubmitter while other processes
 * keep chaining new records, on a real server database (MySQL, MariaDB, PostgreSQL). Skipped unless
 * VERIFACTU_TEST_DATABASE_URL is set (the `concurrency` CI job sets it).
 *
 * Checks: one batch in flight per issuer, so the answered batches, read in order, list every record exactly once and
 * in chain order; a batch without an answer is sent again; every record ends accepted; the chain is intact.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[Group('concurrency')]
final class SubmissionConcurrencyTest extends TestCase
{
    /** Processes chaining while the others submit (VERIFACTU_TEST_CHAINERS; 0 = the records are chained first). */
    private const CHAINERS = 2;

    /** Submitting processes (VERIFACTU_TEST_SUBMITTERS; local runs under the machine's 2-process limit use 2). */
    private const SUBMITTERS = 6;

    private const RECORDS_PER_CHAINER = 40;

    private const DEADLINE_SECONDS = 300;

    public function testConcurrentSubmittersSendEveryRecordOnceAndInChainOrder(): void
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
        $chainers   = getenv('VERIFACTU_TEST_CHAINERS') !== false ? (int) getenv('VERIFACTU_TEST_CHAINERS') : self::CHAINERS;
        $submitters = getenv('VERIFACTU_TEST_SUBMITTERS') !== false ? max(1, (int) getenv('VERIFACTU_TEST_SUBMITTERS')) : self::SUBMITTERS;
        if ($chainers === 0) {
            /** @var BillingRecordProcessor $processor */
            $processor = $container->get(BillingRecordProcessor::SERVICE_NAME);
            for ($i = 1; $i <= 2 * self::RECORDS_PER_CHAINER; ++$i) {
                $result = $processor->process(new BillingRecord(RecordType::Alta, '89890001K', sprintf('P-%03d', $i), '09-07-2026', 'F1', '21.00', '121.00', '', operationDescription: 'Concurrency'));
                self::assertSame([], $result['errors']);
            }
        }
        $entityManager->getConnection()->close();

        $directory = sys_get_temp_dir() . '/verifactu-concurrency/submit-' . bin2hex(random_bytes(4));
        mkdir($directory, 0o777, true);
        $environment = [
            'VERIFACTU_TEST_DATABASE_URL' => ConcurrencyKernel::databaseUrl(),
            'VERIFACTU_TEST_RECORD_STORE' => 'doctrine',
            'VERIFACTU_TEST_CHAINERS'     => (string) $chainers,
            'VERIFACTU_TEST_START_AT'     => (string) (microtime(true) + 3),
        ] + getenv();

        $processes = [];
        for ($worker = 1; $worker <= $chainers + $submitters; ++$worker) {
            $mode    = $worker <= $chainers ? 'chain' : 'submit';
            $id      = (string) ($mode === 'chain' ? $worker : $worker - $chainers);
            $command = [PHP_BINARY, __DIR__ . '/submitter.php', $mode, $id, $directory . '/' . $mode . $id . '.log', (string) self::RECORDS_PER_CHAINER];
            // @phpstan-ignore frankenphp.classic.noUnlimitedIoTimeout (CLI test helper; the deadline below terminates hung workers)
            $process = proc_open($command, [1 => ['file', $directory . '/out' . $worker, 'w'], 2 => ['file', $directory . '/err' . $worker, 'w']], $pipes, dirname(__DIR__, 2), $environment);
            if ($process === false) {
                throw new RuntimeException('Cannot start a worker process.');
            }
            $processes[$worker] = $process;
        }

        $deadline = microtime(true) + self::DEADLINE_SECONDS;
        $errors   = [];
        $retries  = 0;
        foreach ($processes as $worker => $process) {
            while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
                usleep(50_000);
            }
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
                self::fail(sprintf('Worker %d did not finish within %d s.', $worker, self::DEADLINE_SECONDS));
            }
            proc_close($process);
            $mode = $worker <= $chainers ? 'chain' . $worker : 'submit' . ($worker - $chainers);
            $log  = file($directory . '/' . $mode . '.log', FILE_IGNORE_NEW_LINES);
            self::assertIsArray($log, (string) @file_get_contents($directory . '/err' . $worker));
            $stderr = trim((string) @file_get_contents($directory . '/err' . $worker));
            if ($stderr !== '') {
                $errors[] = $mode . ' stderr: ' . substr($stderr, 0, 500);
            }
            foreach ($log as $line) {
                if ($line[0] === 'E') {
                    $errors[] = $mode . ': ' . $line;
                } elseif ($line[0] === 'R') {
                    ++$retries;
                }
            }
        }
        self::assertSame([], $errors, 'Only retryable lock errors are allowed');

        /** @var BillingRecordStoreInterface $store */
        $store  = $container->get('nowo_verifactu.billing_record_store');
        $stored = [...$store->findByIssuer('89890001K')];
        $total  = ($chainers === 0 ? 2 : $chainers) * self::RECORDS_PER_CHAINER;
        self::assertCount($total, $stored);
        $statuses = array_map(static fn (StoredBillingRecord $s): string => $s->submissionStatus, $stored);
        self::assertSame(array_fill(0, $total, StoredBillingRecord::STATUS_ACCEPTED), $statuses, 'Every record ends accepted; ' . json_encode(array_count_values($statuses)) . '; submitter logs: ' . implode(' | ', array_map(static fn (string $f): string => basename($f) . ': ' . implode(', ', array_slice(file($f, FILE_IGNORE_NEW_LINES) ?: [], 0, 12)), glob($directory . '/submit*.log') ?: [])));

        $batches = file($directory . '/batches.log', FILE_IGNORE_NEW_LINES);
        self::assertIsArray($batches);
        $answered   = [];
        $unanswered = 0;
        foreach ($batches as $batch) {
            $fields = explode(' ', $batch);
            if ($fields[2] === 'none') {
                ++$unanswered;

                continue;
            }
            foreach (array_slice($fields, 3) as $record) {
                $answered[] = explode('@', $record)[1];
            }
        }
        $chainOrder = array_map(static fn (StoredBillingRecord $s): string => (string) $s->record->hash, $stored);
        self::assertSame(1, $unanswered, 'The first request of the run lost its answer');
        self::assertSame($chainOrder, $answered, 'The answered batches list every record exactly once, in chain order');

        /** @var ChainVerifier $verifier */
        $verifier = $container->get(ChainVerifier::SERVICE_NAME);
        self::assertSame([], $verifier->verify('89890001K')->problems);

        fwrite(STDERR, sprintf("[submission, %d chainers, %d submitters] %d records, %d requests (%d without answer), %d retried lock errors\n", $chainers, $submitters, $total, count($batches), $unanswered, $retries));
        $kernel->shutdown();
    }
}
