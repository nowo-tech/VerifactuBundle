<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Command;

use Nowo\VerifactuBundle\Command\ValidateBillingRecordCommand;
use Nowo\VerifactuBundle\Generator\HashChainGenerator;
use Nowo\VerifactuBundle\Tests\Unit\Helper\TranslationHelper;
use Nowo\VerifactuBundle\Validator\AeatBusinessRulesValidator;
use Nowo\VerifactuBundle\Validator\SpanishTaxIdValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class ValidateBillingRecordCommandTest extends TestCase
{
    private CommandTester $tester;

    protected function setUp(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(TranslationHelper::createTranslatorCallback());

        $command = new ValidateBillingRecordCommand(
            new AeatBusinessRulesValidator(new SpanishTaxIdValidator(), $translator),
            new HashChainGenerator(),
        );

        $this->tester = new CommandTester($command);
    }

    public function testExecuteReturnsSuccessForValidRecord(): void
    {
        $exitCode = $this->tester->execute([
            '--nif'          => '89890001K',
            '--numserie'     => '12345678/G33',
            '--fecha'        => '01-01-2024',
            '--cuota'        => '12.35',
            '--importe'      => '123.45',
            '--generated-at' => '2024-01-01T19:20:30+01:00',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Billing record is valid', $this->tester->getDisplay());
        self::assertStringContainsString('3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60', $this->tester->getDisplay());
    }

    public function testExecuteReturnsFailureForInvalidRecord(): void
    {
        $exitCode = $this->tester->execute([
            '--nif'          => 'INVALID',
            '--numserie'     => '',
            '--fecha'        => 'bad-date',
            '--cuota'        => 'x',
            '--importe'      => 'y',
            '--generated-at' => '',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('error', strtolower($this->tester->getDisplay()));
    }
}
