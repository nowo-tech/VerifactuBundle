<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Repository;

use InvalidArgumentException;

use function sprintf;
use function trim;

/**
 * Shared argument checks of the BillingRecordQueryInterface implementations.
 *
 * @internal
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class BillingRecordQueryPaging
{
    /**
     * @throws InvalidArgumentException on a negative offset or a limit outside 1 – MAX_LIMIT
     */
    public static function assertPage(int $offset, int $limit): void
    {
        if ($offset < 0) {
            throw new InvalidArgumentException(sprintf('The offset must be 0 or more, got %d.', $offset));
        }
        if ($limit < 1 || $limit > BillingRecordQueryInterface::MAX_LIMIT) {
            throw new InvalidArgumentException(sprintf('The limit must be between 1 and %d, got %d.', BillingRecordQueryInterface::MAX_LIMIT, $limit));
        }
    }

    /**
     * Distinct invoice numbers, in the given order (trimmed like the hash and the store do, otherwise exact strings).
     *
     * @param list<string> $invoiceSeriesNumbers
     *
     * @return list<string>
     */
    public static function distinctInvoices(array $invoiceSeriesNumbers): array
    {
        $seen     = [];
        $distinct = [];
        foreach ($invoiceSeriesNumbers as $number) {
            $number = trim($number);
            if (!isset($seen[$number])) {
                $seen[$number] = true;
                $distinct[]    = $number;
            }
        }

        return $distinct;
    }
}
