<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Provider;

use Nowo\VerifactuBundle\Model\SoftwareIdentity;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Default software identity provider: the static `nowo_verifactu.software` and `nowo_verifactu.installation`
 * configuration.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[AsAlias(id: self::SERVICE_NAME, public: true)]
final class ConfigSoftwareIdentityProvider implements SoftwareIdentityProviderInterface
{
    public const SERVICE_NAME = 'nowo_verifactu.provider.config_software_identity';

    /**
     * Receives the `nowo_verifactu.software` and `nowo_verifactu.installation` configuration.
     *
     * @param array<string, mixed> $softwareConfig
     * @param array<string, mixed> $installationConfig
     */
    public function __construct(
        private readonly array $softwareConfig = [],
        private readonly array $installationConfig = [],
    ) {
    }

    /**
     * Returns the configured software identity.
     */
    public function getSoftwareIdentity(): SoftwareIdentity
    {
        return new SoftwareIdentity(
            (string) ($this->softwareConfig['manufacturer_name'] ?? ''),
            (string) ($this->softwareConfig['manufacturer_nif'] ?? ''),
            (string) ($this->softwareConfig['name'] ?? ''),
            (string) ($this->softwareConfig['id'] ?? ''),
            (string) ($this->softwareConfig['version'] ?? ''),
            (string) ($this->installationConfig['number'] ?? ''),
            (bool) ($this->softwareConfig['solo_verifactu'] ?? true),
        );
    }
}
