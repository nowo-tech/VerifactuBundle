<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Qr;

use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Qr\QrCodeGenerator;
use Nowo\VerifactuBundle\Qr\QrUrlBuilder;
use PHPUnit\Framework\TestCase;

use function extension_loaded;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class QrCodeGeneratorTest extends TestCase
{
    public function testGenerateDataUriReturnsPngDataUri(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('GD extension is required for QR PNG generation.');
        }

        $generator = new QrCodeGenerator(new QrUrlBuilder());
        $record    = new BillingRecord(
            RecordType::Alta,
            '89890001K',
            'FAC-001',
            '09-07-2026',
            'F1',
            '21.00',
            '121.00',
            '2026-07-09T16:00:00+02:00',
            hash: 'ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789',
        );

        $dataUri = $generator->generateDataUri($record, 'sandbox', ['size_mm' => 35]);

        self::assertStringStartsWith('data:image/png;base64,', $dataUri);
        self::assertNotSame('', substr($dataUri, 22));
    }
}
