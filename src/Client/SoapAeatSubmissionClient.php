<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Client;

use InvalidArgumentException;
use Nowo\VerifactuBundle\Certificate\Certificate;
use Nowo\VerifactuBundle\Certificate\CertificateProviderInterface;
use Nowo\VerifactuBundle\Certificate\TemporaryCertificateFile;
use Nowo\VerifactuBundle\Model\AeatBatchResponse;
use Nowo\VerifactuBundle\Model\AeatEnvironment;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Provider\AeatEnvironmentProviderInterface;
use Nowo\VerifactuBundle\Provider\IssuerProviderInterface;
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
final class SoapAeatSubmissionClient implements AeatSubmissionClientInterface, BatchAeatSubmissionClientInterface
{
    public const SERVICE_NAME = 'nowo_verifactu.client.soap_aeat_submission';

    /**
     * Receives the envelope builder, endpoint resolver, transport, issuer configuration, mode, environment and certificate settings.
     *
     * With an issuer provider, the issuer is resolved on every submission and the static `$issuerConfig` is ignored. With
     * a certificate provider (per issuer NIF) or an environment provider, the static certificate settings or environment
     * are ignored likewise; certificate contents are written to a private temporary file for the cURL call only.
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
        private readonly ?IssuerProviderInterface $issuerProvider = null,
        private readonly ?CertificateProviderInterface $certificateProvider = null,
        private readonly ?AeatEnvironmentProviderInterface $environmentProvider = null,
        private readonly AeatResponseParser $responseParser = new AeatResponseParser(),
    ) {
    }

    /**
     * Sends the billing record to the AEAT SOAP service and parses the response.
     */
    public function submit(BillingRecord $record): array
    {
        $certificate = $this->certificateFor($record->issuerNif);
        // A chained record goes to the environment it was chained for; otherwise the current one.
        $environment = $record->environment ?? $this->environmentProvider?->getEnvironment() ?? AeatEnvironment::fromValue($this->environment);
        $payload     = $this->envelopeBuilder->buildSubmissionEnvelope($record, $this->issuerProvider?->getIssuer()->toArray() ?? $this->issuerConfig);
        $endpoint    = $this->endpointResolver->resolve($this->mode, $environment, $this->certificateType);
        $response    = $this->transmit($certificate, $endpoint, $payload);

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
     * Sends the records (one issuer, one environment, chain order) in one request and parses the per-record answer.
     * A transport error, a non-2xx status, a SoapFault or an unreadable body gives a failed response: no record of
     * the request was registered, so the same records may be sent again.
     */
    public function submitBatch(array $records, ?callable $beforeTransmit = null): AeatBatchResponse
    {
        if ($records === []) {
            throw new InvalidArgumentException('An AEAT submission needs at least one record.');
        }
        $environment = $records[0]->environment ?? $this->environmentProvider?->getEnvironment() ?? AeatEnvironment::fromValue($this->environment);
        foreach ($records as $record) {
            if ($record->environment !== null && $record->environment !== $environment) {
                throw new InvalidArgumentException('All records of one AEAT submission must belong to the same AEAT environment.');
            }
        }

        $certificate = $this->certificateFor($records[0]->issuerNif);
        $payload     = $this->envelopeBuilder->buildBatchEnvelope($records, $this->issuerProvider?->getIssuer()->toArray() ?? $this->issuerConfig);
        $endpoint    = $this->endpointResolver->resolve($this->mode, $environment, $this->certificateType);
        if ($beforeTransmit !== null) {
            // The request is built and about to leave: from here on it counts as an attempt (it may reach the AEAT).
            $beforeTransmit();
        }
        $response = $this->transmit($certificate, $endpoint, $payload);
        if (($response['error'] ?? null) !== null) {
            return AeatBatchResponse::failed((string) $response['error'], $response['status_code'], $response['body'], $endpoint, true);
        }

        return $this->responseParser->parse($response['body'], $response['status_code'], $endpoint);
    }

    private function certificateFor(string $issuerNif): Certificate
    {
        $certificate = $this->certificateProvider instanceof CertificateProviderInterface
            // Normalised NIF: the provider must still authorize it for the current tenant (see CertificateProviderInterface).
            ? $this->certificateProvider->getCertificate(strtoupper(trim($issuerNif)))
            : ($this->certificatePath === null || $this->certificatePath === '' ? null : Certificate::fromPath($this->certificatePath, $this->certificatePassword));
        if (!$certificate instanceof Certificate) {
            throw new InvalidArgumentException('AEAT certificate path is required for SOAP submission.');
        }

        return $certificate;
    }

    /**
     * @return array{status_code: int, body: string, error?: string}
     */
    private function transmit(#[SensitiveParameter] Certificate $certificate, string $endpoint, string $payload): array
    {
        // Contents go to cURL from memory when the transport can (no key file at all); otherwise a private temporary file.
        return $certificate->path === null && $this->transport instanceof CertificateAwareSoapTransportInterface && $this->transport->supportsCertificateInMemory()
            ? $this->transport->sendWithCertificate($endpoint, AeatEndpointResolver::SOAP_ACTION_ALTA, $payload, $certificate, $this->timeout)
            : TemporaryCertificateFile::with($certificate, fn (string $path): array => $this->transport->send(
                $endpoint,
                AeatEndpointResolver::SOAP_ACTION_ALTA,
                $payload,
                $path,
                $certificate->password(),
                $this->timeout,
            ));
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
