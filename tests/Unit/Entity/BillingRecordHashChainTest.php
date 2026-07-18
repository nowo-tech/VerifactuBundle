<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Entity;

use Nowo\VerifactuBundle\Entity\BillingRecordHashChain;
use PHPUnit\Framework\TestCase;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class BillingRecordHashChainTest extends TestCase
{
    public function testConstructorNormalizesValuesAndUpdateRefreshesState(): void
    {
        $entity = new BillingRecordHashChain(' 89890001k ', ' FAC-001 ', ' 09-07-2026 ', ' abcdef ');

        self::assertNull($entity->getId());
        self::assertSame('89890001K', $entity->getIssuerNif());
        self::assertSame('FAC-001', $entity->getInvoiceSeriesNumber());
        self::assertSame('09-07-2026', $entity->getIssueDate());
        self::assertSame('ABCDEF', $entity->getHash());

        $beforeUpdate = $entity->getUpdatedAt();
        $entity->update('FAC-002', '10-07-2026', '123456');

        self::assertSame('FAC-002', $entity->getInvoiceSeriesNumber());
        self::assertSame('10-07-2026', $entity->getIssueDate());
        self::assertSame('123456', $entity->getHash());
        self::assertGreaterThanOrEqual($beforeUpdate, $entity->getUpdatedAt());
    }
}
