<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Model;

/**
 * Order of a record list read through BillingRecordQueryInterface (1.10).
 *
 * Positions are the issuer's chain order (1 = first record). The issue-date orders break ties by position, in the same
 * direction.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
enum BillingRecordSort: string
{
    /** Chain order: oldest record first. */
    case PositionAsc = 'position_asc';

    /** Reverse chain order: newest record first (the default). */
    case PositionDesc = 'position_desc';

    /** Invoice issue date (FechaExpedicionFactura), oldest first. */
    case IssueDateAsc = 'issue_date_asc';

    /** Invoice issue date (FechaExpedicionFactura), newest first. */
    case IssueDateDesc = 'issue_date_desc';
}
