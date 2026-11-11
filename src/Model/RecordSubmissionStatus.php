<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Model;

use function trim;

/**
 * The AEAT outcome of one billing record (EstadoRegistro, RespuestaSuministro.xsd `EstadoRegistroType`), as stored in
 * the record log. AEAT Servicios web v1.0.3 p. 11: Correcto (aceptado), AceptadoConErrores (aceptado con errores; it is
 * registered), Incorrecto (rechazado; it is not registered and must be corrected and sent again).
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
enum RecordSubmissionStatus: string
{
    case Accepted           = 'accepted';
    case AcceptedWithErrors = 'accepted_errors';
    case Rejected           = 'rejected';

    /**
     * The AEAT answered "duplicate" (RegistroDuplicado) for a record sent before, and nothing confirmed that the record
     * the AEAT holds is this one (same Huella). Never reported as registered; see BillingRecordBatchSubmitter.
     */
    case Unconfirmed = 'unconfirmed';

    /**
     * Maps an AEAT EstadoRegistro (Correcto / AceptadoConErrores / Incorrecto); null for anything else.
     */
    public static function fromAeat(string $estadoRegistro): ?self
    {
        return match (trim($estadoRegistro)) {
            'Correcto'           => self::Accepted,
            'AceptadoConErrores' => self::AcceptedWithErrors,
            'Incorrecto'         => self::Rejected,
            default              => null,
        };
    }

    /**
     * Maps the EstadoRegistroDuplicado of a duplicate (Correcta / AceptadaConErrores / Anulada), i.e. the state of the
     * record the AEAT already holds, for a record CONFIRMED to be that very record (same Huella): null when the state
     * says nothing about it.
     *
     * - Alta: Correcta → accepted, AceptadaConErrores → accepted with errors, Anulada → accepted (it was registered and
     *   annulled later; the duplicate state is kept with the record).
     * - Anulacion: Anulada → accepted (the anulación is registered); anything else → null.
     */
    public static function fromDuplicate(string $estadoRegistroDuplicado, RecordType $operation = RecordType::Alta): ?self
    {
        $state = trim($estadoRegistroDuplicado);
        if ($operation === RecordType::Anulacion) {
            return $state === 'Anulada' ? self::Accepted : null;
        }

        return match ($state) {
            'Correcta', 'Anulada' => self::Accepted,
            'AceptadaConErrores'  => self::AcceptedWithErrors,
            default               => null,
        };
    }

    /**
     * The record log status for this AEAT outcome.
     */
    public function storeStatus(): string
    {
        return match ($this) {
            self::Accepted           => StoredBillingRecord::STATUS_ACCEPTED,
            self::AcceptedWithErrors => StoredBillingRecord::STATUS_ACCEPTED_WITH_ERRORS,
            self::Rejected           => StoredBillingRecord::STATUS_REJECTED,
            self::Unconfirmed        => StoredBillingRecord::STATUS_UNCONFIRMED,
        };
    }

    /**
     * Whether the AEAT registered the record (Correcto or AceptadoConErrores). An unconfirmed duplicate is not.
     */
    public function isRegistered(): bool
    {
        return $this === self::Accepted || $this === self::AcceptedWithErrors;
    }
}
