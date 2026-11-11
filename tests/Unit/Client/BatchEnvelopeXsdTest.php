<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Client;

use DOMDocument;
use InvalidArgumentException;
use Nowo\VerifactuBundle\Client\SoapEnvelopeBuilder;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Repository\InMemoryHashChainRepository;
use Nowo\VerifactuBundle\Tests\Support\TestProcessorFactory;
use Nowo\VerifactuBundle\Validator\XsdValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * The batch envelope (one Cabecera, up to 1000 RegistroFactura) validated against the official AEAT SuministroLR.xsd.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class BatchEnvelopeXsdTest extends TestCase
{
    private const NS_LR = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroLR.xsd';

    /**
     * @return iterable<string, array{int}>
     */
    public static function sizes(): iterable
    {
        yield 'one record' => [1];
        yield 'three records' => [3];
        yield 'the AEAT maximum (1000)' => [1000];
    }

    #[DataProvider('sizes')]
    public function testTheBatchEnvelopeIsValidAgainstTheAeatSchema(int $size): void
    {
        $records  = $this->chained($size);
        $envelope = (new SoapEnvelopeBuilder())->buildBatchEnvelope($records, ['name' => 'Test Issuer']);

        self::assertSame([], $this->validate($envelope));
        $document = new DOMDocument();
        $document->loadXML($envelope);
        self::assertSame($size, $document->getElementsByTagNameNS(self::NS_LR, 'RegistroFactura')->length);
        self::assertSame(1, $document->getElementsByTagNameNS(self::NS_LR, 'Cabecera')->length);
        // Chain order inside the request: each record's RegistroAnterior is the record before it.
        $hashes = $document->getElementsByTagName('Huella');
        self::assertSame($records[$size - 1]->hash, $hashes->item($hashes->length - 1)?->textContent);
    }

    public function testCorrectionsAndAnAnulacionAreValid(): void
    {
        $processor = TestProcessorFactory::create(new InMemoryHashChainRepository());
        $records   = [];
        foreach ([
            TestProcessorFactory::record('F-1'),
            TestProcessorFactory::record('F-1', correction: 'S'),
            TestProcessorFactory::record('F-2', correction: 'S', previousRejection: 'X'),
            TestProcessorFactory::record('F-3', type: RecordType::Anulacion, previousRejection: 'N', withoutPreviousRecord: 'S'),
        ] as $record) {
            $result = $processor->process($record);
            self::assertSame([], $result['errors']);
            $records[] = $result['record'];
        }

        $envelope = (new SoapEnvelopeBuilder())->buildBatchEnvelope($records, []);

        self::assertSame([], $this->validate($envelope));
        self::assertStringContainsString('<Subsanacion>S</Subsanacion>' . "\n" . '  <RechazoPrevio>X</RechazoPrevio>', (string) $records[2]->xml);
        self::assertStringContainsString('<SinRegistroPrevio>S</SinRegistroPrevio>' . "\n" . '  <RechazoPrevio>N</RechazoPrevio>', (string) $records[3]->xml);
    }

    public function testMoreThanTheAeatMaximumOrMixedIssuersAreRefused(): void
    {
        $builder = new SoapEnvelopeBuilder();
        $record  = $this->chained(1)[0];

        try {
            $builder->buildBatchEnvelope(array_fill(0, 1001, $record), []);
            self::fail('1001 records were accepted.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('1 to 1000 records, 1001 given', $exception->getMessage());
        }

        $other = new BillingRecord(RecordType::Alta, '12345678Z', 'X-1', '09-07-2026', 'F1', '0', '0', '');
        $this->expectException(InvalidArgumentException::class);
        $builder->buildBatchEnvelope([$record, $other], []);
    }

    public function testEmptyBatchIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new SoapEnvelopeBuilder())->buildBatchEnvelope([], []);
    }

    /**
     * @return list<BillingRecord>
     */
    private function chained(int $count): array
    {
        $result = TestProcessorFactory::create(new InMemoryHashChainRepository())->processBatch(
            array_map(static fn (int $i): BillingRecord => TestProcessorFactory::record('F-' . $i), range(1, $count)),
        );
        self::assertSame([], $result['errors']);

        return $result['records'];
    }

    /**
     * @return list<string>
     */
    private function validate(string $envelope): array
    {
        $soap = new DOMDocument();
        $soap->loadXML($envelope);
        $body     = $soap->getElementsByTagNameNS(self::NS_LR, 'RegFactuSistemaFacturacion')->item(0);
        $document = new DOMDocument();
        self::assertNotNull($body);
        $document->appendChild($document->importNode($body, true));

        return (new XsdValidator(new IdentityTranslator(), true))->validate((string) $document->saveXML(), XsdValidator::SCHEMA_SOAP_SUBMISSION);
    }
}
