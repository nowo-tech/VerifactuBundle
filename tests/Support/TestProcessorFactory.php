<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Support;

use Nowo\VerifactuBundle\Client\NullAeatSubmissionClient;
use Nowo\VerifactuBundle\Generator\BillingRecordXmlGenerator;
use Nowo\VerifactuBundle\Generator\HashChainGenerator;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Provider\AeatEnvironmentProviderInterface;
use Nowo\VerifactuBundle\Provider\IssuerProviderInterface;
use Nowo\VerifactuBundle\Repository\BillingRecordStoreInterface;
use Nowo\VerifactuBundle\Repository\HashChainRepositoryInterface;
use Nowo\VerifactuBundle\Service\BillingRecordProcessor;
use Nowo\VerifactuBundle\Validator\AeatBusinessRulesValidator;
use Nowo\VerifactuBundle\Validator\SpanishTaxIdValidator;
use Nowo\VerifactuBundle\Validator\XsdValidator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Translation\IdentityTranslator;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * A real BillingRecordProcessor (real XSD validation) over the given chain repository and record store.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class TestProcessorFactory
{
    public const ISSUER = '89890001K';

    public static function create(
        HashChainRepositoryInterface $repository,
        ?BillingRecordStoreInterface $store = null,
        ?EventDispatcherInterface $dispatcher = null,
        ?AeatEnvironmentProviderInterface $environmentProvider = null,
        ?IssuerProviderInterface $issuerProvider = null,
    ): BillingRecordProcessor {
        $translator = new IdentityTranslator();

        return new BillingRecordProcessor(
            new AeatBusinessRulesValidator(new SpanishTaxIdValidator(), $translator),
            new HashChainGenerator(),
            new BillingRecordXmlGenerator(),
            new XsdValidator($translator, true),
            $repository,
            new NullAeatSubmissionClient(),
            $dispatcher ?? new EventDispatcher(),
            ['nif' => self::ISSUER, 'name' => 'Test Issuer'],
            ['manufacturer_nif' => self::ISSUER, 'manufacturer_name' => 'Nowo.tech', 'name' => 'VerifactuBundle', 'id' => '01', 'version' => '1.7.0'],
            ['number' => '001'],
            'verifactu',
            false,
            recordStore: $store,
            issuerProvider: $issuerProvider,
            environmentProvider: $environmentProvider,
            enforceProviderIssuer: $issuerProvider instanceof IssuerProviderInterface,
        );
    }

    /**
     * A valid F1 Alta of the test issuer (generation time stamped by the processor).
     */
    public static function record(string $number, string $issuerNif = self::ISSUER, RecordType $type = RecordType::Alta, ?string $correction = null, ?string $previousRejection = null, ?string $withoutPreviousRecord = null): BillingRecord
    {
        return new BillingRecord($type, $issuerNif, $number, '09-07-2026', 'F1', '21.00', '121.00', '', operationDescription: 'Batch test', correction: $correction, previousRejection: $previousRejection, withoutPreviousRecord: $withoutPreviousRecord);
    }
}
