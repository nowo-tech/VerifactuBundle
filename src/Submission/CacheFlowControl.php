<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Submission;

use DateTimeImmutable;
use Psr\Cache\CacheItemPoolInterface;

use function max;
use function sha1;
use function sprintf;

/**
 * Flow control kept in a PSR-6 cache pool shared by every process (default `cache.app`): one item per key with the
 * time the next submission may start, expiring with it. Stateless service (worker mode).
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class CacheFlowControl implements FlowControlInterface
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public function nextSubmissionAt(string $key): ?DateTimeImmutable
    {
        $item = $this->cache->getItem($this->itemKey($key));
        if (!$item->isHit()) {
            return null;
        }
        $value = $item->get();

        return $value instanceof DateTimeImmutable ? $value : null;
    }

    public function registerSubmission(string $key, DateTimeImmutable $sentAt, int $waitSeconds): void
    {
        $wait = max(0, $waitSeconds);
        $item = $this->cache->getItem($this->itemKey($key));
        $item->set($sentAt->modify(sprintf('+%d seconds', $wait)));
        $item->expiresAfter(max(1, $wait));
        $this->cache->save($item);
    }

    private function itemKey(string $key): string
    {
        return 'nowo_verifactu.flow_control.' . sha1($key);
    }
}
