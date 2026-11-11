<?php

declare(strict_types=1);

/*
 * One process of the submission concurrency test. Usage: submitter.php <mode> <worker-id> <log-file> <records>
 * Modes:
 *   chain:  chains <records> records for the issuer (each in its own process() call, retried on lock errors).
 *   submit: sends the issuer's waiting records with BillingRecordBatchSubmitter (batch size 7, no flow control) until
 *           the chainers are done and nothing is waiting nor in flight. Its batch client appends one line per request
 *           to the shared log ("B <worker> <answer> <number@hash...>"); the first request of the whole run (an atomic marker
 *           file) gets a transport failure (no answer), so those records must be sent again.
 * Output lines in the worker log: "E <class>: <message>" per failure, "R <class>" per retried lock error.
 */

use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\VerifactuBundle\Client\BatchAeatSubmissionClientInterface;
use Nowo\VerifactuBundle\Entity\BillingRecordHashChain;
use Nowo\VerifactuBundle\Model\AeatBatchResponse;
use Nowo\VerifactuBundle\Model\BatchSubmissionResult;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Repository\HashChainRepositoryInterface;
use Nowo\VerifactuBundle\Repository\SubmissionQueueInterface;
use Nowo\VerifactuBundle\Service\BillingRecordBatchSubmitter;
use Nowo\VerifactuBundle\Service\BillingRecordProcessor;
use Nowo\VerifactuBundle\Tests\Concurrency\ConcurrencyKernel;
use Nowo\VerifactuBundle\Tests\Support\ScriptedBatchClient;
use Symfony\Component\EventDispatcher\EventDispatcher;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/** @var list<string> $arguments */
$arguments                               = $_SERVER['argv'];
[, $mode, $workerId, $logFile, $records] = $arguments;
$directory                               = dirname($logFile);

$kernel = new ConcurrencyKernel('test', false);
$kernel->boot();
$container = $kernel->getContainer();
/** @var ManagerRegistry $doctrine */
$doctrine = $container->get('doctrine');
$output   = fopen($logFile, 'w');
if ($output === false) {
    throw new RuntimeException('Cannot write ' . $logFile);
}

$startAt = (float) getenv('VERIFACTU_TEST_START_AT');
while (microtime(true) < $startAt) {
    usleep(1000);
}

if ($mode === 'chain') {
    /** @var BillingRecordProcessor $processor */
    $processor = $container->get(BillingRecordProcessor::SERVICE_NAME);
    for ($i = 1; $i <= (int) $records; ++$i) {
        $record = new BillingRecord(RecordType::Alta, '89890001K', sprintf('C%s-%03d', $workerId, $i), '09-07-2026', 'F1', '21.00', '121.00', '', operationDescription: 'Concurrency');
        for ($attempt = 1;; ++$attempt) {
            try {
                $result = $processor->process($record);
                if ($result['errors'] !== []) {
                    fwrite($output, 'E validation: ' . implode('; ', $result['errors']) . "\n");
                }
                break;
            } catch (RetryableException $exception) {
                if ($attempt >= 50) {
                    fwrite($output, 'E ' . $exception::class . "\n");
                    break;
                }
                fwrite($output, 'R ' . $exception::class . "\n");
                usleep(random_int(1000, 20000));
            }
        }
        usleep(random_int(0, 3000));
    }
    touch($directory . '/chainer-' . $workerId . '.done');
} else {
    submitPending($container, $doctrine, $directory, (string) $workerId, $output);
}
fclose($output);

/**
 * @param resource $output
 */
function submitPending(Psr\Container\ContainerInterface $container, ManagerRegistry $doctrine, string $directory, string $workerId, $output): void
{

    $shared = $directory . '/batches.log';
    $client = new class($shared, $workerId) implements BatchAeatSubmissionClientInterface {
        public function __construct(private readonly string $shared, private readonly string $workerId)
        {
        }

        public function submitBatch(array $records): AeatBatchResponse
        {
            // A slow AEAT: the batch stays in flight long enough for the other submitters to try to claim records.
            usleep(random_int(5000, 30000));
            $marker = @fopen(dirname($this->shared) . '/first-request.marker', 'x');
            $failed = $marker !== false;
            if ($marker !== false) {
                fclose($marker);
            }
            $response = $failed ? AeatBatchResponse::failed('timeout') : ScriptedBatchClient::allAccepted($records, 0);
            $line     = 'B ' . $this->workerId . ' ' . ($failed ? 'none' : 'ok') . ' ' . implode(' ', array_map(static fn (BillingRecord $r): string => $r->invoiceSeriesNumber . '@' . $r->hash, $records)) . "\n";
            file_put_contents($this->shared, $line, \FILE_APPEND | \LOCK_EX);

            return $response;
        }
    };

    /** @var SubmissionQueueInterface $queue */
    $queue = $container->get('nowo_verifactu.billing_record_store');
    $heads = $doctrine->getRepository(BillingRecordHashChain::class);
    if (!$heads instanceof HashChainRepositoryInterface) {
        throw new RuntimeException('The chain head repository is not the Doctrine one.');
    }
    $submitter = new BillingRecordBatchSubmitter($queue, $client, $heads, new EventDispatcher(), null, null, null, 7, 300, 0);
    $deadline  = microtime(true) + 240;
    $chainers  = (int) getenv('VERIFACTU_TEST_CHAINERS');

    while (microtime(true) < $deadline) {
        try {
            $result = $submitter->submitPending('89890001K');
        } catch (RetryableException $exception) {
            fwrite($output, 'R ' . $exception::class . "\n");
            usleep(random_int(1000, 20000));

            continue;
        } catch (Throwable $exception) {
            fwrite($output, 'E ' . $exception::class . ': ' . substr($exception->getMessage(), 0, 300) . "\n");

            break;
        }
        fwrite($output, 'S ' . $result->state . ' ' . count($result->outcomes) . ' ' . $result->remaining . "\n");
        $chainersDone = count(glob($directory . '/chainer-*.done') ?: []) === $chainers;
        if ($result->state === BatchSubmissionResult::STATE_IDLE && $chainersDone) {
            break;
        }
        if ($result->state !== BatchSubmissionResult::STATE_SENT) {
            usleep(random_int(2000, 10000));
        }
    }

    /** @var Doctrine\DBAL\Connection $connection */
    $connection = $doctrine->getConnection();
    $connection->close();
}
