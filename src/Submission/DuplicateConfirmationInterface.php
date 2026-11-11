<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Submission;

use Nowo\VerifactuBundle\Model\AeatRecordResponse;
use Nowo\VerifactuBundle\Model\BillingRecord;

/**
 * Confirms that an AEAT "duplicate" answer (RegistroDuplicado) refers to this very record, i.e. that an earlier send of
 * it was registered and only its answer was lost (1.7, `submission.duplicate_confirmation`).
 *
 * The duplicate answer carries no Huella, so it cannot tell by itself whether the record the AEAT holds for the invoice
 * id is ours or another one that used the same id (AEAT FAQ v1.3 §6). Typical implementation: query the AEAT
 * (ConsultaFactuSistemaFacturacion, RespuestaConsultaLR.xsd returns the Huella of the registered record) and compare it
 * with $record->hash. Without an implementation, a duplicate for a record sent before is stored as `unconfirmed` and
 * never reported as accepted.
 *
 * Stateless (worker mode): resolve everything per call.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
interface DuplicateConfirmationInterface
{
    /**
     * True when the record the AEAT holds is this record (same Huella), false when it is another one, null when it
     * cannot be told now (the record is then stored as `unconfirmed`).
     */
    public function isSameRecord(BillingRecord $record, AeatRecordResponse $duplicate): ?bool;
}
