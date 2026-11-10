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
    providers:
        issuer: null             # service id of your IssuerProviderInterface (null = the issuer block above)
        software_identity: null  # service id of your SoftwareIdentityProviderInterface (null = software + installation)
        aeat_environment: null   # service id of your AeatEnvironmentProviderInterface (null = aeat.environment)
        certificate: null        # service id of your CertificateProviderInterface, per issuer NIF (null = aeat.certificate_*)
    validation:
        strict: false            # also reject records without recipients (F1, F3, R1 – R4) or TipoRectificativa (R1 – R5)
    xml:
        default_regime_key: null # ClaveRegimen for tax lines without one, e.g. '01' (null = omitted, as in 1.5)
    hash_chain:
        storage: memory          # memory | doctrine
        repository: null         # custom service id (optional)
        table_prefix: verifactu_ # deprecated, no effect: use doctrine.table_prefix
    record_store:
        storage: none            # none | memory | doctrine
        service: null            # custom BillingRecordStoreInterface service id (optional, wins over storage)
    doctrine:
        table_prefix: ''         # prepended to the bundle table names (REQ-ORM-002)
        mapping: attribute       # attribute | xml (bundle entities from Resources/config/doctrine/*.orm.xml)
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

**Production requires persistent storage.** With `aeat.environment: production` and an AEAT client (a `certificate_path` or a custom `submission_client`), the container refuses to compile while `hash_chain.storage` is `memory` and no `hash_chain.repository` is set: the memory storage is request-scoped, so every request would start a new chain.

**No flush, and your transaction.** The Doctrine repository and record store never call `flush()`, `clear()` or `resetManager()` on an EntityManager: they write with DBAL on the connection of the manager that maps the bundle entities and read with array-hydrated DQL, so your pending entities are never written by the bundle and nothing stays managed. `withChainLock()` opens a transaction on that connection; when you already opened one, it nests (savepoint or nesting level), so the chain head and the stored record commit or roll back with your invoice. Without your transaction it commits on its own. Store the invoice in the same transaction as the chain head (`EntityManager::wrapInTransaction()`), or be ready to re-issue the record: a chain head without a stored invoice breaks the next record's `previousHash` audit trail. Inside your transaction the chain row lock is held until it ends, including the AEAT call and the event listeners of `process()`.

**Concurrency.** `BillingRecordProcessor` reads the chain head, hashes the new record and stores the new head. When the repository implements `LockableHashChainRepositoryInterface`, that read-then-write runs inside `withChainLock()`, so concurrent requests for the same issuer NIF cannot chain onto the same previous record. The Doctrine repository opens a transaction, makes sure the issuer row exists (insert-if-absent of an empty head: `ON CONFLICT DO NOTHING` on PostgreSQL / SQLite, `ON DUPLICATE KEY UPDATE` on MySQL / MariaDB, so the first record of an issuer is serialised like the others), then reads it **once** with a pessimistic write lock (`SELECT … FOR UPDATE`) and hands that head to the processor. A locking read returns the latest committed head even inside a host transaction whose snapshot is older (MySQL / MariaDB REPEATABLE READ), and the record store takes its position from locking reads too. Multi-process tests (`tests/Concurrency`, CI job "Chain concurrency") check MySQL 8.4, MariaDB 11.4 and PostgreSQL 17, inside and outside a host transaction, with and without the record store, one issuer and 8 processes racing on the first record of 30 new issuers, plus a forced deadlock inside a host transaction on a kept-alive connection: no fork, a contiguous log, every record reachable from the head. Under contention the database may still report a deadlock or a lock wait timeout: nothing of the record is written. **How to retry depends on your transaction.** Without a transaction of yours, retry `process()` (DBAL `RetryableException`). Inside your transaction, roll back and retry the **whole unit of work** (your transaction, your writes and `process()`), never `process()` alone: on MySQL / MariaDB a deadlock makes InnoDB roll back your whole transaction, so the bundle closes the connection (DBAL's nesting level back to 0; the next statement reconnects) and throws `Exception\ChainTransactionLostException` (its previous exception is the database error); after it, your transaction no longer exists, so do not call `rollBack()` again. On PostgreSQL a deadlock (SQLSTATE 40P01) is a DBAL `RetryableException`, but a `lock_timeout` you configured (55P03) surfaces as a plain `DriverException`: treat it as retryable too. Custom repositories should implement the interface and pass the head they read under the lock to the operation (database row lock, Symfony Lock, etc.): a repository that calls `$operation()` without it makes the processor re-read the head with a plain `getLastState()`, which can return a stale head and **fork the chain on MySQL / MariaDB (REPEATABLE READ) inside your transaction**.

**SQLite is single-writer.** It has no row locks: its database lock serialises writers, so a concurrent writer fails with "database is locked" (it never forks). Use it for development and tests; in production use MySQL, MariaDB or PostgreSQL. If you must run several SQLite writers, set a busy timeout (PDO `timeout` / `PRAGMA busy_timeout`) and retry.

**Table prefix.** `doctrine.table_prefix` (default `''`; letters, digits and underscores only) is prepended to every table the bundle owns, to its unique constraint and to any many-to-many join table of bundle entities, so several applications can share one database. It is applied by a Doctrine `loadClassMetadata` listener scoped to the `Nowo\VerifactuBundle\Entity\` namespace (host entities are never renamed); the bundle reads through DQL and writes with DBAL using the table and column names of the class metadata, so every statement follows the prefixed name.

| `doctrine.table_prefix` | Table | Unique constraint |
| --- | --- | --- |
| `''` (default) | `verifactu_hash_chain` | `uniq_verifactu_hash_chain_issuer` |
| `app_` | `app_verifactu_hash_chain` | `app_uniq_verifactu_hash_chain_issuer` |

The record store table follows the same rule (`verifactu_billing_record`, `uniq_verifactu_billing_record_hash`).

Changing the prefix on an existing database requires renaming the table (see [UPGRADING.md](UPGRADING.md#unreleased)).

**Deprecated / reserved options.** `hash_chain.table_prefix` is deprecated and has no effect (use `doctrine.table_prefix`). `aeat_spec_version` is accepted for forward compatibility but has no effect today.

The default `memory` storage is **request-scoped** (cleared at the start of every main request, also in FrankenPHP worker mode without `services_resetter`): every request starts a new chain. It is meant for development and tests; with `kernel.debug: false` it logs a warning on every stored record. Full worker-mode notes: [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).

### Record store and chain verification

`record_store.storage: doctrine` keeps every chained record in `verifactu_billing_record` (issuer NIF, position, the
hashed fields, previous hash, hash, XML, signed XML, submission status and AEAT reference), appended inside the chain
lock so the head and the log never diverge. `memory` is request-scoped (development and tests). `record_store.service`
plugs your own `Repository\BillingRecordStoreInterface`. With a store, `Service\ChainVerifier` and
`nowo:verifactu:verify-chain` are available ([COMMANDS](COMMANDS.md#verify-a-stored-chain)); without one they are not
registered.

### Doctrine mapping (standard and DDD hosts)

The bundle entities (`BillingRecordHashChain`, `BillingRecordEntry`) are bundle-owned and referenced by IDs (issuer NIF,
position, hash), never by a relation to your entities. `doctrine.mapping: attribute` (default) maps them with PHP
attributes; `doctrine.mapping: xml` maps them from `src/Resources/config/doctrine/*.orm.xml`, for hosts whose ORM
configuration only loads XML mappings.

### Runtime issuer and software identity

By default the issuer and the `SistemaInformatico` block come from `issuer`, `software` and `installation`. To keep them
in your database (and edit them without a cache clear), implement `Provider\IssuerProviderInterface` and/or
`Provider\SoftwareIdentityProviderInterface` and set `providers.issuer` / `providers.software_identity` to the service
ids. They are called on every `process()` and every SOAP submission, never cached by the bundle. `IdSistemaInformatico`
is up to two characters and `NumeroInstalacion` free text (for example `SB` and `1`).

### Runtime AEAT environment and certificate

`providers.aeat_environment` points at your `Provider\AeatEnvironmentProviderInterface` ("sandbox" or "production"),
asked by the SOAP client, the QR Twig functions and `nowo:verifactu:submit-sandbox` on every call.
`providers.certificate` points at your `Certificate\CertificateProviderInterface`: `getCertificate($issuerNif)` returns
`Certificate::fromPath($path, $password)` or `Certificate::fromContents($pkcs12OrPemBytes, $password, 'p12'|'pem')`
(for example decrypted from your database), or null when the issuer has none. The SOAP client and the XAdES signer ask
it on every call; contents are written to a private (0600) temporary file only for the cURL call and deleted right
after, also on failure. `Certificate` hides its contents and password from dumps. A certificate provider enables the
SOAP client and the signer like a `certificate_path` does. With an environment provider, the container refuses the
in-memory hash chain together with an AEAT client (the provider may switch to production at runtime).

### Record completeness (AEAT validations)

Records built as in 1.5 produce the same XML. The 1.6 fields (recipients, rectification, operation date, ClaveRegimen,
CalificacionOperacion / OperacionExenta) are validated when set ([USAGE](USAGE.md#complete-records-recipients-rectifications-tax-qualification)).
Two AEAT rules reject records that 1.5 could not complete: invoices F1, F3, R1 – R4 need at least one recipient, and
R1 – R5 need TipoRectificativa (AEAT "Validaciones y errores" v1.2.2, §3.1.3 items 3 and 13). Set
`validation.strict: true` to refuse such records before they are chained (recommended). ClaveRegimen is optional in the
schema but the AEAT warns when an IVA line has none and rejects it from 2027-01-01 (§15.6): pass it per line or set
`xml.default_regime_key` (for example `'01'`, general regime).

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
