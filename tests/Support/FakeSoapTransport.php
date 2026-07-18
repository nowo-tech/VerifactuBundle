<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Support;

use Nowo\VerifactuBundle\Client\SoapTransportInterface;

/**
 * Test double for AEAT SOAP transport.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class FakeSoapTransport implements SoapTransportInterface
{
    public function __construct(
        private readonly string $body = '',
        private readonly ?string $error = null,
    ) {
    }

    public function send(string $endpoint, string $soapAction, string $payload, string $certificatePath, ?string $certificatePassword, int $timeout): array
    {
        if ($this->error !== null) {
            return ['status_code' => 0, 'body' => '', 'error' => $this->error];
        }

        return ['status_code' => 200, 'body' => $this->body];
    }
}
