<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Provider;

use Nowo\VerifactuBundle\Provider\ConfigIssuerProvider;
use Nowo\VerifactuBundle\Provider\ConfigSoftwareIdentityProvider;
use PHPUnit\Framework\TestCase;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class ConfigProvidersTest extends TestCase
{
    public function testIssuerFromConfiguration(): void
    {
        $issuer = (new ConfigIssuerProvider(['nif' => '89890001K', 'name' => 'Issuer']))->getIssuer();

        self::assertSame('89890001K', $issuer->nif);
        self::assertSame('Issuer', $issuer->name);
        self::assertSame(['nif' => '', 'name' => ''], (new ConfigIssuerProvider())->getIssuer()->toArray());
    }

    public function testSoftwareIdentityFromConfiguration(): void
    {
        $identity = (new ConfigSoftwareIdentityProvider(
            ['id' => 'SB', 'name' => 'Beacon', 'version' => '1.0', 'manufacturer_nif' => 'B12345678', 'manufacturer_name' => 'Producer', 'solo_verifactu' => false],
            ['number' => '1'],
        ))->getSoftwareIdentity();

        self::assertSame('Producer', $identity->producerName);
        self::assertSame('B12345678', $identity->producerNif);
        self::assertSame('Beacon', $identity->name);
        self::assertSame('SB', $identity->id);
        self::assertSame('1.0', $identity->version);
        self::assertSame('1', $identity->installationNumber);
        self::assertFalse($identity->soloVerifactu);

        $empty = (new ConfigSoftwareIdentityProvider())->getSoftwareIdentity();
        self::assertSame('', $empty->installationNumber);
        self::assertTrue($empty->soloVerifactu);
    }
}
