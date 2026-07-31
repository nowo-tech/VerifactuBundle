<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Client;

use Nowo\VerifactuBundle\Client\AeatEndpointResolver;
use PHPUnit\Framework\TestCase;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class AeatEndpointResolverTest extends TestCase
{
    private AeatEndpointResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new AeatEndpointResolver();
    }

    public function testResolveVerifactuSandboxPersonal(): void
    {
        self::assertSame(
            'https://prewww1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP',
            $this->resolver->resolve('verifactu', 'sandbox', 'personal'),
        );
    }

    public function testResolveNoVerifactuProductionSeal(): void
    {
        self::assertSame(
            'https://www10.agenciatributaria.gob.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/RequerimientoSOAP',
            $this->resolver->resolve('no_verifactu', 'production', 'seal'),
        );
    }

    public function testResolveVerifactuProductionPersonal(): void
    {
        self::assertSame(
            'https://www1.agenciatributaria.gob.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP',
            $this->resolver->resolve('verifactu', 'production', 'personal'),
        );
    }

    public function testResolveVerifactuSandboxSeal(): void
    {
        self::assertSame(
            'https://prewww10.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP',
            $this->resolver->resolve('verifactu', 'sandbox', 'seal'),
        );
    }

    public function testResolveNoVerifactuSandboxPersonal(): void
    {
        self::assertSame(
            'https://prewww1.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/RequerimientoSOAP',
            $this->resolver->resolve('no_verifactu', 'sandbox', 'personal'),
        );
    }

    public function testResolveNoVerifactuSandboxSeal(): void
    {
        self::assertSame(
            'https://prewww10.aeat.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/RequerimientoSOAP',
            $this->resolver->resolve('no_verifactu', 'sandbox', 'seal'),
        );
    }

    public function testResolveVerifactuProductionSeal(): void
    {
        self::assertSame(
            'https://www10.agenciatributaria.gob.es/wlpl/TIKE-CONT/ws/SistemaFacturacion/VerifactuSOAP',
            $this->resolver->resolve('verifactu', 'production', 'seal'),
        );
    }
}
