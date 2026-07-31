# Usage

## Table of contents

- [Process a billing record](#process-a-billing-record)
- [Production AEAT submission](#production-aeat-submission)
- [Hash only](#hash-only)
- [QR in Twig](#qr-in-twig)
- [Console](#console)
- [Events](#events)

## Process a billing record

```php
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Service\BillingRecordProcessor;

$result = $processor->process(new BillingRecord(
    RecordType::Alta,
    issuerNif: '89890001K',
    invoiceSeriesNumber: 'FAC-2026-001',
    issueDate: '09-07-2026',
    invoiceType: 'F1',
    totalTaxAmount: '21.00',
    totalAmount: '121.00',
    generatedAt: '2026-07-09T16:00:00+02:00',
), submitToAeat: true);
```

The processor validates XML against official AEAT XSD schemas, optionally signs records with XAdES (No-Veri*Factu), persists the hash chain, and submits via SOAP when a certificate is configured.

## Production AEAT submission

Configure your AEAT certificate and environment:

```yaml
nowo_verifactu:
    mode: verifactu
    hash_chain:
        storage: doctrine
    aeat:
        environment: production
        certificate_path: '%env(AEAT_CERT_PATH)%'
        certificate_password: '%env(AEAT_CERT_PASSWORD)%'
        validate_xsd: true
```

For No-Veri*Factu with XAdES signing:

```yaml
nowo_verifactu:
    mode: no_verifactu
    aeat:
        certificate_path: '%env(AEAT_CERT_PATH)%'
        certificate_password: '%env(AEAT_CERT_PASSWORD)%'
        sign_xades: true
```

## Hash only

```php
use Nowo\VerifactuBundle\Generator\HashChainGenerator;

$hash = $hashChainGenerator->computeHash($record);
$input = $hashChainGenerator->buildInputString($record);
```

## QR in Twig

```twig
<img src="{{ verifactu_qr_data_uri(record) }}" alt="Veri*Factu QR">
<p>{{ verifactu_legend() }}</p>
<a href="{{ verifactu_qr_url(record) }}">Verify at AEAT</a>
```

## Console

```bash
php bin/console nowo:verifactu:validate-record \
  --nif=89890001K --numserie=FAC-001 --fecha=09-07-2026 \
  --cuota=21.00 --importe=121.00 --generated-at=2026-07-09T16:00:00+02:00

php bin/console nowo:verifactu:verify-hash \
  --nif=89890001K --numserie=12345678/G33 --fecha=01-01-2024 \
  --cuota=12.35 --importe=123.45 --generated-at=2024-01-01T19:20:30+01:00 \
  --hash=3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60
```

## Events

Listen to `VerifactuEvents::BEFORE_BILLING_RECORD_GENERATION` to mutate the record before hash/XML generation, or `VerifactuEvents::AFTER_BILLING_RECORD_GENERATED` for post-processing.
