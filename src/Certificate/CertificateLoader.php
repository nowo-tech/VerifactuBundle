<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Certificate;

use InvalidArgumentException;
use SensitiveParameter;
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
     * Loads a PKCS#12 or PEM certificate and returns its private key and certificate in PEM.
     *
     * @return array{private_key: string, certificate: string, path: string}
     */
    public function load(string $path, #[SensitiveParameter] ?string $password = null): array
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
     * Loads a certificate given by path or by contents (CertificateProviderInterface); `path` is '' for contents.
     *
     * @return array{private_key: string, certificate: string, path: string}
     */
    public function loadCertificate(Certificate $certificate): array
    {
        if ($certificate->path !== null) {
            return $this->load($certificate->path, $certificate->password);
        }

        return $certificate->format === Certificate::FORMAT_PKCS12
            ? $this->parsePkcs12((string) $certificate->contents, $certificate->password, '')
            : $this->parsePem((string) $certificate->contents, $certificate->password, '');
    }

    /**
     * @return array{private_key: string, certificate: string, path: string}
     */
    private function loadPkcs12(string $path, #[SensitiveParameter] ?string $password): array
    {
        $contents = file_get_contents($path);
        // @codeCoverageIgnoreStart
        if ($contents === false) {
            throw new InvalidArgumentException(sprintf('Unable to read certificate: %s', $path));
        }
        // @codeCoverageIgnoreEnd

        return $this->parsePkcs12($contents, $password, $path);
    }

    /**
     * @return array{private_key: string, certificate: string, path: string}
     */
    private function parsePkcs12(#[SensitiveParameter] string $contents, #[SensitiveParameter] ?string $password, string $path): array
    {
        $certs = [];
        if (!openssl_pkcs12_read($contents, $certs, $password ?? '')) {
            throw new InvalidArgumentException('Unable to parse PKCS#12 certificate. Check path and password.');
        }

        return [
            'private_key' => $certs['pkey'],
            'certificate' => $certs['cert'],
            'path'        => $path,
        ];
    }

    /**
     * @return array{private_key: string, certificate: string, path: string}
     */
    private function loadPem(string $path, #[SensitiveParameter] ?string $password): array
    {
        $contents = file_get_contents($path);
        // @codeCoverageIgnoreStart
        if ($contents === false) {
            throw new InvalidArgumentException(sprintf('Unable to read certificate: %s', $path));
        }
        // @codeCoverageIgnoreEnd

        return $this->parsePem($contents, $password, $path);
    }

    /**
     * @return array{private_key: string, certificate: string, path: string}
     */
    private function parsePem(#[SensitiveParameter] string $contents, #[SensitiveParameter] ?string $password, string $path): array
    {
        $privateKey = openssl_pkey_get_private($contents, $password ?? '');
        if ($privateKey === false) {
            throw new InvalidArgumentException('Unable to load PEM private key. Check path and password.');
        }

        $certificate = str_contains($contents, '-----BEGIN CERTIFICATE-----') ? @openssl_x509_read($contents) : false;
        if ($certificate === false) {
            throw new InvalidArgumentException('The PEM file contains no X.509 certificate: put the certificate and its private key in the same file.');
        }

        if (!openssl_x509_check_private_key($certificate, $privateKey)) {
            throw new InvalidArgumentException('The PEM certificate does not match its private key.');
        }

        $exportedKey         = '';
        $exportedCertificate = '';
        // @codeCoverageIgnoreStart
        if (!openssl_pkey_export($privateKey, $exportedKey) || !openssl_x509_export($certificate, $exportedCertificate)) {
            throw new InvalidArgumentException('Unable to export the PEM key material.');
        }
        // @codeCoverageIgnoreEnd

        return [
            'private_key' => $exportedKey,
            'certificate' => $exportedCertificate,
            'path'        => $path,
        ];
    }
}
