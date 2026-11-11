<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Command;

use Nowo\VerifactuBundle\Command\VerifyChainCommand;
use Nowo\VerifactuBundle\Generator\HashChainGenerator;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\HashChainState;
use Nowo\VerifactuBundle\Model\RecordType;
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

    public function testUnknownEnvironmentIsRefused(): void
    {
        self::assertSame(Command::INVALID, $this->tester()->execute(['--issuer' => '89890001K', '--environment' => 'staging']));
    }

    public function testEmptyChainIsValid(): void
    {
        $tester = $this->tester();

        self::assertSame(Command::SUCCESS, $tester->execute(['--issuer' => '89890001K']));
        self::assertStringContainsString('0 records', $tester->getDisplay());
    }

    public function testALogStartedOnAnOlderChainIsReportedAndAnAnchorIsChecked(): void
    {
        $generator = new HashChainGenerator();
        $oldHead   = str_repeat('D', 64);
        $record    = new BillingRecord(RecordType::Alta, '89890001K', 'F-10', '09-07-2026', 'F1', '21.00', '121.00', '2026-07-09T16:00:00+02:00', previousHash: $oldHead);
        $hash      = $generator->computeHash($record);
        $record    = $record->withHash($hash)->withXml('<R><Huella>' . $oldHead . '</Huella><CuotaTotal>21.00</CuotaTotal><ImporteTotal>121.00</ImporteTotal><Huella>' . $hash . '</Huella></R>');
        $store     = new InMemoryBillingRecordStore();
        $store->append($record);
        $heads = new InMemoryHashChainRepository();
        $heads->storeLastState(new HashChainState('89890001K', 'F-10', '09-07-2026', $hash));
        $tester = $this->tester($store, $heads);

        self::assertSame(Command::SUCCESS, $tester->execute(['--issuer' => '89890001K']));
        self::assertStringContainsString('starts after an older record', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute(['--issuer' => '89890001K', '--anchor' => $oldHead]));
        self::assertSame(Command::FAILURE, $tester->execute(['--issuer' => '89890001K', '--anchor' => str_repeat('E', 64)]));
    }

    private function tester(?InMemoryBillingRecordStore $store = null, ?InMemoryHashChainRepository $heads = null): CommandTester
    {
        return new CommandTester(new VerifyChainCommand(new ChainVerifier(
            $store ?? new InMemoryBillingRecordStore(),
            $heads ?? new InMemoryHashChainRepository(),
            new HashChainGenerator(),
        )));
    }
}
