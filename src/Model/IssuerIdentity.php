<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Model;

/**
 * The obligado a expedir (issuer): NIF and name, as resolved for one process() call.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class IssuerIdentity
{
    /**
     * Creates the issuer identity.
     */
    public function __construct(
        public readonly string $nif,
        public readonly string $name,
    ) {
    }

    /**
     * Returns the `nowo_verifactu.issuer` configuration shape (nif, name).
     *
     * @return array{nif: string, name: string}
     */
    public function toArray(): array
    {
        return ['nif' => $this->nif, 'name' => $this->name];
    }
}
