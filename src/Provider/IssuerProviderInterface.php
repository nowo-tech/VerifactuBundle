<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Provider;

use Nowo\VerifactuBundle\Model\IssuerIdentity;

/**
 * Supplies the issuer (NIF and name) at runtime.
 *
 * BillingRecordProcessor and the SOAP client call it on every record, never at container build, so a host can keep
 * the issuer in its database and change it without a cache clear. The default implementation
 * (ConfigIssuerProvider) returns `nowo_verifactu.issuer`.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
interface IssuerProviderInterface
{
    /**
     * Returns the current issuer.
     */
    public function getIssuer(): IssuerIdentity;
}
