<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Client;

/**
 * HTTP transport for AEAT SOAP requests (mTLS via client certificate).
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
interface SoapTransportInterface
{
    /**
     * @return array{status_code: int, body: string, error?: string}
     */
    public function send(string $endpoint, string $soapAction, string $payload, string $certificatePath, ?string $certificatePassword, int $timeout): array;
}
