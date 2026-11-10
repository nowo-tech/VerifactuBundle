<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Provider;

/**
 * Supplies the AEAT environment ("sandbox" or "production") at runtime.
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
    public const SANDBOX    = 'sandbox';
    public const PRODUCTION = 'production';

    /**
     * Returns "sandbox" or "production"; any other value is treated as "sandbox".
     */
    public function getEnvironment(): string;
}
