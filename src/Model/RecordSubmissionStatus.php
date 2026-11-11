<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Model;

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
     * Maps the EstadoRegistroDuplicado of a duplicate (Correcta / AceptadaConErrores / Anulada): the record the AEAT
     * already holds. Correcta and AceptadaConErrores mean an earlier send of the same record was registered (a retry
     * after a lost response), so the record counts as accepted; Anulada is a rejection.
     */
    public static function fromDuplicate(string $estadoRegistroDuplicado): self
    {
        return match (trim($estadoRegistroDuplicado)) {
            'Correcta'           => self::Accepted,
            'AceptadaConErrores' => self::AcceptedWithErrors,
            default              => self::Rejected,
        };
    }

    /**
     * Whether the AEAT registered the record (Correcto or AceptadoConErrores).
     */
    public function isRegistered(): bool
    {
        return $this !== self::Rejected;
    }
}
