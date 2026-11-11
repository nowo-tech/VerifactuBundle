<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Client;

use Nowo\VerifactuBundle\Certificate\Certificate;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use function constant;
use function defined;
use function in_array;
use function is_string;

use const CURLINFO_HTTP_CODE;
use const CURLOPT_CONNECTTIMEOUT;
use const CURLOPT_FOLLOWLOCATION;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_KEYPASSWD;
use const CURLOPT_MAXFILESIZE;
use const CURLOPT_POST;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_PROTOCOLS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_SSL_VERIFYHOST;
use const CURLOPT_SSL_VERIFYPEER;
use const CURLOPT_SSLCERT;
use const CURLOPT_SSLCERTPASSWD;
use const CURLOPT_SSLCERTTYPE;
use const CURLOPT_SSLKEYTYPE;
use const CURLOPT_TIMEOUT;
use const CURLPROTO_HTTPS;
use const PATHINFO_EXTENSION;

/**
 * cURL-based SOAP transport with mutual TLS for AEAT endpoints.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[AsAlias(id: self::SERVICE_NAME, public: true)]
final class CurlSoapTransport implements CertificateAwareSoapTransportInterface
{
    public const SERVICE_NAME = 'nowo_verifactu.client.curl_soap_transport';

    /** Largest AEAT response accepted (bytes, when the server announces Content-Length). */
    public const MAX_RESPONSE_BYTES = 10_485_760;

    /**
     * Posts the SOAP payload over mutual TLS with the client certificate and the configured timeout.
     */
    public function send(
        string $endpoint,
        string $soapAction,
        string $payload,
        string $certificatePath,
        #[SensitiveParameter]
        ?string $certificatePassword,
        int $timeout,
    ): array {
        if ($certificatePath === '') {
            return ['status_code' => 0, 'body' => '', 'error' => 'Certificate path is required.'];
        }

        $options = [
            CURLOPT_SSLCERT     => $certificatePath,
            CURLOPT_SSLCERTTYPE => $this->resolveCertType($certificatePath),
        ];
        if ($certificatePassword !== null && $certificatePassword !== '') {
            $options[CURLOPT_SSLCERTPASSWD] = $certificatePassword;
        }

        return $this->post($endpoint, $soapAction, $payload, $options, $timeout);
    }

    /**
     * Whether cURL takes the certificate from memory (CURLOPT_SSLCERT_BLOB: PHP ≥ 8.1 built with libcurl ≥ 7.71).
     */
    public function supportsCertificateInMemory(): bool
    {
        return defined('CURLOPT_SSLCERT_BLOB') && defined('CURLOPT_SSLKEY_BLOB');
    }

    /**
     * Sends with the certificate from memory: no file, so nothing to clean up after an exit, a fatal error or a timeout.
     * A PKCS#12 blob carries the key; a PEM blob is given as both the certificate and the key.
     */
    public function sendWithCertificate(string $endpoint, string $soapAction, string $payload, #[SensitiveParameter] Certificate $certificate, int $timeout): array
    {
        if ($certificate->path !== null) {
            return $this->send($endpoint, $soapAction, $payload, $certificate->path, $certificate->password(), $timeout);
        }

        // @codeCoverageIgnoreStart
        if (!$this->supportsCertificateInMemory()) {
            return ['status_code' => 0, 'body' => '', 'error' => 'This PHP / libcurl build cannot use a certificate from memory (CURLOPT_SSLCERT_BLOB).'];
        }
        // @codeCoverageIgnoreEnd

        $pkcs12  = $certificate->format === Certificate::FORMAT_PKCS12;
        $options = [
            constant('CURLOPT_SSLCERT_BLOB') => (string) $certificate->contents(),
            CURLOPT_SSLCERTTYPE              => $pkcs12 ? 'P12' : 'PEM',
        ];
        if (!$pkcs12) {
            $options[constant('CURLOPT_SSLKEY_BLOB')] = (string) $certificate->contents();
            $options[CURLOPT_SSLKEYTYPE]              = 'PEM';
        }
        $password = $certificate->password();
        if ($password !== null && $password !== '') {
            $options[CURLOPT_SSLCERTPASSWD] = $password;
            $options[CURLOPT_KEYPASSWD]     = $password;
        }

        return $this->post($endpoint, $soapAction, $payload, $options, $timeout);
    }

    /**
     * @param array<int, mixed> $certificateOptions
     *
     * @return array{status_code: int, body: string, error?: string}
     */
    private function post(string $endpoint, string $soapAction, string $payload, #[SensitiveParameter] array $certificateOptions, int $timeout): array
    {
        $handle = curl_init($endpoint);
        // @codeCoverageIgnoreStart
        if ($handle === false) {
            return ['status_code' => 0, 'body' => '', 'error' => 'Unable to initialize cURL.'];
        }
        // @codeCoverageIgnoreEnd

        $options = $certificateOptions + [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT        => $timeout,
            // Explicit TLS policy: never follow redirects, HTTPS only, verify the AEAT certificate and host name,
            // and cap the response size.
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_MAXFILESIZE    => self::MAX_RESPONSE_BYTES,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: "' . $soapAction . '"',
            ],
        ];

        curl_setopt_array($handle, $options);

        $body       = curl_exec($handle);
        $statusCode = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error      = curl_error($handle);
        // No curl_close(): it has no effect since PHP 8.0 (the handle is freed with $handle) and is deprecated in 8.5.

        $result = [
            'status_code' => $statusCode,
            'body'        => is_string($body) ? $body : '',
        ];

        if ($error !== '') {
            $result['error'] = $error;
        }

        return $result;
    }

    private function resolveCertType(string $certificatePath): string
    {
        $extension = strtolower(pathinfo($certificatePath, PATHINFO_EXTENSION));

        return in_array($extension, ['p12', 'pfx'], true) ? 'P12' : 'PEM';
    }
}
