<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Repository;

use InvalidArgumentException;
use Nowo\VerifactuBundle\Model\BillingRecordCriteria;
use Nowo\VerifactuBundle\Model\BillingRecordView;

/**
 * Read port over the record log (1.10): what a host lists, counts or looks up, so it never queries the bundle's tables
 * or entities itself. Registered whenever a record store is (record_store.storage / record_store.service); replace it
 * with record_store.query.
 *
 * Every method is scoped to one issuer NIF (upper-cased and trimmed). A scope that is not a NIF-shaped key (for
 * example '') returns nothing. Results are read-only views (no entity, no XML) and never include records of another
 * issuer. Invoice numbers compare byte for byte, whatever the database collation.
 *
 * Read only: implementations never write, lock, flush or open a transaction.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
interface BillingRecordQueryInterface
{
    /** Largest page page() returns. */
    public const MAX_LIMIT = 500;

    /**
     * Returns one page of the issuer's records matching the criteria, in the criteria's order.
     *
     * @param int $offset records to skip (0 or more)
     * @param int $limit records to return (1 – MAX_LIMIT)
     *
     * @throws InvalidArgumentException on a negative offset or a limit outside 1 – MAX_LIMIT
     *
     * @return list<BillingRecordView>
     */
    public function page(BillingRecordCriteria $criteria, int $offset = 0, int $limit = 50): array;

    /**
     * Counts the issuer's records matching the criteria (the order is ignored).
     */
    public function count(BillingRecordCriteria $criteria): int;

    /**
     * Returns the record at a position of the issuer chain, or null.
     */
    public function findByPosition(string $issuerNif, int $position): ?BillingRecordView;

    /**
     * Returns the latest record (highest position) of an invoice of the issuer, or null. An invoice has several records
     * when it was cancelled (Anulacion) or corrected (Subsanacion); list them all with page() and
     * BillingRecordCriteria::$invoiceSeriesNumber.
     */
    public function findByInvoice(string $issuerNif, string $invoiceSeriesNumber): ?BillingRecordView;

    /**
     * Returns the latest record of each given invoice of the issuer, in the order of the given invoice numbers
     * (duplicates once); invoices without a record are absent. Map them by BillingRecordView::$invoiceSeriesNumber.
     * One call for a whole list (e.g. an export), not one query per invoice.
     *
     * @param list<string> $invoiceSeriesNumbers
     *
     * @return list<BillingRecordView>
     */
    public function findLatestByInvoices(string $issuerNif, array $invoiceSeriesNumbers): array;
}
