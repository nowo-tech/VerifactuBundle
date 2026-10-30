<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Client;

use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use function in_array;
use function is_string;

use const CURLINFO_HTTP_CODE;
use const CURLOPT_CONNECTTIMEOUT;
use const CURLOPT_FOLLOWLOCATION;
use const CURLOPT_HTTPHEADER;
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
final class CurlSoapTransport implements SoapTransportInterface
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

        $handle = curl_init($endpoint);
        // @codeCoverageIgnoreStart
        if ($handle === false) {
            return ['status_code' => 0, 'body' => '', 'error' => 'Unable to initialize cURL.'];
        }
        // @codeCoverageIgnoreEnd

        $certType = $this->resolveCertType($certificatePath);

        $options = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSLCERT        => $certificatePath,
            CURLOPT_SSLCERTTYPE    => $certType,
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

        if ($certificatePassword !== null && $certificatePassword !== '') {
            $options[CURLOPT_SSLCERTPASSWD] = $certificatePassword;
        }

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

    /**
     * @return 'P12'|'PEM'
     */
    private function resolveCertType(string $certificatePath): string
    {
        $extension = strtolower(pathinfo($certificatePath, PATHINFO_EXTENSION));

        return in_array($extension, ['p12', 'pfx'], true) ? 'P12' : 'PEM';
    }
}
