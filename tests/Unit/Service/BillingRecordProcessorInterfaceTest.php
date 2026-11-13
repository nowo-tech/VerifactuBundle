<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Service;

use Nowo\VerifactuBundle\DependencyInjection\NowoVerifactuExtension;
use Nowo\VerifactuBundle\Model\BillingRecordBatchResult;
use Nowo\VerifactuBundle\Model\BillingRecordResult;
use Nowo\VerifactuBundle\Repository\InMemoryHashChainRepository;
use Nowo\VerifactuBundle\Service\BillingRecordProcessor;
use Nowo\VerifactuBundle\Service\BillingRecordProcessorInterface;
use Nowo\VerifactuBundle\Tests\Support\TestProcessorFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * REQ-ARCH-001 rule 1: the processor is reachable through an interface, the typed results mirror the arrays.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class BillingRecordProcessorInterfaceTest extends TestCase
{
    public function testInterfaceIsAliasedAndAHostCanOverrideIt(): void
    {
        $container = new ContainerBuilder();
        (new NowoVerifactuExtension())->load([[]], $container);

        self::assertTrue($container->hasAlias(BillingRecordProcessorInterface::class));
        self::assertSame(BillingRecordProcessor::class, (string) $container->getAlias(BillingRecordProcessorInterface::class));
        self::assertTrue($container->hasDefinition(BillingRecordProcessor::class), 'The old class id keeps working');

        $container->register('app.processor', stdClass::class);
        $container->setAlias(BillingRecordProcessorInterface::class, 'app.processor');
        self::assertSame('app.processor', (string) $container->getAlias(BillingRecordProcessorInterface::class));
    }

    public function testProcessToResultMirrorsProcess(): void
    {
        $processor = TestProcessorFactory::create(new InMemoryHashChainRepository());

        $result = $processor->processToResult(TestProcessorFactory::record('F-1'));

        self::assertTrue($result->isSuccessful());
        self::assertSame('F-1', $result->record->invoiceSeriesNumber);
        self::assertSame([], $result->errors);
        self::assertNull($result->submission);
        self::assertSame([], $result->eventErrors);
        self::assertSame([], $result->storeErrors);
    }

    public function testProcessToResultCarriesValidationErrors(): void
    {
        $processor = TestProcessorFactory::create(new InMemoryHashChainRepository());

        $result = $processor->processToResult(TestProcessorFactory::record(''));

        self::assertFalse($result->isSuccessful());
        self::assertSame(['validation.invoice_series_number.empty'], $result->errors);
    }

    public function testProcessBatchToResultMirrorsProcessBatch(): void
    {
        $processor = TestProcessorFactory::create(new InMemoryHashChainRepository());

        $ok = $processor->processBatchToResult([TestProcessorFactory::record('F-1'), TestProcessorFactory::record('F-2')]);
        self::assertTrue($ok->isSuccessful());
        self::assertCount(2, $ok->records);

        $failed = $processor->processBatchToResult([TestProcessorFactory::record('F-3'), TestProcessorFactory::record('')]);
        self::assertFalse($failed->isSuccessful());
        self::assertSame([], $failed->records);
        self::assertSame([1 => ['validation.invoice_series_number.empty']], $failed->errors);
    }

    public function testSubmissionAndStoreErrorsAreCarriedByTheResult(): void
    {
        $record = TestProcessorFactory::record('F-1');
        $error  = new RuntimeException('store');

        $result = BillingRecordResult::fromArray([
            'record'       => $record,
            'errors'       => [],
            'submission'   => ['status' => 'ok'],
            'event_errors' => [['event' => 'x', 'exception' => $error]],
            'store_errors' => [$error],
        ]);

        self::assertSame(['status' => 'ok'], $result->submission);
        self::assertSame([$error], $result->storeErrors);
        self::assertSame($error, $result->eventErrors[0]['exception']);

        $batch = BillingRecordBatchResult::fromArray(['records' => [$record], 'errors' => [], 'event_errors' => [['event' => 'x', 'exception' => $error]]]);
        self::assertCount(1, $batch->eventErrors);
    }
}
