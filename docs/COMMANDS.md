# Console Commands

The bundle provides console commands for common Veri*Factu operations.

## Table of contents

- [Validate billing record](#validate-billing-record)
- [Verify hash](#verify-hash)
- [Verify a stored chain](#verify-a-stored-chain)
- [Reset a chain](#reset-a-chain)
- [Submit waiting records in batches](#submit-waiting-records-in-batches)
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

Recomputes the AEAT fingerprint from the given fields and compares it to `--hash`. Amounts are hashed in their
canonical form (`12.30`); a hash computed by VerifactuBundle 1.1 – 1.4.1 without trailing zeros (`12.3`) is also
accepted, as the AEAT treats both forms as equal.

## Verify a stored chain

```bash
php bin/console nowo:verifactu:verify-chain --issuer=89890001K [--environment=sandbox|production] [--anchor=<hash of the head before the log started>]
```

Available when a record store is configured (`nowo_verifactu.record_store`). Walks every stored record of the issuer
and checks consecutive positions, each fingerprint, each previous-hash link, that the stored XML carries the same
`Huella`, `CuotaTotal` and `ImporteTotal` that were hashed, and that the chain head points at the last record. Exit code
`0` when valid, `1` with a table of problems otherwise, `2` without `--issuer`. When the log was enabled on an issuer
that already had a chain, the first stored record continues from the old head: the command prints that hash, and
`--anchor` requires it to be exactly the given one. The Doctrine store is read in pages of 500 records.

One AEAT environment is checked at a time: by default the environment of the current chain head (`--environment` picks
another). Records of the environment used before a reset are skipped; a record of another environment inside the chain
is reported. Rows written before 1.6 count as the configured `aeat.environment`. A reset closes the chain of the older
environment: after a sandbox → production reset, `verify-chain --environment sandbox` reports the later production
records (and the head) as problems. That is expected; verify the current environment instead.

## Reset a chain

```bash
php bin/console nowo:verifactu:reset-chain --issuer=89890001K [--force]
```

Removes the chain head of the issuer, so its next record starts a new chain (no previous hash). Needed before the first
production record of an issuer that was tested in the sandbox: a record is never chained onto a head of the other
environment. Stored records are kept. Asks for confirmation; `--force` skips it (required in non-interactive runs).

## Submit waiting records in batches

```bash
php bin/console nowo:verifactu:submit-pending [--issuer=89890001K] [--retry-failed]
```

Available with a record store. Sends the next batch of waiting records (up to `submission.batch_size`, at most 1000) of
the issuer, or of every issuer with waiting records, once, and prints the per-record outcome (accepted, accepted with
errors, rejected, unconfirmed duplicates, without answer), how many records are still waiting and when the next
submission may start (AEAT `TiempoEsperaEnvio`). A throttled issuer is reported, not waited for: run the command from a
scheduler, or dispatch the `SubmitPendingBillingRecords` Messenger message, which reschedules itself. Exit code `1`
when a batch got no per-record answer (its records stay pending) or a record the AEAT kept refusing on its own was
marked `failed`. `--retry-failed` (with `--issuer`) first puts the issuer's `failed` records back to pending, once the
cause of the refusal is fixed. Records written before 1.6 (no AEAT environment) are never sent (see UPGRADING).

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

**The only path to the AEAT outside production code.** `--submit` is the only bundle path that may send a request to
the AEAT from a development box, and only the owner runs it, with an owner-held certificate (never committed, never in
CI secrets, scratch directories or logs). The test suite and CI set `VERIFACTU_FORBID_NETWORK=1`, which makes the real
cURL transport refuse every request before it is built; with that variable set, `--submit` fails with a transport
error instead of reaching the AEAT.

**Outside the sandbox** (`aeat.environment: production`) `--submit` is refused: a real registration cannot be undone. To submit anyway, pass `--allow-production`, give `--nif` and `--numserie` explicitly and confirm the interactive prompt (non-interactive runs are refused):

```bash
php bin/console nowo:verifactu:submit-sandbox \
  --nif=YOUR_NIF \
  --numserie=FAC-2026-001 \
  --submit --allow-production
```
