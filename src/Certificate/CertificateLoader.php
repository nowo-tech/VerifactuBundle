<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Certificate;

use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use function in_array;
use function sprintf;

use const PATHINFO_EXTENSION;

/**
 * Loads PKCS#12 and PEM certificates for AEAT mTLS and XAdES signing.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[AsAlias(id: self::SERVICE_NAME, public: true)]
final class CertificateLoader
{
    public const SERVICE_NAME = 'nowo_verifactu.certificate.loader';

    /**
     * @return array{private_key: string, certificate: string, path: string, password: string|null}
     */
    public function load(string $path, ?string $password = null): array
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException(sprintf('Certificate file not found: %s', $path));
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, ['p12', 'pfx'], true)) {
            return $this->loadPkcs12($path, $password);
        }

        return $this->loadPem($path, $password);
    }

    /**
     * @return array{private_key: string, certificate: string, path: string, password: string|null}
     */
    private function loadPkcs12(string $path, ?string $password): array
    {
        $contents = file_get_contents($path);
        // @codeCoverageIgnoreStart
        if ($contents === false) {
            throw new InvalidArgumentException(sprintf('Unable to read certificate: %s', $path));
        }
        // @codeCoverageIgnoreEnd

        $certs = [];
        if (!openssl_pkcs12_read($contents, $certs, $password ?? '')) {
            throw new InvalidArgumentException('Unable to parse PKCS#12 certificate. Check path and password.');
        }

        return [
            'private_key' => $certs['pkey'],
            'certificate' => $certs['cert'],
            'path'        => $path,
            'password'    => $password,
        ];
    }

    /**
     * @return array{private_key: string, certificate: string, path: string, password: string|null}
     */
    private function loadPem(string $path, ?string $password): array
    {
        $privateKey = openssl_pkey_get_private(file_get_contents($path) ?: '', $password ?? '');
        if ($privateKey === false) {
            throw new InvalidArgumentException('Unable to load PEM private key.');
        }

        $details = openssl_pkey_get_details($privateKey);
        // @codeCoverageIgnoreStart
        if ($details === false || !isset($details['key'])) {
            throw new InvalidArgumentException('Unable to export PEM private key.');
        }
        // @codeCoverageIgnoreEnd

        $certificate = file_get_contents($path) ?: '';

        return [
            'private_key' => $details['key'],
            'certificate' => $certificate,
            'path'        => $path,
            'password'    => $password,
        ];
    }
}
