<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Provider;

use Nowo\VerifactuBundle\Model\IssuerIdentity;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Default issuer provider: the static `nowo_verifactu.issuer` configuration.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[AsAlias(id: self::SERVICE_NAME, public: true)]
final class ConfigIssuerProvider implements IssuerProviderInterface
{
    public const SERVICE_NAME = 'nowo_verifactu.provider.config_issuer';

    /**
     * Receives the `nowo_verifactu.issuer` configuration.
     *
     * @param array<string, mixed> $issuerConfig
     */
    public function __construct(
        private readonly array $issuerConfig = [],
    ) {
    }

    /**
     * Returns the configured issuer.
     */
    public function getIssuer(): IssuerIdentity
    {
        return new IssuerIdentity(
            (string) ($this->issuerConfig['nif'] ?? ''),
            (string) ($this->issuerConfig['name'] ?? ''),
        );
    }
}
