<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Model;

use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use PHPUnit\Framework\TestCase;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class BillingRecordTest extends TestCase
{
    public function testWithMethodsReturnNewInstances(): void
    {
        $record = new BillingRecord(
            RecordType::Alta,
            '89890001K',
            'FAC-001',
            '09-07-2026',
            'F1',
            '21.00',
            '121.00',
            '2026-07-09T16:00:00+02:00',
        );

        $withHash = $record->withHash('HASH', 'PREV');
        $withXml  = $withHash->withXml('<xml/>');
        $signed   = $withXml->withSignedXml('<signed/>');

        self::assertNull($record->hash);
        self::assertSame('HASH', $withHash->hash);
        self::assertSame('PREV', $withHash->previousHash);
        self::assertSame('<xml/>', $withXml->xml);
        self::assertSame('<signed/>', $signed->signedXml);
    }
}
