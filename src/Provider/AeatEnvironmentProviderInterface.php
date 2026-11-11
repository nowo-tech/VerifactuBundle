<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Provider;

use Nowo\VerifactuBundle\Model\AeatEnvironment;

/**
 * Supplies the AEAT environment at runtime, as an AeatEnvironment (only sandbox or production exist, so an unknown
 * stored value can never be routed anywhere: map it explicitly, e.g. `AeatEnvironment::fromValue($stored)` or
 * `$stored === 'test' ? AeatEnvironment::Sandbox : …`).
 *
 * The SOAP client, the QR Twig functions and the sandbox command ask it on every call, so a host can switch the
 * environment from its database without a cache clear. The default (ConfigAeatEnvironmentProvider) returns
 * `nowo_verifactu.aeat.environment`. The container-build safety check that refuses a production client with the
 * in-memory chain only sees the static configuration.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
interface AeatEnvironmentProviderInterface
{
    /**
     * Returns the environment of the current call.
     */
    public function getEnvironment(): AeatEnvironment;
}
