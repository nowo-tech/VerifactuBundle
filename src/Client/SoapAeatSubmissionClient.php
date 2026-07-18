<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Client;

use InvalidArgumentException;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use const ENT_QUOTES;
use const ENT_XML1;

/**
 * Production AEAT submission client using SOAP 1.1 over HTTPS with client certificate.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[AsAlias(id: self::SERVICE_NAME, public: true)]
final class SoapAeatSubmissionClient implements AeatSubmissionClientInterface
{
    public const SERVICE_NAME = 'nowo_verifactu.client.soap_aeat_submission';

    /**
     * @param array{nif: string, name?: string} $issuerConfig
     */
    public function __construct(
        private readonly SoapEnvelopeBuilder $envelopeBuilder,
        private readonly AeatEndpointResolver $endpointResolver,
        private readonly SoapTransportInterface $transport,
        private readonly array $issuerConfig,
        private readonly string $mode,
        private readonly string $environment,
        private readonly ?string $certificatePath,
        private readonly ?string $certificatePassword,
        private readonly string $certificateType = 'personal',
        private readonly int $timeout = 30,
    ) {
    }

    public function submit(BillingRecord $record): array
    {
        if ($this->certificatePath === null || $this->certificatePath === '') {
            throw new InvalidArgumentException('AEAT certificate path is required for SOAP submission.');
        }

        $payload  = $this->envelopeBuilder->buildSubmissionEnvelope($record, $this->issuerConfig);
        $endpoint = $this->endpointResolver->resolve($this->mode, $this->environment, $this->certificateType);

        $response = $this->transport->send(
            $endpoint,
            AeatEndpointResolver::SOAP_ACTION_ALTA,
            $payload,
            $this->certificatePath,
            $this->certificatePassword,
            $this->timeout,
        );

        if (($response['error'] ?? null) !== null) {
            return [
                'success'  => false,
                'errors'   => [(string) $response['error']],
                'endpoint' => $endpoint,
            ];
        }

        $parsed = $this->parseResponse($response['body']);

        $result = [
            'success'      => $parsed['success'],
            'errors'       => $parsed['errors'],
            'endpoint'     => $endpoint,
            'status_code'  => $response['status_code'],
            'raw_response' => $response['body'],
        ];

        if ($parsed['reference'] !== null) {
            $result['reference'] = $parsed['reference'];
        }

        return $result;
    }

    /**
     * @return array{success: bool, reference: ?string, errors: list<string>}
     */
    private function parseResponse(string $body): array
    {
        if ($body === '') {
            return ['success' => false, 'reference' => null, 'errors' => ['Empty AEAT response body.']];
        }

        if (str_contains($body, 'env:Fault') || str_contains($body, 'Fault>')) {
            preg_match('/<faultstring>(.*?)<\/faultstring>/s', $body, $matches);

            return [
                'success'   => false,
                'reference' => null,
                'errors'    => [html_entity_decode(strip_tags($matches[1] ?? 'AEAT SOAP fault'), ENT_QUOTES | ENT_XML1, 'UTF-8')],
            ];
        }

        preg_match('/<CSV[^>]*>(.*?)<\/CSV>/s', $body, $csvMatch);
        preg_match('/<EstadoRegistro[^>]*>(.*?)<\/EstadoRegistro>/s', $body, $statusMatch);

        $csv    = trim(strip_tags($csvMatch[1] ?? ''));
        $status = trim(strip_tags($statusMatch[1] ?? 'Correcto'));

        return [
            'success'   => !str_contains(strtolower($status), 'incorrecto') && !str_contains(strtolower($status), 'error'),
            'reference' => $csv !== '' ? $csv : null,
            'errors'    => [],
        ];
    }
}
