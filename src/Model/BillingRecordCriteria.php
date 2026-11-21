<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Model;

use DateTimeInterface;
use InvalidArgumentException;

use function in_array;
use function sprintf;

/**
 * What a record list reads through BillingRecordQueryInterface (1.10): one issuer (the mandatory scope), optional
 * filters and the order.
 *
 * The scope is the issuer NIF, upper-cased and trimmed like the record store keys. A scope that is not a NIF-shaped key
 * (1 – 20 letters and digits, e.g. an empty string) matches nothing: the list is empty and the count is 0, never the
 * records of every issuer. Filters combine with AND; an empty filter list means "any".
 *
 * Issue dates compare by calendar day (the `Y-m-d` of the given value, in its own time zone), both ends inclusive.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class BillingRecordCriteria
{
    /** Every submission status a stored record can have. */
    public const STATUSES = [
        StoredBillingRecord::STATUS_PENDING,
        StoredBillingRecord::STATUS_SENDING,
        StoredBillingRecord::STATUS_HELD,
        StoredBillingRecord::STATUS_CONFIRMING,
        StoredBillingRecord::STATUS_ACCEPTED,
        StoredBillingRecord::STATUS_ACCEPTED_WITH_ERRORS,
        StoredBillingRecord::STATUS_REJECTED,
        StoredBillingRecord::STATUS_UNCONFIRMED,
        StoredBillingRecord::STATUS_FAILED,
    ];

    /** Upper-cased, trimmed issuer NIF ('' when the given scope is not a NIF-shaped key). */
    public readonly string $issuerNif;

    /** @var list<string> submission statuses (STATUSES); empty = any */
    public readonly array $statuses;

    /** @var list<RecordType> record types (Alta, Anulacion); empty = any */
    public readonly array $recordTypes;

    /** @var list<string> invoice types (TipoFactura: F1, F2, R1 …, upper-cased); empty = any */
    public readonly array $invoiceTypes;

    /** Exact invoice series and number (NumSerieFactura, trimmed like the hash and the store do, then compared byte for byte); null = any. */
    public readonly ?string $invoiceSeriesNumber;

    /** First issue day included (`Ymd`), or null. */
    public readonly ?string $issuedFrom;

    /** Last issue day included (`Ymd`), or null. */
    public readonly ?string $issuedTo;

    /**
     * Creates the criteria.
     *
     * @param list<string> $statuses submission statuses (STATUSES)
     * @param list<RecordType|string> $recordTypes record types (Alta, Anulacion)
     * @param list<string> $invoiceTypes invoice types (F1, F2, F3, R1 – R5)
     *
     * @throws InvalidArgumentException on an unknown status or record type
     */
    public function __construct(
        string $issuerNif,
        array $statuses = [],
        array $recordTypes = [],
        array $invoiceTypes = [],
        ?string $invoiceSeriesNumber = null,
        ?DateTimeInterface $issuedFrom = null,
        ?DateTimeInterface $issuedTo = null,
        public readonly BillingRecordSort $sort = BillingRecordSort::PositionDesc,
    ) {
        $this->issuerNif = self::normalizeIssuer($issuerNif);

        $validStatuses = [];
        foreach ($statuses as $status) {
            if (!in_array($status, self::STATUSES, true)) {
                throw new InvalidArgumentException(sprintf('Unknown submission status "%s"; expected one of: %s.', $status, implode(', ', self::STATUSES)));
            }
            $validStatuses[] = $status;
        }
        $this->statuses = array_values(array_unique($validStatuses));

        $types = [];
        foreach ($recordTypes as $recordType) {
            $type = $recordType instanceof RecordType ? $recordType : RecordType::tryFrom($recordType);
            if ($type === null) {
                throw new InvalidArgumentException(sprintf('Unknown record type "%s"; expected Alta or Anulacion.', (string) $recordType));
            }
            $types[$type->value] = $type;
        }
        $this->recordTypes = array_values($types);

        $this->invoiceTypes        = array_values(array_unique(array_map(static fn (string $type): string => strtoupper(trim($type)), $invoiceTypes)));
        $this->invoiceSeriesNumber = $invoiceSeriesNumber === null ? null : trim($invoiceSeriesNumber);
        $this->issuedFrom          = $issuedFrom?->format('Ymd');
        $this->issuedTo            = $issuedTo?->format('Ymd');
    }

    /**
     * Upper-cases and trims an issuer NIF; returns '' when it is not 1 – 20 letters and digits (such a scope matches
     * nothing).
     */
    public static function normalizeIssuer(string $issuerNif): string
    {
        $key = strtoupper(trim($issuerNif));

        return preg_match('/^[A-Z0-9]{1,20}$/D', $key) === 1 ? $key : '';
    }

    /**
     * Whether the scope matches no issuer (the list is empty, the count 0).
     */
    public function isEmptyScope(): bool
    {
        return $this->issuerNif === '';
    }

    /**
     * Converts a stored issue date (dd-mm-yyyy, FechaExpedicionFactura) into its `Ymd` key; '' when it has another form.
     */
    public static function issueDateKey(string $issueDate): string
    {
        return preg_match('/^(\d{2})-(\d{2})-(\d{4})$/D', $issueDate, $parts) === 1 ? $parts[3] . $parts[2] . $parts[1] : '';
    }
}
