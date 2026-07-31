<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Command;

use Nowo\VerifactuBundle\Certificate\CertificateLoader;
use Nowo\VerifactuBundle\Client\AeatEndpointResolver;
use Nowo\VerifactuBundle\Client\NullAeatSubmissionClient;
use Nowo\VerifactuBundle\Client\SoapAeatSubmissionClient;
use Nowo\VerifactuBundle\Client\SoapEnvelopeBuilder;
use Nowo\VerifactuBundle\Command\SubmitToAeatSandboxCommand;
use Nowo\VerifactuBundle\Generator\BillingRecordXmlGenerator;
use Nowo\VerifactuBundle\Generator\HashChainGenerator;
use Nowo\VerifactuBundle\Integration\InvoiceToBillingRecordMapper;
use Nowo\VerifactuBundle\Repository\InMemoryHashChainRepository;
use Nowo\VerifactuBundle\Service\BillingRecordProcessor;
use Nowo\VerifactuBundle\Signer\XadesBillingRecordSigner;
use Nowo\VerifactuBundle\Tests\Support\FakeSoapTransport;
use Nowo\VerifactuBundle\Tests\Unit\Helper\TranslationHelper;
use Nowo\VerifactuBundle\Validator\AeatBusinessRulesValidator;
use Nowo\VerifactuBundle\Validator\SpanishTaxIdValidator;
use Nowo\VerifactuBundle\Validator\XsdValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\Translation\TranslatorInterface;

use function dirname;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class SubmitToAeatSandboxCommandTest extends TestCase
{
    public function testWarnsWhenSubmitRunsOutsideSandboxEnvironment(): void
    {
        $tester = $this->createTester(
            $this->createProcessor(new NullAeatSubmissionClient()),
            certificatePath: '/tmp/cert.p12',
            environment: 'production',
        );

        self::assertSame(0, $tester->execute([
            '--fecha'        => '09-07-2026',
            '--generated-at' => '2026-07-09T16:00:00+02:00',
            '--submit'       => true,
        ]));
        self::assertStringContainsString('Environment is "production"', $tester->getDisplay());
        self::assertStringContainsString('AEAT submission OK', $tester->getDisplay());
    }

    public function testReportsSignedXmlPresence(): void
    {
        $signer = new XadesBillingRecordSigner(
            new CertificateLoader(),
            dirname(__DIR__, 2) . '/Fixtures/certs/test.p12',
            'test',
        );

        $tester = $this->createTester(
            $this->createProcessor(new NullAeatSubmissionClient(), mode: 'no_verifactu', recordSigner: $signer),
        );

        self::assertSame(0, $tester->execute([
            '--fecha'        => '09-07-2026',
            '--generated-at' => '2026-07-09T16:00:00+02:00',
            '--dry-run'      => true,
        ]));
        self::assertStringContainsString('XAdES signature: present', $tester->getDisplay());
    }

    public function testReportsAeatSubmissionFailure(): void
    {
        $client = new SoapAeatSubmissionClient(
            new SoapEnvelopeBuilder(),
            new AeatEndpointResolver(),
            new FakeSoapTransport(error: 'AEAT rejected'),
            ['nif' => '89890001K', 'name' => 'Test Issuer'],
            'verifactu',
            'sandbox',
            dirname(__DIR__, 2) . '/Fixtures/certs/test.p12',
            'test',
        );

        $tester = $this->createTester(
            $this->createProcessor($client),
            certificatePath: dirname(__DIR__, 2) . '/Fixtures/certs/test.p12',
        );

        self::assertSame(1, $tester->execute([
            '--fecha'        => '09-07-2026',
            '--generated-at' => '2026-07-09T16:00:00+02:00',
            '--submit'       => true,
        ]));
        self::assertStringContainsString('AEAT submission failed', $tester->getDisplay());
        self::assertStringContainsString('AEAT rejected', $tester->getDisplay());
    }

    private function createTester(
        BillingRecordProcessor $processor,
        ?string $certificatePath = null,
        string $environment = 'sandbox',
    ): CommandTester {
        $command = new SubmitToAeatSandboxCommand(
            new InvoiceToBillingRecordMapper(),
            $processor,
            $certificatePath,
            $environment,
            ['nif' => '89890001K', 'name' => 'Test Issuer'],
        );

        return new CommandTester($command);
    }

    private function createProcessor(
        NullAeatSubmissionClient|SoapAeatSubmissionClient $submissionClient,
        string $mode = 'verifactu',
        ?XadesBillingRecordSigner $recordSigner = null,
    ): BillingRecordProcessor {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(TranslationHelper::createTranslatorCallback());

        return new BillingRecordProcessor(
            new AeatBusinessRulesValidator(new SpanishTaxIdValidator(), $translator),
            new HashChainGenerator(),
            new BillingRecordXmlGenerator(),
            new XsdValidator($translator, true),
            new InMemoryHashChainRepository(),
            $submissionClient,
            new EventDispatcher(),
            ['nif' => '89890001K', 'name' => 'Test Issuer'],
            [
                'manufacturer_nif'  => '89890001K',
                'manufacturer_name' => 'Nowo.tech',
                'name'              => 'VerifactuBundle',
                'id'                => '01',
                'version'           => '1.0.0',
            ],
            ['number' => '001'],
            $mode,
            false,
            $recordSigner,
        );
    }
}
