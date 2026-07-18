<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Client;

use Nowo\VerifactuBundle\Client\NullAeatSubmissionClient;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use PHPUnit\Framework\TestCase;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class NullAeatSubmissionClientTest extends TestCase
{
    public function testSubmitReturnsSandboxReference(): void
    {
        $client = new NullAeatSubmissionClient();
        $record = new BillingRecord(
            RecordType::Alta,
            '89890001K',
            'FAC-001',
            '09-07-2026',
            'F1',
            '21.00',
            '121.00',
            '2026-07-09T16:00:00+02:00',
            hash: 'ABC123',
        );

        $result = $client->submit($record);

        self::assertTrue($result['success']);
        self::assertArrayHasKey('reference', $result);
        self::assertSame('SANDBOX-ABC123', $result['reference']);
    }
}
