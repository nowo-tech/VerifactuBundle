<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit;

use Nowo\VerifactuBundle\NowoVerifactuBundle;
use PHPUnit\Framework\TestCase;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class NowoVerifactuBundleTest extends TestCase
{
    public function testBundleHasExpectedAlias(): void
    {
        $bundle    = new NowoVerifactuBundle();
        $extension = $bundle->getContainerExtension();

        self::assertNotNull($extension);
        self::assertSame('nowo_verifactu', $extension->getAlias());
    }
}
