<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Model;

use Nowo\VerifactuBundle\Model\Amount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class AmountTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function amounts(): iterable
    {
        yield 'two decimals' => ['12.35', '12.35', '12.35'];
        yield 'trailing zero' => ['12.30', '12.30', '12.3'];
        yield 'one decimal' => ['12.3', '12.30', '12.3'];
        yield 'whole' => ['12', '12.00', '12'];
        yield 'zero' => ['0.00', '0.00', '0'];
        yield 'bare zero' => ['0', '0.00', '0'];
        yield 'negative zero' => ['-0.00', '0.00', '-0'];
        yield 'negative' => ['-0012.50', '-12.50', '-12.5'];
        yield 'plus sign' => ['+5', '5.00', '5'];
        yield 'spaces' => [' 7.1 ', '7.10', '7.1'];
        yield 'dot only decimals' => ['.5', '0.50', '0.5'];
        yield 'trailing dot' => ['5.', '5.00', '5'];
        yield 'extra zero decimals' => ['21.000000', '21.00', '21'];
        yield 'large' => ['999999999999.99', '999999999999.99', '999999999999.99'];
        yield 'three significant' => ['1.234', '1.234', '1.234'];
        yield 'not a number' => ['abc', 'abc', 'abc'];
        yield 'empty' => ['', '', ''];
        yield 'dot only' => ['.', '.', '.'];
    }

    #[DataProvider('amounts')]
    public function testCanonicalAndLegacyForms(string $input, string $canonical, string $legacy): void
    {
        self::assertSame($canonical, Amount::canonical($input));
        self::assertSame($legacy, Amount::withoutTrailingZeros($input));
    }
}
