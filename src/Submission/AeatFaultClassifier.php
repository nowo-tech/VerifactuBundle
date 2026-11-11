<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Submission;

use Nowo\VerifactuBundle\Model\AeatBatchResponse;

use function in_array;

/**
 * Tells who a SOAP `Client` fault of the AEAT is about, from its Codigo[NNNN] (1.7, batch submission). A Client fault
 * rejects the whole message: either the message structure (one record can break the XML schema) or the header
 * ("errores sintácticos en la cabecera", Servicios web v1.0.3 §5.1 and p. 28, e.g. Codigo[4104]).
 *
 * - ISSUER: the header, the issuer, the representative, the certificate or the authorisation; no split of the batch can
 *   fix it. The submitter pauses the issuer at once and fails no record.
 * - RECORD: the content of the message (schema, parsing, a field format); counted against the records, the batch is
 *   split and the record at fault is marked failed without probing.
 * - UNKNOWN: any other code, or none: the batch is split and a record refused on its own is held while other records
 *   are sent alone as probes (BillingRecordBatchSubmitter).
 *
 * The default lists follow the AEAT list of message-level errors of the Veri*Factu services (codes 41xx, AEAT
 * `errores.properties`, checked 2026-11-11). They are
 * configurable (`submission.issuer_fault_codes`, `submission.record_fault_codes`): a code the AEAT adds or redefines
 * falls back to UNKNOWN, which is safe (probing).
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class AeatFaultClassifier
{
    public const ISSUER  = 'issuer';
    public const RECORD  = 'record';
    public const UNKNOWN = 'unknown';

    /**
     * Header, issuer, representative, certificate, authorisation and service errors (AEAT `errores.properties` of the
     * Veri*Factu web services, tikeV1.0): 4104 / 4105 NIF of ObligadoEmision / Representante not identified, 4107 NIF not
     * in the census, 4108 technical error getting the certificate, 4110 technical error checking the authorisations,
     * 4111 technical error creating the procedure, 4112 the certificate holder is not the issuer, a social
     * collaborator, an attorney or a successor, 4115 – 4117 NIF in the header wrong or badly formatted, 4118 address
     * does not match the input file, 4120 – 4127 and 4129 – 4131 and 4133 header fields (FechaFinVeriFactu, Incidencia,
     * RefRequerimiento, Representante, FinRequerimiento...), 4128 technical error (table manager), 4132 / 4140
     * authorisation for queries, 4134 service not active, 4135 GET not allowed, 4139 service not enabled in
     * production, 4141 access to VERIFACTU suspended.
     */
    public const DEFAULT_ISSUER_CODES = [
        4104, 4105, 4107, 4108, 4110, 4111, 4112, 4115, 4116, 4117, 4118, 4120, 4121, 4122, 4123, 4124, 4125, 4126,
        4127, 4128, 4129, 4130, 4131, 4132, 4133, 4134, 4135, 4139, 4140, 4141,
    ];

    /**
     * Message content errors: 4102 the XML does not follow the schema (a mandatory field is missing), 4106 wrong date
     * format, 4119 characters not encoded in UTF-8, 4136 / 4137 RegistroAlta / RegistroAnulacion node missing or
     * wrongly placed. Not listed on purpose (UNKNOWN, probed): 4103 unexpected parsing error, 4109 NIF format (header
     * or record), 4113 / 4114 too many records, 4138 empty request or wrong encoding.
     */
    public const DEFAULT_RECORD_CODES = [4102, 4106, 4119, 4136, 4137];

    /**
     * @param list<int> $issuerCodes
     * @param list<int> $recordCodes
     */
    public function __construct(
        private readonly array $issuerCodes = self::DEFAULT_ISSUER_CODES,
        private readonly array $recordCodes = self::DEFAULT_RECORD_CODES,
    ) {
    }

    /**
     * ISSUER, RECORD or UNKNOWN for a record refusal (AeatBatchResponse::isRecordRefusal()); UNKNOWN otherwise.
     */
    public function classify(AeatBatchResponse $response): string
    {
        $code = $response->faultErrorCode;
        if (!$response->isRecordRefusal() || $code === null) {
            return self::UNKNOWN;
        }

        return match (true) {
            in_array($code, $this->issuerCodes, true) => self::ISSUER,
            in_array($code, $this->recordCodes, true) => self::RECORD,
            default                                   => self::UNKNOWN,
        };
    }
}
