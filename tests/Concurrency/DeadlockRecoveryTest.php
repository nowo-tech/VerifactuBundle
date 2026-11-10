<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Concurrency;

use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\VerifactuBundle\Exception\ChainTransactionLostException;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Service\BillingRecordProcessor;
use Nowo\VerifactuBundle\Service\ChainVerifier;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function dirname;

use const PHP_BINARY;

/**
 * Worker mode (one kept-alive kernel and connection) on MySQL / MariaDB: a deadlock inside the chain lock while it runs
 * in the host's transaction makes InnoDB roll back the host's whole transaction (audit KW-D-H1). The bundle must close
 * the connection and throw ChainTransactionLostException, so DBAL's nesting level is back to 0 and the next request
 * chains with the row lock again.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[Group('concurrency')]
final class DeadlockRecoveryTest extends TestCase
{
    private const HELD_BY_TEST = '10000001S';

    private const HELD_BY_OTHER = '10000002Q';

    public function testADeadlockInTheHostTransactionClosesTheConnectionAndTheNextRequestChains(): void
    {
        if (!str_starts_with(ConcurrencyKernel::databaseUrl(), 'mysql')) {
            self::markTestSkipped('Needs MySQL or MariaDB (InnoDB rolls back the whole transaction of a deadlock victim).');
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
        $processor  = $container->get(BillingRecordProcessor::SERVICE_NAME);
        $connection = $entityManager->getConnection();

        foreach ([self::HELD_BY_TEST, self::HELD_BY_OTHER] as $issuer) {
            self::assertSame([], $processor->process($this->record($issuer, 'SEED'))['errors']);
        }

        $directory = sys_get_temp_dir() . '/verifactu-concurrency/deadlock-' . bin2hex(random_bytes(4));
        mkdir($directory, 0o777, true);
        $holder = $this->startHolder($directory);

        // Request 1 of the worker: the host transaction locks one issuer, then process() needs the other one.
        $connection->beginTransaction();
        $connection->insert('host_invoice', ['number' => 'HOST-1']);
        $connection->fetchOne('SELECT hash FROM verifactu_hash_chain WHERE issuer_nif = ? FOR UPDATE', [self::HELD_BY_TEST]);
        file_put_contents($directory . '/go', '1');

        try {
            $processor->process($this->record(self::HELD_BY_OTHER, 'FAC-LOST'));
            self::fail('The deadlock must surface');
        } catch (ChainTransactionLostException $exception) {
            self::assertInstanceOf(RetryableException::class, $exception->getPrevious());
        } finally {
            $this->waitFor($holder);
        }

        self::assertSame(0, $connection->getTransactionNestingLevel(), 'DBAL must not stay inside a transaction that no longer exists');
        self::assertFalse($connection->isTransactionActive());
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM host_invoice WHERE number = 'HOST-1'"), 'The server rolled back the host work');

        // Request 2 of the same worker, same connection: the whole unit of work again, with the lock.
        $connection->beginTransaction();
        $connection->insert('host_invoice', ['number' => 'HOST-1']);
        $retried = $processor->process($this->record(self::HELD_BY_OTHER, 'FAC-RETRIED'));
        $connection->commit();
        self::assertSame([], $retried['errors']);
        self::assertSame(0, $connection->getTransactionNestingLevel());

        // Request 3, outside any host transaction.
        self::assertSame([], $processor->process($this->record(self::HELD_BY_TEST, 'FAC-NEXT'))['errors']);

        /** @var ChainVerifier $verifier */
        $verifier = $container->get(ChainVerifier::SERVICE_NAME);
        self::assertSame(2, $verifier->verify(self::HELD_BY_OTHER)->checkedRecords);
        self::assertTrue($verifier->verify(self::HELD_BY_OTHER)->isValid());
        self::assertTrue($verifier->verify(self::HELD_BY_TEST)->isValid());
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM host_invoice WHERE number = 'HOST-1'"));
        $kernel->shutdown();
    }

    private function record(string $issuer, string $number): BillingRecord
    {
        return new BillingRecord(RecordType::Alta, $issuer, $number, '09-07-2026', 'F1', '21.00', '121.00', '', operationDescription: 'Deadlock');
    }

    /**
     * @return resource
     */
    private function startHolder(string $directory)
    {
        $command = [PHP_BINARY, __DIR__ . '/lock-holder.php', self::HELD_BY_OTHER, self::HELD_BY_TEST, $directory];
        // @phpstan-ignore frankenphp.classic.noUnlimitedIoTimeout (CLI test helper; waitFor() terminates it after a deadline)
        $process = proc_open($command, [1 => ['file', $directory . '/out', 'w'], 2 => ['file', $directory . '/err', 'w']], $pipes, dirname(__DIR__, 2), ['VERIFACTU_TEST_DATABASE_URL' => ConcurrencyKernel::databaseUrl(), 'VERIFACTU_TEST_RECORD_STORE' => 'doctrine'] + getenv());
        if ($process === false) {
            throw new RuntimeException('Cannot start the lock holder.');
        }

        $deadline = microtime(true) + 60;
        while (!is_file($directory . '/ready')) {
            if (microtime(true) > $deadline || !proc_get_status($process)['running']) {
                proc_terminate($process);
                self::fail('The lock holder did not start: ' . @file_get_contents($directory . '/err'));
            }
            usleep(10_000);
        }

        return $process;
    }

    /**
     * @param resource $process
     */
    private function waitFor($process): void
    {
        $deadline = microtime(true) + 60;
        while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
            usleep(20_000);
        }
        if (proc_get_status($process)['running']) {
            proc_terminate($process);
        }
        proc_close($process);
    }
}
