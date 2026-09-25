<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\EventSubscriber;

use Nowo\VerifactuBundle\EventSubscriber\WorkerStateResetSubscriber;
use Nowo\VerifactuBundle\Model\HashChainState;
use Nowo\VerifactuBundle\Repository\InMemoryHashChainRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class WorkerStateResetSubscriberTest extends TestCase
{
    public function testSubscribesToKernelRequestWithHighPriority(): void
    {
        self::assertSame(
            [KernelEvents::REQUEST => ['onKernelRequest', 4096]],
            WorkerStateResetSubscriber::getSubscribedEvents(),
        );
    }

    public function testTwoConsecutiveMainRequestsDoNotShareHashChainState(): void
    {
        $repository = new InMemoryHashChainRepository();
        $subscriber = new WorkerStateResetSubscriber($repository);

        $subscriber->onKernelRequest($this->createEvent(HttpKernelInterface::MAIN_REQUEST));
        $repository->storeLastState(new HashChainState('89890001K', 'FAC-001', '09-07-2026', str_repeat('A', 64)));
        $repository->storeLastState(new HashChainState('B12345678', 'FAC-900', '09-07-2026', str_repeat('B', 64)));

        $subscriber->onKernelRequest($this->createEvent(HttpKernelInterface::MAIN_REQUEST));

        self::assertNull($repository->getLastState('89890001K'));
        self::assertNull($repository->getLastState('B12345678'));
    }

    public function testSubRequestKeepsHashChainState(): void
    {
        $repository = new InMemoryHashChainRepository();
        $subscriber = new WorkerStateResetSubscriber($repository);
        $repository->storeLastState(new HashChainState('89890001K', 'FAC-001', '09-07-2026', str_repeat('A', 64)));

        $subscriber->onKernelRequest($this->createEvent(HttpKernelInterface::SUB_REQUEST));

        self::assertNotNull($repository->getLastState('89890001K'));
    }

    private function createEvent(int $requestType): RequestEvent
    {
        return new RequestEvent($this->createMock(HttpKernelInterface::class), new Request(), $requestType);
    }
}
