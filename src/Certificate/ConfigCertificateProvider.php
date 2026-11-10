<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Certificate;

use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Default certificate provider: the static `nowo_verifactu.aeat.certificate_path` / `certificate_password`, for every
 * issuer.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[AsAlias(id: self::SERVICE_NAME, public: true)]
final class ConfigCertificateProvider implements CertificateProviderInterface
{
    public const SERVICE_NAME = 'nowo_verifactu.certificate.config_provider';

    /**
     * Receives the configured certificate path and password.
     */
    public function __construct(
        private readonly ?string $certificatePath = null,
        #[SensitiveParameter]
        private readonly ?string $certificatePassword = null,
    ) {
    }

    /**
     * Returns the configured certificate, or null without a certificate path.
     */
    public function getCertificate(string $issuerNif): ?Certificate
    {
        return $this->certificatePath === null || $this->certificatePath === ''
            ? null
            : Certificate::fromPath($this->certificatePath, $this->certificatePassword);
    }
}
