<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Model;

use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\ChainVerificationResult;
use Nowo\VerifactuBundle\Model\IssuerIdentity;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Model\SoftwareIdentity;
use Nowo\VerifactuBundle\Model\StoredBillingRecord;
use PHPUnit\Framework\TestCase;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class IdentityModelsTest extends TestCase
{
    public function testIssuerIdentityExportsTheConfigurationShape(): void
    {
        self::assertSame(['nif' => '89890001K', 'name' => 'Issuer'], (new IssuerIdentity('89890001K', 'Issuer'))->toArray());
    }

    public function testSoftwareIdentityExportsTheConfigurationShapes(): void
    {
        $identity = new SoftwareIdentity('Producer SL', 'B12345678', 'Beacon', 'SB', '1.2.3', '1', false);

        self::assertSame([
            'id'                => 'SB',
            'name'              => 'Beacon',
            'version'           => '1.2.3',
            'manufacturer_nif'  => 'B12345678',
            'manufacturer_name' => 'Producer SL',
            'solo_verifactu'    => false,
        ], $identity->toSoftwareConfig());
        self::assertSame(['number' => '1'], $identity->toInstallationConfig());
        self::assertTrue((new SoftwareIdentity('', '', '', '', '', ''))->soloVerifactu);
    }

    public function testStoredRecordAndVerificationResult(): void
    {
        $record = new BillingRecord(RecordType::Alta, '89890001K', 'F-1', '09-07-2026', 'F1', '1.00', '2.00', '2026-07-09T16:00:00+02:00');
        $stored = new StoredBillingRecord(1, $record);

        self::assertSame(StoredBillingRecord::STATUS_PENDING, $stored->submissionStatus);
        self::assertNull($stored->submissionReference);
        self::assertTrue((new ChainVerificationResult('89890001K', 0))->isValid());
        self::assertFalse((new ChainVerificationResult('89890001K', 1, [['position' => 1, 'invoiceSeriesNumber' => 'F-1', 'problem' => 'x']]))->isValid());
    }
}
