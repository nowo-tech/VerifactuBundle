<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Command;

use DateTimeImmutable;
use Nowo\VerifactuBundle\Command\SubmitPendingCommand;
use Nowo\VerifactuBundle\Model\AeatBatchResponse;
use Nowo\VerifactuBundle\Model\RecordSubmissionStatus;
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

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class SubmitPendingCommandTest extends TestCase
{
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
        self::assertStringContainsString('12345678Z: 2 sent (1 accepted, 0 accepted with errors, 1 rejected, 0 without answer), 0 waiting; next submission at 2026-11-11T10:01:00', $tester->getDisplay());
        self::assertStringContainsString('89890001K: 1 sent (1 accepted', $tester->getDisplay());

        $this->chain($heads, $store, ['F-2'], '89890001K');
        self::assertSame(Command::SUCCESS, $tester->execute(['--issuer' => ' 89890001k ']));
        self::assertStringContainsString('89890001K: 1 waiting; the AEAT wait time ends at 2026-11-11T10:01:00', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute(['--issuer' => 'B12345674']));
        self::assertStringContainsString('B12345674: nothing to send.', $tester->getDisplay());
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
    private function tester(ScriptedBatchClient $client): array
    {
        $heads     = new InMemoryHashChainRepository();
        $store     = new InMemoryBillingRecordStore();
        $submitter = new BillingRecordBatchSubmitter($store, $client, $heads, new EventDispatcher(), new CacheFlowControl(new ArrayAdapter()), new MockClock('2026-11-11 10:00:00'));

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
