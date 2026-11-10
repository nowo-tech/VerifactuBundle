<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Model;

/**
 * The SistemaInformatico block: the producer of the billing software and the software installation.
 *
 * The producer (NombreRazon / NIF) is the company that makes the software, not the issuer of the invoices.
 * IdSistemaInformatico is up to two characters and NumeroInstalacion up to 100 (AEAT record design).
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class SoftwareIdentity
{
    /**
     * Creates the software identity.
     */
    public function __construct(
        public readonly string $producerName,
        public readonly string $producerNif,
        public readonly string $name,
        public readonly string $id,
        public readonly string $version,
        public readonly string $installationNumber,
        public readonly bool $soloVerifactu = true,
    ) {
    }

    /**
     * Returns the `nowo_verifactu.software` configuration shape.
     *
     * @return array{id: string, name: string, version: string, manufacturer_nif: string, manufacturer_name: string, solo_verifactu: bool}
     */
    public function toSoftwareConfig(): array
    {
        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'version'           => $this->version,
            'manufacturer_nif'  => $this->producerNif,
            'manufacturer_name' => $this->producerName,
            'solo_verifactu'    => $this->soloVerifactu,
        ];
    }

    /**
     * Returns the `nowo_verifactu.installation` configuration shape.
     *
     * @return array{number: string}
     */
    public function toInstallationConfig(): array
    {
        return ['number' => $this->installationNumber];
    }
}
