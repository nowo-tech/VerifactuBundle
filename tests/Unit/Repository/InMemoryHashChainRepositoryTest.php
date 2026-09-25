<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Repository;

use Nowo\VerifactuBundle\Model\HashChainState;
use Nowo\VerifactuBundle\Repository\InMemoryHashChainRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class InMemoryHashChainRepositoryTest extends TestCase
{
    public function testResetRemovesStoredState(): void
    {
        $repository = new InMemoryHashChainRepository();
        $repository->storeLastState(new HashChainState('89890001K', 'FAC-001', '09-07-2026', 'HASH'));

        $repository->reset('89890001K');

        self::assertNull($repository->getLastState('89890001K'));
    }

    public function testStoreLastHashPreservesPreviousInvoiceFields(): void
    {
        $repository = new InMemoryHashChainRepository();
        $repository->storeLastState(new HashChainState('89890001K', 'FAC-001', '09-07-2026', 'HASH1'));
        $repository->storeLastHash('89890001K', 'HASH2');

        $state = $repository->getLastState('89890001K');
        self::assertNotNull($state);
        self::assertSame('FAC-001', $state->invoiceSeriesNumber);
        self::assertSame('HASH2', $state->hash);
    }

    public function testGetLastHashReturnsNullWhenNoStateExists(): void
    {
        $repository = new InMemoryHashChainRepository();

        self::assertNull($repository->getLastHash('89890001K'));
    }

    public function testClearRemovesEveryIssuer(): void
    {
        $repository = new InMemoryHashChainRepository();
        $repository->storeLastState(new HashChainState('89890001K', 'FAC-001', '09-07-2026', 'HASH1'));
        $repository->storeLastState(new HashChainState('B12345678', 'FAC-002', '09-07-2026', 'HASH2'));

        $repository->clear();

        self::assertNull($repository->getLastState('89890001K'));
        self::assertNull($repository->getLastState('B12345678'));
    }

    public function testStoreLogsWarningWhenNotPersistentWarningIsEnabled(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('hash chain stored in memory'), ['issuer' => '89890001K']);

        $repository = new InMemoryHashChainRepository($logger, true);
        $repository->storeLastState(new HashChainState(' 89890001k ', 'FAC-001', '09-07-2026', 'HASH1'));

        self::assertSame('HASH1', $repository->getLastHash('89890001K'));
    }

    public function testStoreDoesNotLogWhenWarningIsDisabled(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $repository = new InMemoryHashChainRepository($logger);
        $repository->storeLastState(new HashChainState('89890001K', 'FAC-001', '09-07-2026', 'HASH1'));

        (new InMemoryHashChainRepository(null, true))->storeLastState(new HashChainState('89890001K', 'FAC-001', '09-07-2026', 'HASH1'));
        self::assertNotNull($repository->getLastState('89890001K'));
    }
}
