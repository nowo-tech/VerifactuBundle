<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Provider;

use Nowo\VerifactuBundle\Model\SoftwareIdentity;

/**
 * Supplies the SistemaInformatico identity (producer name and NIF, software name, IdSistemaInformatico, version,
 * NumeroInstalacion) at runtime.
 *
 * BillingRecordProcessor calls it on every record, never at container build. The default implementation
 * (ConfigSoftwareIdentityProvider) returns `nowo_verifactu.software` and `nowo_verifactu.installation`.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
interface SoftwareIdentityProviderInterface
{
    /**
     * Returns the current software identity.
     */
    public function getSoftwareIdentity(): SoftwareIdentity;
}
