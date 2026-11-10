<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Model;

/**
 * The identification of another invoice (AEAT IDFacturaARType): the invoice a rectifying invoice corrects
 * (FacturasRectificadas) or a simplified invoice an F3 replaces (FacturasSustituidas).
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class RectifiedInvoice
{
    /**
     * Creates the reference: issuer NIF, series and number, issue date (dd-mm-yyyy).
     */
    public function __construct(
        public readonly string $issuerNif,
        public readonly string $invoiceSeriesNumber,
        public readonly string $issueDate,
    ) {
    }
}
