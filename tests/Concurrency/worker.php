<?php

declare(strict_types=1);

/*
 * One process of the chain concurrency test. Usage: worker.php <mode> <records> <worker-id> <output-file>
 * Modes: plain (no host transaction), hosttx (host transaction around process()), hosttx_read (host transaction
 * that reads something first, so MySQL / MariaDB REPEATABLE READ fix its snapshot before process()).
 * Retryable errors (deadlock, lock wait timeout) are retried like a real host would; any other error is reported.
 * Output lines: "C <previousHash> <hash>" per committed record, "E <exception class>: <message>" per failure.
 */

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Service\BillingRecordProcessor;
use Nowo\VerifactuBundle\Tests\Concurrency\ConcurrencyKernel;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/** @var list<string> $arguments */
$arguments                                  = $_SERVER['argv'];
[, $mode, $records, $workerId, $outputFile] = $arguments;

$kernel = new ConcurrencyKernel('test', false);
$kernel->boot();
/** @var BillingRecordProcessor $processor */
$processor = $kernel->getContainer()->get(BillingRecordProcessor::SERVICE_NAME);
/** @var ManagerRegistry $doctrine */
$doctrine = $kernel->getContainer()->get('doctrine');
/** @var Connection $connection */
$connection = $doctrine->getConnection();
$output     = fopen($outputFile, 'w');
if ($output === false) {
    throw new RuntimeException('Cannot write ' . $outputFile);
}

// Start barrier: every worker has booted its kernel before the first record, so the processes really overlap.
$startAt = (float) getenv('VERIFACTU_TEST_START_AT');
while (microtime(true) < $startAt) {
    usleep(1000);
}

for ($i = 1; $i <= (int) $records; ++$i) {
    // Odd records leave the generation time empty (stamped inside the chain lock), even ones bring their own.
    // VERIFACTU_TEST_EXPLICIT_TIME=1 always brings one (to run this probe against bundle versions before 1.5).
    $generatedAt = $i % 2 === 1 && getenv('VERIFACTU_TEST_EXPLICIT_TIME') !== '1'
        ? ''
        : (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))->format('Y-m-d\TH:i:sP');
    $record = new BillingRecord(RecordType::Alta, '89890001K', sprintf('W%s-%03d', $workerId, $i), '09-07-2026', 'F1', '21.00', '121.00', $generatedAt, operationDescription: 'Concurrency');

    for ($attempt = 1;; ++$attempt) {
        try {
            if ($mode !== 'plain') {
                $connection->beginTransaction();
                if ($mode === 'hosttx_read') {
                    $connection->fetchOne('SELECT COUNT(*) FROM host_invoice');
                    usleep(random_int(0, 2000));
                }
            }
            $result = $processor->process($record);
            if ($mode !== 'plain') {
                $connection->commit();
            }
            if ($result['errors'] !== []) {
                fwrite($output, 'E validation: ' . implode('; ', $result['errors']) . "\n");
            } else {
                fwrite($output, 'C ' . ($result['record']->previousHash ?? '') . ' ' . $result['record']->hash . "\n");
            }
            break;
        } catch (Throwable $exception) {
            while ($connection->isTransactionActive()) {
                try {
                    $connection->rollBack();
                } catch (Throwable) {
                    break;
                }
            }
            if (!$exception instanceof RetryableException || $attempt >= 50) {
                fwrite($output, 'E ' . $exception::class . ': ' . substr($exception->getMessage(), 0, 300) . "\n");
                break;
            }
            fwrite($output, 'R ' . $exception::class . "\n");
            usleep(random_int(1000, 20000));
        }
    }
}

fclose($output);
