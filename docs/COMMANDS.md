# Console Commands

The bundle provides console commands for common Veri*Factu operations.

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

Submit to the AEAT sandbox (requires certificate configuration):

```bash
php bin/console nowo:verifactu:submit-sandbox \
  --nif=YOUR_NIF \
  --numserie=SANDBOX-001 \
  --submit
```

See [SANDBOX.md](SANDBOX.md) for certificate setup and expected output.
