<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Service;

use Nowo\VerifactuBundle\Generator\HashChainGenerator;
use Nowo\VerifactuBundle\Model\Amount;
use Nowo\VerifactuBundle\Model\ChainVerificationResult;
use Nowo\VerifactuBundle\Model\HashChainState;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Repository\BillingRecordStoreInterface;
use Nowo\VerifactuBundle\Repository\HashChainRepositoryInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use function count;
use function sprintf;

/**
 * Walks the stored records of an issuer and checks the whole chain.
 *
 * For every record: the positions are consecutive, the fingerprint recomputes from the stored fields (the AEAT
 * amount tolerance applies, see HashChainGenerator::verifyHash()), the previous hash is the hash of the record before
 * ('' for the first), and the stored XML carries the same Huella and, for an alta, the same CuotaTotal / ImporteTotal
 * strings that were hashed. Finally the chain head of the repository must point at the last stored record.
 *
 * A log enabled on an issuer that already had a chain (records chained by 1.0 – 1.4 or before the store was turned
 * on) starts with a record whose previous hash is the old head, not ''. That record is accepted as the start of the
 * log and its previous hash is returned as `continuesFrom`; pass `$anchor` (the head before the log started) to
 * require that exact value.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[AsAlias(id: self::SERVICE_NAME, public: true)]
final class ChainVerifier
{
    public const SERVICE_NAME = 'nowo_verifactu.service.chain_verifier';

    /**
     * Receives the record store, the chain head repository and the hash generator.
     */
    public function __construct(
        private readonly BillingRecordStoreInterface $recordStore,
        private readonly HashChainRepositoryInterface $hashChainRepository,
        private readonly HashChainGenerator $hashChainGenerator,
    ) {
    }

    /**
     * Verifies the stored chain of the issuer.
     */
    public function verify(string $issuerNif, ?string $anchor = null): ChainVerificationResult
    {
        $problems      = [];
        $previousHash  = strtoupper(trim($anchor ?? ''));
        $expected      = 1;
        $checked       = 0;
        $continuesFrom = null;

        foreach ($this->recordStore->findByIssuer($issuerNif) as $stored) {
            ++$checked;
            $record = $stored->record;
            $hash   = strtoupper((string) $record->hash);
            $report = static function (string $problem) use (&$problems, $stored, $record): void {
                $problems[] = ['position' => $stored->position, 'invoiceSeriesNumber' => $record->invoiceSeriesNumber, 'problem' => $problem];
            };

            if ($stored->position !== $expected) {
                $report(sprintf('Expected position %d, found %d.', $expected, $stored->position));
            }
            if (!$this->hashChainGenerator->verifyHash($record, $hash)) {
                $report('The hash does not match the stored record fields.');
            }
            if ($checked === 1 && $anchor === null && ($record->previousHash ?? '') !== '') {
                // The log starts in the middle of an older chain: its first record continues from a head outside it.
                $previousHash  = strtoupper((string) $record->previousHash);
                $continuesFrom = $previousHash;
            }
            if (strtoupper($record->previousHash ?? '') !== $previousHash) {
                $report('The previous hash does not match the hash of the record before it.');
            }
            foreach ($this->xmlMismatches($record->xml ?? '', $hash, $record->recordType === RecordType::Alta ? [
                'CuotaTotal'   => $record->totalTaxAmount,
                'ImporteTotal' => $record->totalAmount,
            ] : []) as $element) {
                $report(sprintf('The XML %s differs from the hashed value.', $element));
            }

            $previousHash = $hash;
            $expected     = $stored->position + 1;
        }

        $head = $this->hashChainRepository->getLastState($issuerNif);
        if ($checked > 0 && (!$head instanceof HashChainState || strtoupper($head->hash) !== $previousHash)) {
            $problems[] = ['position' => $expected - 1, 'invoiceSeriesNumber' => '', 'problem' => 'The chain head does not point at the last stored record.'];
        }

        return new ChainVerificationResult(strtoupper(trim($issuerNif)), $checked, $problems, $continuesFrom);
    }

    /**
     * @param array<string, string> $amounts element name => hashed amount
     *
     * @return list<string> the element names whose XML text differs
     */
    private function xmlMismatches(string $xml, string $hash, array $amounts): array
    {
        $mismatches = [];
        if ($this->elementText($xml, 'Huella', true) !== $hash) {
            $mismatches[] = 'Huella';
        }
        foreach ($amounts as $element => $amount) {
            if ($this->elementText($xml, $element, false) !== Amount::canonical($amount)) {
                $mismatches[] = $element;
            }
        }

        return $mismatches;
    }

    /**
     * Returns the text of the record's own element (the last Huella is the record's: RegistroAnterior comes first).
     */
    private function elementText(string $xml, string $element, bool $last): ?string
    {
        if (preg_match_all('/<(?:[\w.-]+:)?' . $element . '>([^<]*)<\/(?:[\w.-]+:)?' . $element . '>/', $xml, $matches) < 1) {
            return null;
        }

        $values = $matches[1];

        return trim($last ? $values[count($values) - 1] : $values[0]);
    }
}
