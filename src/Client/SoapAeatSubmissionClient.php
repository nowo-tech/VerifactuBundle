<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Client;

use InvalidArgumentException;
use Nowo\VerifactuBundle\Model\BillingRecord;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use function sprintf;

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
     * Receives the envelope builder, endpoint resolver, transport, issuer configuration, mode, environment and certificate settings.
     *
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
        #[SensitiveParameter]
        private readonly ?string $certificatePassword,
        private readonly string $certificateType = 'personal',
        private readonly int $timeout = 30,
    ) {
    }

    /**
     * Sends the billing record to the AEAT SOAP service and parses the response.
     */
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

        $parsed     = $this->parseResponse($response['body']);
        $statusCode = $response['status_code'];
        $success    = $parsed['success'];
        $errors     = $parsed['errors'];

        if ($statusCode < 200 || $statusCode >= 300) {
            $success  = false;
            $errors[] = sprintf('AEAT returned HTTP status %d.', $statusCode);
        }

        $result = [
            'success'      => $success,
            'errors'       => $errors,
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

        // AEAT responses use namespace prefixes (e.g. <tikR:EstadoRegistro>); accept prefixed and unprefixed tags.
        $csv         = $this->extractElement($body, 'CSV');
        $status      = $this->extractElement($body, 'EstadoRegistro');
        $description = $this->extractElement($body, 'DescripcionErrorRegistro');

        if ($status === null || $status === '') {
            return [
                'success'   => false,
                'reference' => null,
                'errors'    => ['AEAT response does not contain an EstadoRegistro status.'],
            ];
        }

        $errors = [];
        if ($status !== 'Correcto') {
            $errors[] = $description !== null && $description !== ''
                ? sprintf('AEAT registration status "%s": %s', $status, $description)
                : sprintf('AEAT registration status "%s".', $status);
        }

        return [
            'success'   => $status === 'Correcto',
            'reference' => $csv !== null && $csv !== '' ? $csv : null,
            'errors'    => $errors,
        ];
    }

    private function extractElement(string $body, string $name): ?string
    {
        $pattern = '/<(?:[\w.-]+:)?' . $name . '(?:\s[^>]*)?>(.*?)<\/(?:[\w.-]+:)?' . $name . '>/s';
        if (preg_match($pattern, $body, $matches) !== 1) {
            return null;
        }

        return trim(html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }
}
