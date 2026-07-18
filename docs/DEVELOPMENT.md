# Development Guide

This document provides information about developing and contributing to the Verifactu Bundle.

## Development setup

### Using Docker (recommended)

```bash
make up
make install
make test
make test-coverage
make qa
```

### Without Docker

```bash
composer install
composer test
composer test-coverage
composer qa
```

## Testing

Tests live under `tests/`, split into:

- **`tests/Unit/`** — isolated unit tests (generators, validators, clients, signer, Twig, DI, models)
- **`tests/Integration/`** — kernel boot, Doctrine hash-chain repository, console commands

Coverage areas:

- **Hash & XML**: `HashChainGenerator`, `BillingRecordXmlGenerator`, `XsdValidator`
- **Validation**: `AeatBusinessRulesValidator`, `SpanishTaxIdValidator`
- **AEAT client**: `SoapAeatSubmissionClient`, `CurlSoapTransport`, `AeatEndpointResolver`
- **Signing**: `XadesBillingRecordSigner`, `CertificateLoader`
- **Storage**: `InMemoryHashChainRepository`, `DoctrineHashChainRepository`
- **Processing**: `BillingRecordProcessor`, events, `InvoiceToBillingRecordMapper`
- **QR / Twig**: `QrCodeGenerator`, `VerifactuTwigExtension`
- **Commands**: validate-record, verify-hash, submit-sandbox

**Target:** at least **80%** line coverage (enforced in CI); current suite is ~98%.

```bash
composer test
composer test-coverage
# open coverage/index.html
```

## Code quality

```bash
composer cs-check    # or make cs-check
composer cs-fix      # or make cs-fix
composer rector-dry  # or make rector-dry
composer rector      # or make rector
composer phpstan     # or make phpstan
make release-check   # full pre-release gate
```

## CI/CD

GitHub Actions (`.github/workflows/ci.yml`) runs the PHP/Symfony matrix, style, PHPStan, and coverage. Tag pushes (`v*`) trigger `.github/workflows/release.yml`.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) and [BRANCHING.md](BRANCHING.md). Spec-driven workflow: [SPEC-DRIVEN-DEVELOPMENT.md](SPEC-DRIVEN-DEVELOPMENT.md) and [SPEC-KIT.md](SPEC-KIT.md).

## Demo

```bash
make -C demo/symfony8 up
```

Details: [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md) and [DEMOS.md](DEMOS.md).
