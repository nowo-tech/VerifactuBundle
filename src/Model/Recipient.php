<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Model;

/**
 * One invoice recipient (Destinatarios/IDDestinatario, AEAT PersonaFisicaJuridicaType): a name and either a Spanish NIF
 * or a foreign identifier (IDOtro).
 *
 * IDOtro/IDType (AEAT list L7): 02 NIF-IVA, 03 passport, 04 official ID of the country of residence, 05 residence
 * certificate, 06 other supporting document, 07 not registered ("no censado"). CodigoPais is ISO 3166-1 alpha-2; it may
 * be omitted with IDType 02, and "ES" is only accepted with IDType 07.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class Recipient
{
    public const ID_TYPE_VAT_NUMBER     = '02';
    public const ID_TYPE_PASSPORT       = '03';
    public const ID_TYPE_OFFICIAL_ID    = '04';
    public const ID_TYPE_RESIDENCE      = '05';
    public const ID_TYPE_OTHER_DOCUMENT = '06';
    public const ID_TYPE_NOT_REGISTERED = '07';

    /**
     * Use withNif() or withForeignId().
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $nif = null,
        public readonly ?string $foreignIdType = null,
        public readonly ?string $foreignId = null,
        public readonly ?string $countryCode = null,
    ) {
    }

    /**
     * A recipient identified by a Spanish NIF.
     */
    public static function withNif(string $name, string $nif): self
    {
        return new self($name, $nif);
    }

    /**
     * A recipient identified by IDOtro (foreign VAT number, passport, …).
     */
    public static function withForeignId(string $name, string $idType, string $id, ?string $countryCode = null): self
    {
        return new self($name, null, $idType, $id, $countryCode);
    }
}
