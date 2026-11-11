<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Command;

use Nowo\VerifactuBundle\Command\ResetChainCommand;
use Nowo\VerifactuBundle\Model\AeatEnvironment;
use Nowo\VerifactuBundle\Model\HashChainState;
use Nowo\VerifactuBundle\Repository\InMemoryHashChainRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

use function str_repeat;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class ResetChainCommandTest extends TestCase
{
    private InMemoryHashChainRepository $heads;

    protected function setUp(): void
    {
        $this->heads = new InMemoryHashChainRepository();
        $this->heads->storeLastState(new HashChainState('89890001K', 'T-1', '09-07-2026', str_repeat('A', 64), AeatEnvironment::Sandbox));
    }

    public function testIssuerIsRequired(): void
    {
        self::assertSame(Command::INVALID, $this->tester()->execute([]));
    }

    public function testNonInteractiveRunWithoutForceKeepsTheHead(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::FAILURE, $tester->execute(['--issuer' => '89890001K'], ['interactive' => false]));
        self::assertNotNull($this->heads->getLastState('89890001K'));
    }

    public function testInteractiveConfirmationResetsTheHead(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['yes']);

        self::assertSame(Command::SUCCESS, $tester->execute(['--issuer' => ' 89890001k ']));
        self::assertStringContainsString('sandbox environment', $tester->getDisplay());
        self::assertNull($this->heads->getLastState('89890001K'));
    }

    public function testForceResetsAndAMissingHeadIsFine(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--issuer' => '89890001K', '--force' => true], ['interactive' => false]));
        self::assertNull($this->heads->getLastState('89890001K'));
        self::assertSame(Command::SUCCESS, $tester->execute(['--issuer' => '89890001K'], ['interactive' => false]));
        self::assertStringContainsString('has no chain head', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new ResetChainCommand($this->heads));
    }
}
