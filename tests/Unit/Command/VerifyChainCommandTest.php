<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Command;

use Nowo\VerifactuBundle\Command\VerifyChainCommand;
use Nowo\VerifactuBundle\Generator\HashChainGenerator;
use Nowo\VerifactuBundle\Repository\InMemoryBillingRecordStore;
use Nowo\VerifactuBundle\Repository\InMemoryHashChainRepository;
use Nowo\VerifactuBundle\Service\ChainVerifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class VerifyChainCommandTest extends TestCase
{
    public function testIssuerIsRequired(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::INVALID, $tester->execute([]));
        self::assertStringContainsString('--issuer', $tester->getDisplay());
    }

    public function testEmptyChainIsValid(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--issuer' => '89890001K']));
        self::assertStringContainsString('0 records', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new VerifyChainCommand(new ChainVerifier(
            new InMemoryBillingRecordStore(),
            new InMemoryHashChainRepository(),
            new HashChainGenerator(),
        )));
    }
}
