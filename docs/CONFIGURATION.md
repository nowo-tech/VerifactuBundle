# Configuration

Configuration root: `nowo_verifactu`

```yaml
nowo_verifactu:
    mode: verifactu              # verifactu | no_verifactu (also selects the QR URL: ValidarQR / ValidarQRNoVerifactu)
    aeat_spec_version: '1.0.0'   # reserved (exposed as a parameter only, no effect on output)
    issuer:
        nif: '89890001K'
        name: 'My Company SL'
    software:
        id: '01'
        name: 'MyApp'
        version: '1.0.0'
        manufacturer_nif: 'B12345678'
        manufacturer_name: 'Nowo.tech'
        solo_verifactu: true
    installation:
        number: '001'
    hash_chain:
        storage: memory          # memory | doctrine
        repository: null         # custom service id (optional)
        table_prefix: verifactu_ # deprecated, no effect: use doctrine.table_prefix
    doctrine:
        table_prefix: ''         # prepended to the bundle table names (REQ-ORM-002)
    aeat:
        environment: sandbox     # sandbox | production
        submission_client: null  # custom client service id (optional)
        certificate_path: '%env(AEAT_CERT_PATH)%'
        certificate_password: '%env(AEAT_CERT_PASSWORD)%'
        certificate_type: personal  # personal | seal
        timeout: 30
        validate_xsd: true
        sign_xades: false        # auto-enabled for no_verifactu mode
    qr:
        size_mm: 35
        legend: verifactu
```

## Production setup

### SOAP client (Veri*Factu)

When `aeat.certificate_path` is set, the bundle auto-wires `SoapAeatSubmissionClient` with mTLS via cURL against the official AEAT endpoints.

### Hash chain persistence

Set `hash_chain.storage: doctrine` and install `doctrine/orm` + `doctrine/doctrine-bundle`. The bundle registers the `BillingRecordHashChain` entity automatically. Doctrine is optional: with the default `memory` storage the bundle never loads Doctrine classes, and `storage: doctrine` without DoctrineBundle installed fails at container build with an explicit `LogicException`. Alternatively set `hash_chain.repository` to your own `HashChainRepositoryInterface` service id (it is aliased to the interface).

**Concurrency.** `BillingRecordProcessor` reads the chain head, hashes the new record and stores the new head. When the repository implements `LockableHashChainRepositoryInterface`, that read-then-write runs inside `withChainLock()`, so concurrent requests for the same issuer NIF cannot chain onto the same previous record. The Doctrine repository opens a transaction and takes a pessimistic write lock (`SELECT … FOR UPDATE`) on the issuer row; for the first record of an issuer the unique constraint on `issuer_nif` makes a concurrent second insert fail instead of forking the chain. Custom repositories should implement the interface (database row lock, Symfony Lock, etc.). Note that SQLite ignores `FOR UPDATE`; use a server database in production.

**Table prefix.** `doctrine.table_prefix` (default `''`; letters, digits and underscores only) is prepended to every table the bundle owns, to its unique constraint and to any many-to-many join table of bundle entities, so several applications can share one database. It is applied by a Doctrine `loadClassMetadata` listener scoped to the `Nowo\VerifactuBundle\Entity\` namespace (host entities are never renamed); the bundle only queries through DQL, so every query follows the prefixed name.

| `doctrine.table_prefix` | Table | Unique constraint |
| --- | --- | --- |
| `''` (default) | `verifactu_hash_chain` | `uniq_verifactu_hash_chain_issuer` |
| `app_` | `app_verifactu_hash_chain` | `app_uniq_verifactu_hash_chain_issuer` |

Changing the prefix on an existing database requires renaming the table (see [UPGRADING.md](UPGRADING.md#unreleased)).

**Deprecated / reserved options.** `hash_chain.table_prefix` is deprecated and has no effect (use `doctrine.table_prefix`). `aeat_spec_version` is accepted for forward compatibility but has no effect today.

The default `memory` storage is **request-scoped** (cleared at the start of every main request, also in FrankenPHP worker mode without `services_resetter`): every request starts a new chain. It is meant for development and tests; with `kernel.debug: false` it logs a warning on every stored record. Full worker-mode notes: [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).

### XAdES signing (No-Veri*Factu)

In `no_verifactu` mode, billing records are signed automatically when a certificate is configured. You can force signing in Veri*Factu mode with `aeat.sign_xades: true`.

> **Limitation:** despite the `XadesBillingRecordSigner` / `sign_xades` names, the signer currently produces a **plain enveloped XML-DSig** signature (RSA-SHA256, exclusive C14N, X.509 certificate in `KeyInfo`). It does **not** add the XAdES `QualifyingProperties` / `SignedProperties` (signing time, signing certificate digest, policy) that the AEAT requires for No-Veri*Factu records (XAdES Enveloped). Do not rely on it as a legally valid No-Veri*Factu signature yet; plug your own `BillingRecordSignerInterface` implementation if you need XAdES today.

### XSD validation

Official AEAT schemas ship under `src/Resources/schemas/aeat/`. Disable with `aeat.validate_xsd: false` only for debugging.

### AEAT SOAP timeout (REQ-RUNTIME-001)

`aeat.timeout` (seconds, range 5–120, default **30**) is applied to both `CURLOPT_TIMEOUT` and `CURLOPT_CONNECTTIMEOUT` in `CurlSoapTransport`.

In FrankenPHP demos, keep:

**operation (`aeat.timeout`) &lt; PHP `max_execution_time` &lt; Caddy `servers.timeouts.write`**

See [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md#timeouts-aeat-soap--frankenphp) for the demo values (30 / 45 / 60).

## Translations

Validation messages use the `NowoVerifactuBundle` translation domain under `src/Resources/translations/`.

Override any key from your application:

```yaml
# translations/NowoVerifactuBundle.es.yaml
validation:
  issuer_nif:
    invalid: 'NIF del emisor no válido (texto personalizado).'
```

Supported locales: `en`, `es`, `it`, `fr`, `pt`, `de`, `nl`.
