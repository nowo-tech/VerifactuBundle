<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Integration;

use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Repository\HashChainRepositoryInterface;
use Nowo\VerifactuBundle\Service\BillingRecordProcessor;
use Nowo\VerifactuBundle\Tests\Kernel\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Simulates FrankenPHP worker mode without services_resetter with the default in-memory storage:
 * one booted kernel, two consecutive main requests on the same service instances, no reset().
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class WorkerModeIntegrationTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    public function testInMemoryChainDoesNotSurviveToTheNextMainRequest(): void
    {
        self::bootKernel(['debug' => false]);
        $container = self::getContainer();

        /** @var BillingRecordProcessor $processor */
        $processor = $container->get(BillingRecordProcessor::class);
        /** @var HashChainRepositoryInterface $repository */
        $repository = $container->get(HashChainRepositoryInterface::class);

        // Request 1: first invoice, then a second one chained within the same request
        $this->dispatchRequest(HttpKernelInterface::MAIN_REQUEST);
        $first = $processor->process($this->record('FAC-2026-001'));
        self::assertSame([], $first['errors']);
        $this->dispatchRequest(HttpKernelInterface::SUB_REQUEST);
        $second = $processor->process($this->record('FAC-2026-002'));
        self::assertSame($first['record']->hash, $second['record']->previousHash);

        // Request 2 on the same worker: no per-worker chain carried over
        $this->dispatchRequest(HttpKernelInterface::MAIN_REQUEST);
        self::assertSame($processor, $container->get(BillingRecordProcessor::class));
        self::assertNull($repository->getLastState('89890001K'));

        $third = $processor->process($this->record('FAC-2026-003'));
        self::assertSame([], $third['errors']);
        self::assertNull($third['record']->previousHash);
    }

    public function testServicesResetterClearsInMemoryChain(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var BillingRecordProcessor $processor */
        $processor = $container->get(BillingRecordProcessor::class);
        $processor->process($this->record('FAC-2026-001'));

        $resetter = $container->get('services_resetter');
        self::assertTrue(method_exists($resetter, 'reset'));
        $resetter->reset();

        /** @var HashChainRepositoryInterface $repository */
        $repository = $container->get(HashChainRepositoryInterface::class);
        self::assertNull($repository->getLastState('89890001K'));
    }

    private function record(string $invoiceSeriesNumber): BillingRecord
    {
        return new BillingRecord(
            RecordType::Alta,
            '89890001K',
            $invoiceSeriesNumber,
            '09-07-2026',
            'F1',
            '21.00',
            '121.00',
            '2026-07-09T16:00:00+02:00',
            issuerName: 'Test Issuer',
            operationDescription: 'Worker mode invoice',
        );
    }

    private function dispatchRequest(int $requestType): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $kernel = self::$kernel;
        self::assertInstanceOf(HttpKernelInterface::class, $kernel);

        $request = Request::create('/');
        $request->attributes->set('_controller', 'already_routed');

        $dispatcher->dispatch(new RequestEvent($kernel, $request, $requestType), KernelEvents::REQUEST);
    }
}
