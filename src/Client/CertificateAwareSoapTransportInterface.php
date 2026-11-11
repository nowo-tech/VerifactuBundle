<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Client;

use Nowo\VerifactuBundle\Certificate\Certificate;
use SensitiveParameter;

/**
 * A SOAP transport that can take the client certificate itself (contents in memory), so no private key is ever written
 * to disk. SoapAeatSubmissionClient uses it for certificates given by contents when supports() is true, and falls back
 * to a private temporary file through SoapTransportInterface::send() otherwise.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
interface CertificateAwareSoapTransportInterface extends SoapTransportInterface
{
    /**
     * Whether this runtime can send the certificate from memory (cURL: PHP ≥ 8.1 built with libcurl ≥ 7.71).
     */
    public function supportsCertificateInMemory(): bool;

    /**
     * Posts the SOAP envelope with the certificate taken from memory.
     *
     * @return array{status_code: int, body: string, error?: string}
     */
    public function sendWithCertificate(string $endpoint, string $soapAction, string $payload, #[SensitiveParameter] Certificate $certificate, int $timeout): array;
}
