<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Provider;

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
        private readonly string $environment = self::SANDBOX,
    ) {
    }

    /**
     * Returns the configured environment.
     */
    public function getEnvironment(): string
    {
        return $this->environment === self::PRODUCTION ? self::PRODUCTION : self::SANDBOX;
    }
}
