# Console Commands

The bundle provides console commands for common Veri*Factu operations.

## Table of contents

- [Validate billing record](#validate-billing-record)
- [Verify hash](#verify-hash)
- [Submit to AEAT sandbox](#submit-to-aeat-sandbox)

## Validate billing record

```bash
php bin/console nowo:verifactu:validate-record \
  --nif=89890001K \
  --numserie=FAC-001 \
  --fecha=09-07-2026 \
  --cuota=21.00 \
  --importe=121.00 \
  --generated-at=2026-07-09T16:00:00+02:00
```

Validates business-rule fields and prints a hash preview without persisting or submitting.

## Verify hash

```bash
php bin/console nowo:verifactu:verify-hash \
  --nif=89890001K \
  --numserie=12345678/G33 \
  --fecha=01-01-2024 \
  --cuota=12.35 \
  --importe=123.45 \
  --generated-at=2024-01-01T19:20:30+01:00 \
  --hash=3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60
```

Recomputes the AEAT fingerprint from the given fields and compares it to `--hash`.

## Submit to AEAT sandbox

Dry run (no certificate required — validates XML + hash only; the record is chained onto the current head but the configured hash chain is **not** updated, also when `--dry-run` is combined with `--submit`):

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

Submit to the AEAT sandbox (requires certificate configuration):

```bash
php bin/console nowo:verifactu:submit-sandbox \
  --nif=YOUR_NIF \
  --numserie=SANDBOX-001 \
  --submit
```

See [SANDBOX.md](SANDBOX.md) for certificate setup and expected output.

**Outside the sandbox** (`aeat.environment: production`) `--submit` is refused: a real registration cannot be undone. To submit anyway, pass `--allow-production`, give `--nif` and `--numserie` explicitly and confirm the interactive prompt (non-interactive runs are refused):

```bash
php bin/console nowo:verifactu:submit-sandbox \
  --nif=YOUR_NIF \
  --numserie=FAC-2026-001 \
  --submit --allow-production
```
