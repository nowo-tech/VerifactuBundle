<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\EventSubscriber;

use Nowo\VerifactuBundle\Repository\InMemoryHashChainRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Clears the in-memory hash chain storage at the start of every main request.
 *
 * Long-running workers (FrankenPHP worker mode) keep the container between requests and may
 * not run the services_resetter; without this, each worker would build its own hash chain.
 * Sub-requests (fragments, ESI) are ignored.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class WorkerStateResetSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly InMemoryHashChainRepository $inMemoryHashChainRepository,
    ) {
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 4096],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->inMemoryHashChainRepository->clear();
    }
}
