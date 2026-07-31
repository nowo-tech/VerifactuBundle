# AEAT sandbox smoke test

Use this guide to validate the bundle against the **real AEAT sandbox** with your test certificate.

## Table of contents

- [Prerequisites](#prerequisites)
- [Configuration](#configuration)
- [CLI smoke test](#cli-smoke-test)
- [Demo web UI](#demo-web-ui)
- [Troubleshooting](#troubleshooting)
- [Anulación in sandbox](#anulación-in-sandbox)

## Prerequisites

1. AEAT test certificate (`.p12` / `.pfx`) with password
2. Symfony app with `nowo_verifactu` configured for sandbox
3. Network access to `prewww1.aeat.es`

## Configuration

```yaml
# config/packages/nowo_verifactu.yaml
nowo_verifactu:
    mode: verifactu
    hash_chain:
        storage: doctrine   # recommended for chained submissions
    aeat:
        environment: sandbox
        certificate_path: '%env(AEAT_CERT_PATH)%'
        certificate_password: '%env(AEAT_CERT_PASSWORD)%'
        validate_xsd: true
```

```bash
# .env.local
AEAT_CERT_PATH=/secure/path/aeat-test.p12
AEAT_CERT_PASSWORD=your-password
```

## CLI smoke test

Dry run (no certificate required — validates XML + hash only):

```bash
php bin/console nowo:verifactu:submit-sandbox \
  --nif=89890001K \
  --numserie=SANDBOX-001 \
  --fecha=09-07-2026 \
  --cuota=21.00 \
  --importe=121.00 \
  --generated-at=2026-07-09T16:00:00+02:00 \
  --dry-run
```

Submit to AEAT sandbox:

```bash
php bin/console nowo:verifactu:submit-sandbox \
  --nif=YOUR_NIF \
  --numserie=SANDBOX-001 \
  --submit
```

Expected success output includes `AEAT submission OK` and a CSV/reference when Hacienda accepts the record.

## Demo web UI

```bash
make -C demo/symfony8 up
# Open http://localhost:8010
```

1. Copy `demo/symfony8/.env.example` → `.env.local`
2. Set `AEAT_CERT_PATH` and `AEAT_CERT_PASSWORD`
3. Run schema update: `php bin/console doctrine:schema:update --force`
4. Issue an invoice with **Enviar a AEAT sandbox** checked

## Troubleshooting

| Symptom | Likely cause |
|---------|----------------|
| `certificate is not configured` | Missing `AEAT_CERT_PATH` env var |
| XSD validation failed | XML fields do not match AEAT schema — check NIF, dates, amounts |
| cURL SSL error | Wrong certificate password or expired cert |
| SOAP fault | NIF in record does not match certificate holder |
| Empty AEAT response | Network/firewall blocking AEAT endpoints |

## Anulación in sandbox

```bash
php bin/console nowo:verifactu:submit-sandbox \
  --record-type=Anulacion \
  --numserie=FAC-ALREADY-SENT \
  --submit
```

The hash chain must contain the previous alta for the same NIF when using Doctrine storage.
