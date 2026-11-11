<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Command;

use Closure;
use DateTimeImmutable;
use Nowo\VerifactuBundle\Command\SubmitPendingCommand;
use Nowo\VerifactuBundle\Model\AeatBatchResponse;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordSubmissionStatus;
use Nowo\VerifactuBundle\Model\StoredBillingRecord;
use Nowo\VerifactuBundle\Repository\InMemoryBillingRecordStore;
use Nowo\VerifactuBundle\Repository\InMemoryHashChainRepository;
use Nowo\VerifactuBundle\Service\BillingRecordBatchSubmitter;
use Nowo\VerifactuBundle\Submission\CacheFlowControl;
use Nowo\VerifactuBundle\Tests\Support\ScriptedBatchClient;
use Nowo\VerifactuBundle\Tests\Support\TestProcessorFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function count;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class SubmitPendingCommandTest extends TestCase
{
    private ?MockClock $clock = null;

    public function testEveryIssuerWithWaitingRecordsIsSentOnce(): void
    {
        $client = new ScriptedBatchClient(
            static fn (array $records): AeatBatchResponse => new AeatBatchResponse(AeatBatchResponse::ENVIO_PARTIALLY_CORRECT, [
                ScriptedBatchClient::line($records[0], RecordSubmissionStatus::Accepted),
                ScriptedBatchClient::line($records[1], RecordSubmissionStatus::Rejected, 1100, 'x'),
            ], 'CSV', 60),
        );
        [$tester, $store, $heads] = $this->tester($client);
        $this->chain($heads, $store, ['F-1', 'F-2'], '12345678Z');
        $this->chain($heads, $store, ['F-1'], '89890001K');

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('12345678Z: 2 sent (1 accepted, 0 accepted with errors, 1 rejected, 0 unconfirmed duplicates, 0 without answer), 0 waiting; next submission at 2026-11-11T10:01:00', $tester->getDisplay());
        self::assertStringContainsString('89890001K: 1 sent (1 accepted', $tester->getDisplay());

        $this->chain($heads, $store, ['F-2'], '89890001K');
        self::assertSame(Command::SUCCESS, $tester->execute(['--issuer' => ' 89890001k ']));
        self::assertStringContainsString('89890001K: 1 waiting; the AEAT wait time ends at 2026-11-11T10:01:00', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute(['--issuer' => 'B12345674']));
        self::assertStringContainsString('B12345674: nothing to send.', $tester->getDisplay());
    }

    /**
     * LJ-M3: a record the AEAT keeps refusing is reported as failed (exit 1), listed, and --retry-failed puts it back.
     */
    public function testAFailedRecordIsReportedListedAndCanBeRequeued(): void
    {
        $fault = static function (array $records): AeatBatchResponse {
            foreach ($records as $record) {
                if ($record instanceof BillingRecord && $record->invoiceSeriesNumber === 'F-1') {
                    return ScriptedBatchClient::fault();
                }
            }

            return ScriptedBatchClient::allAccepted($records);
        };
        /** @var list<Closure> $script */
        $script                   = array_fill(0, 30, $fault);
        [$tester, $store, $heads] = $this->tester(new ScriptedBatchClient(...$script), false);
        $this->chain($heads, $store, ['F-1', 'F-2'], '89890001K');

        $outputs = [];
        do {
            self::assertSame(Command::FAILURE, $tester->execute(['--issuer' => '89890001K']));
            $outputs[] = $tester->getDisplay();
        } while (count($outputs) < 30 && !str_contains($tester->getDisplay(), 'marked failed'));
        self::assertStringContainsString('89890001K: the AEAT refused record 1 on its own (AEAT SOAP fault: Codigo[4102].Error de prueba.); it is marked failed (--retry-failed puts it back).', $tester->getDisplay());
        self::assertStringContainsString('no AEAT answer (AEAT SOAP fault: Codigo[4102].Error de prueba.); 2 records stay pending.', $outputs[0]);

        self::assertSame(Command::SUCCESS, $tester->execute(['--issuer' => '89890001K', '--list' => true]));
        self::assertStringContainsString('failed', $tester->getDisplay());
        self::assertStringContainsString('F-1', $tester->getDisplay());

        self::assertSame(Command::INVALID, $tester->execute(['--retry-failed' => true]));
        self::assertStringContainsString('--retry-failed needs --issuer.', $tester->getDisplay());

        self::assertSame(Command::FAILURE, $tester->execute(['--issuer' => '89890001K', '--retry-failed' => true]));
        self::assertStringContainsString('89890001K: 1 failed records back to pending.', $tester->getDisplay());
        self::assertSame(StoredBillingRecord::STATUS_PENDING, iterator_to_array($store->findByIssuer('89890001K'))[0]->submissionStatus);
    }

    /**
     * LJD-I1: unconfirmed records are listed and resolved after a check at the AEAT; a paused issuer is reported.
     */
    public function testListResolveAndPause(): void
    {
        $answers = [
            static fn (array $records): AeatBatchResponse => AeatBatchResponse::failed('timeout', 0, '', '', true),
            static fn (array $records): AeatBatchResponse => new AeatBatchResponse(AeatBatchResponse::ENVIO_INCORRECT, [ScriptedBatchClient::line($records[0], RecordSubmissionStatus::Rejected, 3000, 'Duplicado', 'Correcta')], null, 60),
        ];
        [$tester, $store, $heads] = $this->tester(new ScriptedBatchClient(...$answers), false);
        $this->chain($heads, $store, ['F-1'], '89890001K');
        $tester->execute([]);
        $tester->execute([]);

        self::assertSame(Command::SUCCESS, $tester->execute(['--issuer' => '89890001K', '--list' => true]));
        self::assertStringContainsString('unconfirmed', $tester->getDisplay());
        self::assertSame(Command::INVALID, $tester->execute(['--list' => true]));
        self::assertSame(Command::INVALID, $tester->execute(['--issuer' => '89890001K', '--resolve' => 'x', '--as' => 'accepted']));
        self::assertSame(Command::INVALID, $tester->execute(['--issuer' => '89890001K', '--resolve' => '1', '--as' => 'unconfirmed']));
        self::assertSame(Command::INVALID, $tester->execute(['--issuer' => '89890001K', '--resolve' => '1', '--as' => 'accepted']));
        self::assertStringContainsString('--resolve --as=accepted needs --csv', $tester->getDisplay());
        self::assertSame(Command::INVALID, $tester->execute(['--issuer' => '89890001K', '--resolve' => '1', '--as' => 'accepted_errors', '--csv' => ' ']));
        self::assertSame(Command::SUCCESS, $tester->execute(['--issuer' => '89890001K', '--resolve' => '1', '--as' => 'accepted', '--csv' => ' CSV-OK ']));
        self::assertStringContainsString('89890001K: record 1 resolved as accepted.', $tester->getDisplay());
        self::assertSame([StoredBillingRecord::STATUS_ACCEPTED, 'CSV-OK'], [iterator_to_array($store->findByIssuer('89890001K'))[0]->submissionStatus, iterator_to_array($store->findByIssuer('89890001K'))[0]->submissionReference]);
        self::assertSame(Command::FAILURE, $tester->execute(['--issuer' => '89890001K', '--resolve' => '1', '--as' => 'rejected']));
        self::assertStringContainsString('record 1 is not unconfirmed', $tester->getDisplay());
        self::assertSame(Command::SUCCESS, $tester->execute(['--issuer' => '89890001K', '--list' => true]));
        self::assertStringContainsString('no unconfirmed, held or failed records', $tester->getDisplay());

        // An issuer-level code: the issuer is paused at once and says so.
        /** @var list<Closure> $script */
        $script                   = array_fill(0, 20, static fn (array $records): AeatBatchResponse => ScriptedBatchClient::fault(4104));
        [$tester, $store, $heads] = $this->tester(new ScriptedBatchClient(...$script));
        $this->chain($heads, $store, ['P-1'], '89890001K');
        self::assertSame(Command::FAILURE, $tester->execute(['--issuer' => '89890001K']));
        self::assertStringContainsString('89890001K: the AEAT refuses every request of this issuer (AEAT SOAP fault: Codigo[4104].Error de prueba.); paused until', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute(['--issuer' => '89890001K']));
        self::assertStringContainsString('89890001K: paused until', $tester->getDisplay());

        // A lone suspect (unknown code): held, waiting for a probe, not "every request refused".
        /** @var list<Closure> $script */
        $script                   = array_fill(0, 20, static fn (array $records): AeatBatchResponse => ScriptedBatchClient::fault(4199));
        [$tester, $store, $heads] = $this->tester(new ScriptedBatchClient(...$script));
        $this->chain($heads, $store, ['L-1'], '89890001K');
        $i = 0;
        do {
            $tester->execute(['--issuer' => '89890001K']);
            $this->clock?->sleep(61);
        } while (++$i < 20 && !str_contains($tester->getDisplay(), 'paused until'));
        self::assertStringContainsString('89890001K: record(s) 1 held (AEAT SOAP fault: Codigo[4199].Error de prueba.), waiting for another record to test the AEAT with', $tester->getDisplay());
        self::assertSame(Command::SUCCESS, $tester->execute(['--issuer' => '89890001K', '--list' => true]));
        self::assertStringContainsString('held', $tester->getDisplay());

        // The operator marks it failed by hand.
        self::assertSame(Command::INVALID, $tester->execute(['--fail' => '1']));
        self::assertSame(Command::INVALID, $tester->execute(['--issuer' => '89890001K', '--fail' => 'x']));
        self::assertSame(Command::SUCCESS, $tester->execute(['--issuer' => '89890001K', '--fail' => '1']));
        self::assertStringContainsString('89890001K: record 1 marked failed (--retry-failed puts it back).', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $tester->execute(['--issuer' => '89890001K', '--fail' => '1']));
        self::assertStringContainsString('record 1 is not pending, held or confirming', $tester->getDisplay());
    }

    public function testNoAnswerFailsAndAnInFlightIssuerIsReported(): void
    {
        [$tester, $store, $heads] = $this->tester(new ScriptedBatchClient(static fn (array $records): AeatBatchResponse => AeatBatchResponse::failed('timeout')));
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Nothing to send.', $tester->getDisplay());

        $this->chain($heads, $store, ['F-1'], '89890001K');
        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('89890001K: no AEAT answer (timeout); 1 records stay pending.', $tester->getDisplay());

        $store->claimNext('89890001K', 10, 'other', new DateTimeImmutable('2026-11-11 10:00:00'), 300);
        $tester->execute(['--issuer' => '89890001K']);
        self::assertStringContainsString('another process is sending a batch', $tester->getDisplay());
    }

    /**
     * @return array{CommandTester, InMemoryBillingRecordStore, InMemoryHashChainRepository}
     */
    private function tester(ScriptedBatchClient $client, bool $flowControl = true): array
    {
        $heads       = new InMemoryHashChainRepository();
        $store       = new InMemoryBillingRecordStore();
        $this->clock = new MockClock('2026-11-11 10:00:00');
        $submitter   = new BillingRecordBatchSubmitter($store, $client, $heads, new EventDispatcher(), $flowControl ? new CacheFlowControl(new ArrayAdapter()) : null, $this->clock);

        return [new CommandTester(new SubmitPendingCommand($submitter)), $store, $heads];
    }

    /**
     * @param list<string> $numbers
     */
    private function chain(InMemoryHashChainRepository $heads, InMemoryBillingRecordStore $store, array $numbers, string $issuer): void
    {
        $processor = TestProcessorFactory::create($heads, $store);
        foreach ($numbers as $number) {
            self::assertSame([], $processor->process(TestProcessorFactory::record($number, $issuer))['errors']);
        }
    }
}
