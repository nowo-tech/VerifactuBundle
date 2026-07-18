<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\DependencyInjection;

use Nowo\VerifactuBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class ConfigurationTest extends TestCase
{
    public function testDefaultConfiguration(): void
    {
        $processor = new Processor();
        $config    = $processor->processConfiguration(new Configuration(), [[]]);

        self::assertSame('verifactu', $config['mode']);
        self::assertSame('', $config['issuer']['nif']);
        self::assertSame('sandbox', $config['aeat']['environment']);
        self::assertSame(35, $config['qr']['size_mm']);
    }
}
