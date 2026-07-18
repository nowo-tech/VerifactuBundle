<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Client;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use function in_array;
use function is_string;

use const CURLINFO_HTTP_CODE;
use const CURLOPT_CONNECTTIMEOUT;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_POST;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_SSLCERT;
use const CURLOPT_SSLCERTPASSWD;
use const CURLOPT_SSLCERTTYPE;
use const CURLOPT_TIMEOUT;
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

    public function send(
        string $endpoint,
        string $soapAction,
        string $payload,
        string $certificatePath,
        ?string $certificatePassword,
        int $timeout,
    ): array {
        if ($certificatePath === '') {
            return ['status_code' => 0, 'body' => '', 'error' => 'Certificate path is required.'];
        }

        $handle = curl_init($endpoint);
        if ($handle === false) {
            return ['status_code' => 0, 'body' => '', 'error' => 'Unable to initialize cURL.'];
        }

        $certType = $this->resolveCertType($certificatePath);

        $options = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSLCERT        => $certificatePath,
            CURLOPT_SSLCERTTYPE    => $certType,
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
        $statusCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error      = curl_error($handle);
        curl_close($handle);

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
