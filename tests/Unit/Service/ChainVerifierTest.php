<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Service;

use Nowo\VerifactuBundle\Generator\HashChainGenerator;
use Nowo\VerifactuBundle\Model\AeatEnvironment;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\HashChainState;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Model\StoredBillingRecord;
use Nowo\VerifactuBundle\Repository\BillingRecordStoreInterface;
use Nowo\VerifactuBundle\Repository\InMemoryHashChainRepository;
use Nowo\VerifactuBundle\Service\ChainVerifier;
use PHPUnit\Framework\TestCase;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class ChainVerifierTest extends TestCase
{
    private HashChainGenerator $generator;

    private InMemoryHashChainRepository $head;

    protected function setUp(): void
    {
        $this->generator = new HashChainGenerator();
        $this->head      = new InMemoryHashChainRepository();
    }

    public function testValidChainOfAltaAndAnulacion(): void
    {
        $first  = $this->chained(RecordType::Alta, 'F-1', '21.00', '121.30', '');
        $second = $this->chained(RecordType::Anulacion, 'F-1', '0.00', '0.00', (string) $first->hash);
        $this->head->storeLastState(new HashChainState('89890001K', 'F-1', '09-07-2026', (string) $second->hash));

        $result = $this->verifier([new StoredBillingRecord(1, $first), new StoredBillingRecord(2, $second)])->verify(' 89890001k ');

        self::assertTrue($result->isValid());
        self::assertSame(2, $result->checkedRecords);
        self::assertSame('89890001K', $result->issuerNif);
    }

    public function testSandboxRecordsBeforeAResetAreSkippedAndTheProductionChainIsVerified(): void
    {
        $test       = $this->chained(RecordType::Alta, 'T-1', '21.00', '121.00', '', AeatEnvironment::Sandbox);
        $production = $this->chained(RecordType::Alta, 'F-1', '21.00', '121.00', '', AeatEnvironment::Production);
        $this->head->storeLastState(new HashChainState('89890001K', 'F-1', '09-07-2026', (string) $production->hash, AeatEnvironment::Production));
        $records = [new StoredBillingRecord(1, $test), new StoredBillingRecord(2, $production)];

        $result = $this->verifier($records)->verify('89890001K');
        self::assertTrue($result->isValid(), print_r($result->problems, true));
        self::assertSame(1, $result->checkedRecords);
        self::assertSame(AeatEnvironment::Production, $result->environment);

        // The sandbox chain alone is still consistent, but its head is no longer the current one.
        $sandbox = $this->verifier($records)->verify('89890001K', null, 'sandbox');
        self::assertSame(1, $sandbox->checkedRecords);
        self::assertFalse($sandbox->isValid());
    }

    public function testARecordOfAnotherEnvironmentInsideTheChainIsReported(): void
    {
        $first = $this->chained(RecordType::Alta, 'F-1', '21.00', '121.00', '', AeatEnvironment::Production);
        $stray = $this->chained(RecordType::Alta, 'T-1', '21.00', '121.00', (string) $first->hash, AeatEnvironment::Sandbox);
        $this->head->storeLastState(new HashChainState('89890001K', 'F-1', '09-07-2026', (string) $first->hash, AeatEnvironment::Production));

        $result = $this->verifier([new StoredBillingRecord(1, $first), new StoredBillingRecord(2, $stray)])->verify('89890001K');

        self::assertFalse($result->isValid());
        self::assertStringContainsString('"sandbox" environment is inside the "production" chain', $result->problems[0]['problem']);
    }

    public function testEmptyChainIsValid(): void
    {
        self::assertTrue($this->verifier([])->verify('89890001K')->isValid());
    }

    public function testReportsEveryKindOfProblem(): void
    {
        $first = $this->chained(RecordType::Alta, 'F-1', '21.00', '121.00', '');
        // Wrong position, wrong previous hash, an amount in the XML that differs and a Huella that differs
        $second = $this->chained(RecordType::Alta, 'F-2', '1.00', '2.00', str_repeat('9', 64));
        $second = $second->withXml(str_replace(['<ImporteTotal>2.00<', '<Huella>' . $second->hash . '<'], ['<ImporteTotal>2.0<', '<Huella>X<'], (string) $second->xml));
        // A hash that does not match the fields, and no XML at all
        $third = new BillingRecord(RecordType::Alta, '89890001K', 'F-3', '09-07-2026', 'F1', '1.00', '2.00', '2026-07-09T16:00:00+02:00', previousHash: $second->hash, hash: str_repeat('A', 64));

        $result = $this->verifier([
            new StoredBillingRecord(1, $first),
            new StoredBillingRecord(3, $second),
            new StoredBillingRecord(4, $third),
        ])->verify('89890001K');

        $problems = array_map(static fn (array $p): string => $p['position'] . ':' . $p['problem'], $result->problems);
        self::assertSame([
            '3:Expected position 2, found 3.',
            '3:The previous hash does not match the hash of the record before it.',
            '3:The XML Huella differs from the hashed value.',
            '3:The XML ImporteTotal differs from the hashed value.',
            '4:The hash does not match the stored record fields.',
            '4:The XML Huella differs from the hashed value.',
            '4:The XML CuotaTotal differs from the hashed value.',
            '4:The XML ImporteTotal differs from the hashed value.',
            '4:The chain head does not point at the last stored record.',
        ], $problems);
        self::assertFalse($result->isValid());
    }

    public function testALogStartedOnAnOlderChainContinuesFromItsHead(): void
    {
        $oldHead = str_repeat('D', 64);
        $first   = $this->chained(RecordType::Alta, 'F-10', '21.00', '121.00', $oldHead);
        $this->head->storeLastState(new HashChainState('89890001K', 'F-10', '09-07-2026', (string) $first->hash));
        $verifier = $this->verifier([new StoredBillingRecord(1, $first)]);

        $result = $verifier->verify('89890001K');
        self::assertTrue($result->isValid());
        self::assertSame($oldHead, $result->continuesFrom);

        self::assertTrue($verifier->verify('89890001K', strtolower($oldHead))->isValid());
        self::assertNull($verifier->verify('89890001K', $oldHead)->continuesFrom);

        $wrongAnchor = $verifier->verify('89890001K', str_repeat('E', 64));
        self::assertSame('The previous hash does not match the hash of the record before it.', $wrongAnchor->problems[0]['problem']);
    }

    public function testChainHeadAheadOfTheStoreIsReported(): void
    {
        $first = $this->chained(RecordType::Alta, 'F-1', '21.00', '121.00', '');
        $this->head->storeLastState(new HashChainState('89890001K', 'F-2', '09-07-2026', str_repeat('C', 64)));

        $result = $this->verifier([new StoredBillingRecord(1, $first)])->verify('89890001K');

        self::assertSame('The chain head does not point at the last stored record.', $result->problems[0]['problem']);
    }

    private function chained(RecordType $type, string $number, string $tax, string $total, string $previousHash, ?AeatEnvironment $environment = null): BillingRecord
    {
        $record = new BillingRecord($type, '89890001K', $number, '09-07-2026', 'F1', $tax, $total, '2026-07-09T16:00:00+02:00', previousHash: $previousHash, environment: $environment);
        $hash   = $this->generator->computeHash($record);
        $xml    = '<Registro><Encadenamiento><RegistroAnterior><Huella>' . $previousHash . '</Huella></RegistroAnterior></Encadenamiento>'
            . ($type === RecordType::Alta ? '<CuotaTotal>' . $tax . '</CuotaTotal><ImporteTotal>' . $total . '</ImporteTotal>' : '')
            . '<Huella>' . $hash . '</Huella></Registro>';

        return $record->withHash($hash)->withXml($xml);
    }

    /**
     * @param list<StoredBillingRecord> $records
     */
    private function verifier(array $records): ChainVerifier
    {
        $store = $this->createMock(BillingRecordStoreInterface::class);
        $store->method('findByIssuer')->willReturn($records);

        return new ChainVerifier($store, $this->head, $this->generator);
    }
}
