# VerifactuBundle

[![CI](https://github.com/nowo-tech/VerifactuBundle/actions/workflows/ci.yml/badge.svg)](https://github.com/nowo-tech/VerifactuBundle/actions/workflows/ci.yml) [![Packagist Version](https://img.shields.io/packagist/v/nowo-tech/verifactu-bundle.svg?style=flat)](https://packagist.org/packages/nowo-tech/verifactu-bundle) [![Packagist Downloads](https://img.shields.io/packagist/dt/nowo-tech/verifactu-bundle.svg)](https://packagist.org/packages/nowo-tech/verifactu-bundle) [![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE) [![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php)](https://php.net) [![Symfony](https://img.shields.io/badge/Symfony-6.0%2B%20%7C%207.4%2B%20%7C%208.0%20%7C%208.1%2B-000000?logo=symfony)](https://symfony.com) [![Coverage](https://img.shields.io/badge/coverage-manual-lightgrey)](#tests)

> ⭐ **Found this useful?** Give it a star on GitHub! It helps us maintain and improve the project.

**Symfony bundle for Spanish Veri*Factu compliance (RD 1007/2023)** — SHA-256 hash chains, billing record XML, AEAT QR codes, validation, and submission hooks for SIF-compatible invoicing systems.

## Features

- ✅ **Hash chain (SHA-256)**: AEAT-compliant fingerprint calculation and encadenamiento
- ✅ **Billing record XML**: Registro de facturación generation (Alta / Anulación)
- ✅ **QR codes**: ISO/IEC 18004 QR with AEAT verification URL and mandatory legend
- ✅ **Business rules validation**: NIF/CIF/NIE, dates, amounts, invoice types
- ✅ **Symfony events**: Before/after generation and AEAT submission hooks
- ✅ **Console commands**: Validate records and verify hashes from CLI
- ✅ **Twig helpers**: `verifactu_qr_data_uri`, `verifactu_qr_url`, `verifactu_legend`
- ✅ **Pluggable storage**: `HashChainRepositoryInterface` with in-memory and Doctrine backends
- ✅ **AEAT SOAP client**: mTLS submission to sandbox/production with client certificate
- ✅ **XAdES signing**: No-Veri*Factu modalidad with PKCS#12/PEM certificates
- ✅ **XSD validation**: Official AEAT schemas shipped with the bundle
- ✅ **Nowo integration**: `InvoiceDraft` + `InvoiceToBillingRecordMapper` for ERP adoption
- ✅ **Sandbox CLI**: `nowo:verifactu:submit-sandbox` smoke-test command
- ✅ **Veri*Factu and No-Veri*Factu modes**: Configuration-driven behaviour
- ✅ **i18n**: Validation messages in 7 locales (en, es, it, fr, pt, de, nl)

## Installation

```bash
composer require nowo-tech/verifactu-bundle
```

Register the bundle in `config/bundles.php`:

```php
<?php

return [
    // ...
    Nowo\VerifactuBundle\NowoVerifactuBundle::class => ['all' => true],
];
```

## Usage

See [docs/USAGE.md](docs/USAGE.md) for the full API. Quick example:

```php
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Service\BillingRecordProcessor;

/** @var BillingRecordProcessor $processor */
$processor = $container->get('nowo_verifactu.service.billing_record_processor');

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

if ([] === $result['errors']) {
    echo $result['record']->hash;
    echo $result['record']->xml;
}
```

## Documentation

- [Installation](docs/INSTALLATION.md)
- [Configuration](docs/CONFIGURATION.md)
- [Usage](docs/USAGE.md)
- [Contributing](docs/CONTRIBUTING.md)
- [Changelog](docs/CHANGELOG.md)
- [Upgrading](docs/UPGRADING.md)
- [Release process](docs/RELEASE.md)
- [Security policy](docs/SECURITY.md)
- [Engram (MCP memory)](docs/ENGRAM.md)
- [Spec-driven development](docs/SPEC-DRIVEN-DEVELOPMENT.md)

### Additional documentation

- [GitHub Spec Kit](docs/SPEC-KIT.md)
- [AEAT sandbox testing](docs/SANDBOX.md)
- [Nowo integration guide](docs/INTEGRATION-NOWO.md)
- [FrankenPHP demo](docs/DEMO-FRANKENPHP.md)

## Demo

Symfony 8 demo with FrankenPHP:

```bash
make -C demo/symfony8 up
# Open http://localhost:8010
```

## Tests and coverage

- Tests: PHPUnit (PHP)
- PHP: **~98%** (116 tests; target ~100% per REQ-TEST-003; minimum 80% enforced in CI)
- TS/JS: N/A
- Python: N/A

Exclusions documented in [`docs/SPEC-DRIVEN-DEVELOPMENT.md`](docs/SPEC-DRIVEN-DEVELOPMENT.md#validation).

```bash
make up && make install && make test
make test-coverage
make release-check
```

## License

This bundle is released under the [MIT License](LICENSE).

## Author

Created by [Nowo.tech](https://nowo.tech)
