#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Real AEAT sandbox smoke test (no Symfony app required).
 *
 * Usage:
 *   AEAT_CERT_PATH=/secure/aeat.p12 AEAT_CERT_PASSWORD=secret \
 *     php scripts/aeat-sandbox-submit.php --submit
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */

use Nowo\VerifactuBundle\Client\AeatEndpointResolver;
use Nowo\VerifactuBundle\Client\CurlSoapTransport;
use Nowo\VerifactuBundle\Client\SoapAeatSubmissionClient;
use Nowo\VerifactuBundle\Client\SoapEnvelopeBuilder;
use Nowo\VerifactuBundle\Generator\BillingRecordXmlGenerator;
use Nowo\VerifactuBundle\Generator\HashChainGenerator;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Repository\InMemoryHashChainRepository;
use Nowo\VerifactuBundle\Service\BillingRecordProcessor;
use Nowo\VerifactuBundle\Validator\AeatBusinessRulesValidator;
use Nowo\VerifactuBundle\Validator\SpanishTaxIdValidator;
use Nowo\VerifactuBundle\Validator\XsdValidator;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Translation\IdentityTranslator;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = parseArguments($argv);

$certPath = $options['cert-path'] ?? getenv('AEAT_CERT_PATH') ?: null;
$certPassword = $options['cert-password'] ?? getenv('AEAT_CERT_PASSWORD') ?: null;
$submit = (bool) ($options['submit'] ?? false);
$dryRun = (bool) ($options['dry-run'] ?? false) || !$submit;

if ($submit && ($certPath === null || $certPath === '' || !is_file($certPath))) {
    fwrite(STDERR, "ERROR: AEAT certificate not found.\n");
    fwrite(STDERR, "Set AEAT_CERT_PATH (and AEAT_CERT_PASSWORD) to your AEAT sandbox .p12/.pfx.\n");
    fwrite(STDERR, "Example:\n");
    fwrite(STDERR, "  AEAT_CERT_PATH=/secure/aeat-test.p12 AEAT_CERT_PASSWORD=secret php scripts/aeat-sandbox-submit.php --submit\n");
    exit(1);
}

$issuerNif = (string) ($options['nif'] ?? '89890001K');
$numSerie = (string) ($options['numserie'] ?? 'SANDBOX-' . date('Ymd-His'));
$fecha = (string) ($options['fecha'] ?? date('d-m-Y'));
$generatedAt = (string) ($options['generated-at'] ?? date('c'));
$recordType = RecordType::tryFrom((string) ($options['record-type'] ?? 'Alta')) ?? RecordType::Alta;

$issuerConfig = [
    'nif'  => $issuerNif,
    'name' => (string) ($options['issuer-name'] ?? 'Sandbox Test Issuer'),
];
$softwareConfig = [
    'manufacturer_nif'  => $issuerNif,
    'manufacturer_name' => 'Nowo.tech',
    'name'              => 'VerifactuBundle',
    'id'                => '01',
    'version'           => '1.0.0',
];
$installationConfig = ['number' => '001'];

$record = new BillingRecord(
    $recordType,
    $issuerNif,
    $numSerie,
    $fecha,
    (string) ($options['tipo'] ?? 'F1'),
    (string) ($options['cuota'] ?? '21.00'),
    (string) ($options['importe'] ?? '121.00'),
    $generatedAt,
    issuerName: $issuerConfig['name'],
    operationDescription: 'AEAT sandbox smoke test',
);

$translator = new IdentityTranslator();
$processor = new BillingRecordProcessor(
    new AeatBusinessRulesValidator(new SpanishTaxIdValidator(), $translator),
    new HashChainGenerator(),
    new BillingRecordXmlGenerator(),
    new XsdValidator($translator, true),
    new InMemoryHashChainRepository(),
    new SoapAeatSubmissionClient(
        new SoapEnvelopeBuilder(),
        new AeatEndpointResolver(),
        new CurlSoapTransport(),
        $issuerConfig,
        'verifactu',
        'sandbox',
        $certPath,
        $certPassword,
    ),
    new EventDispatcher(),
    $issuerConfig,
    $softwareConfig,
    $installationConfig,
    'verifactu',
    false,
    null,
);

echo "Veri*Factu AEAT sandbox smoke test\n";
echo str_repeat('-', 40) . "\n";
echo "NIF:          {$issuerNif}\n";
echo "NumSerie:     {$numSerie}\n";
echo "Record type:  {$recordType->value}\n";
echo "Environment:  sandbox\n";
echo "Mode:         " . ($dryRun ? 'dry-run (no AEAT call)' : 'submit') . "\n";
if ($certPath !== null && $certPath !== '') {
    echo "Certificate:  {$certPath}\n";
}
echo str_repeat('-', 40) . "\n";

$result = $processor->process($record, submitToAeat: $submit && !$dryRun);

if ($result['errors'] !== []) {
    fwrite(STDERR, "Validation/XSD failed:\n");
    foreach ($result['errors'] as $error) {
        fwrite(STDERR, "  - {$error}\n");
    }
    exit(1);
}

$processed = $result['record'];
echo "Hash: {$processed->hash}\n";
echo "Previous hash: " . ($processed->previousHash ?? '(first record)') . "\n";

if (isset($result['submission'])) {
    $submission = $result['submission'];
    echo "Endpoint: " . ($submission['endpoint'] ?? '(unknown)') . "\n";
    echo "HTTP status: " . ($submission['status_code'] ?? 0) . "\n";

    if (($submission['success'] ?? false) === true) {
        echo "RESULT: AEAT submission OK\n";
        echo 'CSV/reference: ' . ($submission['reference'] ?? '(none)') . "\n";
        exit(0);
    }

    fwrite(STDERR, "RESULT: AEAT submission FAILED\n");
    foreach ($submission['errors'] ?? [] as $error) {
        fwrite(STDERR, "  - {$error}\n");
    }
    if (isset($submission['raw_response']) && is_string($submission['raw_response']) && $submission['raw_response'] !== '') {
        fwrite(STDERR, "\nRaw response (truncated):\n");
        fwrite(STDERR, substr($submission['raw_response'], 0, 2000) . "\n");
    }
    exit(1);
}

echo "RESULT: Dry run OK — XML and hash generated. Re-run with --submit to call AEAT.\n";
exit(0);

/**
 * @param list<string> $argv
 *
 * @return array<string, string|bool>
 */
function parseArguments(array $argv): array
{
    $parsed = [];
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--submit') {
            $parsed['submit'] = true;
            continue;
        }
        if ($arg === '--dry-run') {
            $parsed['dry-run'] = true;
            continue;
        }
        if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
            continue;
        }
        [$key, $value] = explode('=', substr($arg, 2), 2);
        $parsed[str_replace('_', '-', $key)] = $value;
    }

    return $parsed;
}
