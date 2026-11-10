<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Repository;

use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\StoredBillingRecord;

/**
 * Optional log of every chained billing record, per issuer and in chain order (record_store.*).
 *
 * BillingRecordProcessor appends each record inside the chain lock, right after the chain head moves, so the head and
 * the log never diverge. Implementations must not flush or commit a host EntityManager (architecture rule 6).
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
interface BillingRecordStoreInterface
{
    /**
     * Appends a chained record (hash, previous hash and XML set) at the end of its issuer log; returns its 1-based
     * position.
     */
    public function append(BillingRecord $record): int;

    /**
     * Returns the records of the issuer in chain order.
     *
     * @return iterable<StoredBillingRecord>
     */
    public function findByIssuer(string $issuerNif): iterable;

    /**
     * Records the outcome of the AEAT submission of the record identified by issuer NIF and hash.
     */
    public function markSubmitted(string $issuerNif, string $hash, bool $accepted, ?string $reference = null): void;
}
