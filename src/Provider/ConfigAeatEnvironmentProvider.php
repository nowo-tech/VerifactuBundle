<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Provider;

use Nowo\VerifactuBundle\Model\AeatEnvironment;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Default AEAT environment provider: the static `nowo_verifactu.aeat.environment` configuration.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[AsAlias(id: self::SERVICE_NAME, public: true)]
final class ConfigAeatEnvironmentProvider implements AeatEnvironmentProviderInterface
{
    public const SERVICE_NAME = 'nowo_verifactu.provider.config_aeat_environment';

    /**
     * Receives the configured environment.
     */
    public function __construct(
        private readonly string $environment = 'sandbox',
    ) {
    }

    /**
     * Returns the configured environment (the configuration only accepts sandbox / production).
     */
    public function getEnvironment(): AeatEnvironment
    {
        return AeatEnvironment::fromValue($this->environment);
    }
}
