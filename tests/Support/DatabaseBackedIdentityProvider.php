<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Support;

use Nowo\VerifactuBundle\Model\IssuerIdentity;
use Nowo\VerifactuBundle\Model\SoftwareIdentity;
use Nowo\VerifactuBundle\Provider\IssuerProviderInterface;
use Nowo\VerifactuBundle\Provider\SoftwareIdentityProviderInterface;

/**
 * Stands in for a host that keeps the issuer and the software identity in its database (e.g. an admin form): the
 * public properties are "the rows", edited between two requests of the same kernel.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class DatabaseBackedIdentityProvider implements IssuerProviderInterface, SoftwareIdentityProviderInterface
{
    public IssuerIdentity $issuer;

    public SoftwareIdentity $software;

    public function __construct()
    {
        $this->issuer   = new IssuerIdentity('89890001K', 'Doctrine Test Issuer');
        $this->software = new SoftwareIdentity('Nowo.tech', '89890001K', 'VerifactuBundle', '01', '1.0.0', '001');
    }

    public function getIssuer(): IssuerIdentity
    {
        return $this->issuer;
    }

    public function getSoftwareIdentity(): SoftwareIdentity
    {
        return $this->software;
    }
}
