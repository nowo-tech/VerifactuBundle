# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[1.6.0] - 2026-11-11](#160---2026-11-11)
- [[1.5.0] - 2026-11-10](#150---2026-11-10)
- [[1.4.1] - 2026-11-10](#141---2026-11-10)
- [[1.4.0] - 2026-10-30](#140---2026-10-30)
- [[1.3.0] - 2026-10-30](#130---2026-10-30)
- [[1.2.0] - 2026-10-13](#120---2026-10-13)
- [[1.1.0] - 2026-10-13](#110---2026-10-13)
- [[1.0.12] - 2026-10-13](#1012---2026-10-13)
- [[1.0.11] - 2026-10-09](#1011---2026-10-09)
- [[1.0.10] - 2026-09-27](#1010---2026-09-27)
- [[1.0.9] - 2026-09-25](#109---2026-09-25)
- [[1.0.8] - 2026-09-03](#108---2026-09-03)
- [[1.0.7] - 2026-08-24](#107---2026-08-24)
- [[1.0.6] - 2026-08-19](#106---2026-08-19)
- [[1.0.5] - 2026-08-18](#105---2026-08-18)
- [[1.0.4] - 2026-08-04](#104---2026-08-04)
- [[1.0.3] - 2026-08-03](#103---2026-08-03)
- [[1.0.2] - 2026-07-31](#102---2026-07-31)
- [[1.0.1] - 2026-07-31](#101---2026-07-31)
- [[1.0.0] - 2026-07-18](#100---2026-07-18)

## [Unreleased]

Batch submission to the AEAT (V7) for Symfony Beacon spec 226. Standard hosts need no change; with the Doctrine record
store, add the new submission columns. See [UPGRADING](UPGRADING.md#unreleased).

### Added

- **Batch submission (V7).** `Service\BillingRecordBatchSubmitter::submitPending($nif)` sends the stored records of an
  issuer in chain order, up to 1000 per SuministroLR request (AEAT *Servicios web* v1.0.3 p. 10; `SuministroLR.xsd`
  `maxOccurs="1000"`; `submission.batch_size`), one AEAT environment and one record per invoice id and operation per
  request. One batch in flight per issuer: the batch is claimed inside the issuer's chain lock, sent outside any
  transaction, and its answers are committed in one transaction.
- **Per-record answers.** `Client\AeatResponseParser` reads `RespuestaRegFactuSistemaFacturacion` (EstadoEnvio, CSV,
  TiempoEsperaEnvio, every RespuestaLinea with CodigoErrorRegistro / DescripcionErrorRegistro / RegistroDuplicado; no
  DTD in any encoding, no network; only `Correcto` / `ParcialmenteCorrecto` / `Incorrecto` are an answer). Records
  become `accepted`, `accepted_errors` or `rejected`; a record without a line goes back to `pending`; a request without
  an answer (transport error, HTTP 502 / 503 / 504) puts the batch back to `pending`.
- **Requests refused as a whole never fail healthy records, and a bad record never blocks an issuer for good.**
  Only a SOAP Fault with faultcode `Client` counts (*Servicios web* v1.0.3 §5.1); `Server` faults, HTML / empty bodies, HTTP 401 / 403 / 404 /
  405 / 407 / 408 / 429 / 500 pages / 502 – 504 and transport errors leave the records pending and, from the second
  request in a row without any answer, dispatch `VerifactuEvents::ISSUER_SUBMISSION_STALLED` (at most once per
  `submission.pause_seconds`). The fault's
  `Codigo` decides (`Submission\AeatFaultClassifier`, AEAT list of message-level errors, configurable with
  `submission.issuer_fault_codes` / `record_fault_codes`, 4-digit codes; a record code that is also an issuer code is
  refused at container build): an issuer code (header, certificate, authorisation, e.g.
  4104) pauses the issuer at once; a record code (e.g. 4102) halves the batch until the record at fault goes alone and
  marks it `failed` after `submission.max_attempts` refusals; any other code holds that record as a suspect (`held`)
  and sends other records alone as probes (the next ones, then the newest): an answered probe turns the suspects
  `confirming` and each is sent alone once more (failed only if refused again); the suspects and the newest record all
  refused pause the issuer, keeping the suspects, and the refused newest record is held too, so the next probe goes to
  the record before it. Runs of consecutive bad records are failed one by one; when the newest records are bad too,
  the healthy records wait one pause per bad record at the tail (a new invoice or `--fail` ends it sooner), never
  until someone acts. With nothing else waiting, the held suspects go alone one per pause, rotating (fewest refusals,
  newest on a tie), and an answered one confirms the others, so a bad record never blocks the held healthy ones after
  a long outage. `VerifactuEvents::RECORD_SUBMISSION_FAILED` (`Event\RecordSubmissionFailedEvent`) and
  `VerifactuEvents::ISSUER_SUBMISSION_PAUSED` (`Event\IssuerSubmissionPausedEvent`, reason `issuer_refused` or
  `awaiting_probe`, held positions, AEAT code) are dispatched after the commit; during a pause one Messenger message
  per issuer stays alive and is dispatched again once at its end, the others are dropped (`wake|<NIF>` key; the
  rescheduled message carries its token, so a superseded wake-up message is dropped too). `nowo:verifactu:submit-pending --list` / `--fail=<position>` / `--retry-failed` /
  `--resolve` (`recordsToReview()`, `failRecord()`, `requeueFailed()`, `resolveUnconfirmed()`) let an operator see and
  act on every record that needs a person; `--retry-failed` puts back only what the submitter (or `--fail`) failed.
  `--fail` and `--retry-failed` run under the issuer's chain lock, like a claim, so they never interleave with a batch
  being claimed; `--csv` is checked against the stored column (at most 120 printable characters).
- **Retries and duplicates.** Resends carry the same stored XML. An attempt is counted right before the request is
  transmitted (`BatchAeatSubmissionClientInterface::submitBatch($records, $beforeTransmit)`), never at the claim. An
  AEAT duplicate (`RegistroDuplicado`, no Huella) becomes a registered state only when a
  `Submission\DuplicateConfirmationInterface` (`submission.duplicate_confirmation`, e.g. an AEAT query comparing the
  Huella) confirms it, by record type (an annulled alta and an anulación answered `Anulada` are registered); otherwise a
  record sent before is stored as `unconfirmed` (never reported as accepted, not sent again) and a record never sent
  before is `rejected`. Stale claims (`submission.claim_timeout`) are claimed again and can no longer store an answer.
- **AEAT flow control** (Orden HAC/1177/2024 art. 16.2 c; *Servicios web* §6.4.4.1): the next submission waits
  `TiempoEsperaEnvio` (default 60 s, `submission.default_wait`) unless it carries the record-design limit of 1000
  records (a smaller `batch_size` never skips the wait). `Submission\FlowControlInterface`, default `CacheFlowControl`
  over `submission.flow_control_cache_pool` (default `cache.app`; must be shared by every node); scope per issuer (one
  SIF installation per issuer, AEAT FAQ v1.3 §4) or `global` (check and slot reservation under one database lock for
  every issuer).
- **After-commit events.** `VerifactuEvents::AFTER_RECORD_SUBMISSION` (`Event\RecordSubmittedEvent`), once per answered
  record after its answer is committed; the submitter refuses to run inside a transaction.
- **`BillingRecordProcessor::processBatch()`**: several records of one issuer chained in order in one chain lock, all or
  nothing (the head never points at a record that was not stored).
- **Corrections:** `BillingRecord` takes `correction` (Subsanacion), `previousRejection` (RechazoPrevio, X = Alta after
  an AEAT rejection) and `withoutPreviousRecord` (SinRegistroPrevio), written in the AEAT order and validated
  (`validation.correction_flags.invalid`, 7 locales; RechazoPrevio S or X on an alta needs Subsanacion S, AEAT
  Validaciones v1.2.2 §3.1.3 item 2).
- **Async and CLI:** `Messenger\SubmitPendingBillingRecords` + handler (reschedules itself with a `DelayStamp`; backs
  off and stops after `MAX_FAILED_RUNS` runs without any AEAT answer; only with symfony/messenger) and
  `nowo:verifactu:submit-pending [--issuer] [--retry-failed]`.
- **Ports for DDD hosts:** `Repository\SubmissionQueueInterface` (implemented by the Doctrine and in-memory stores),
  `Client\BatchAeatSubmissionClientInterface` (SOAP and null clients; `submission.client`), `FlowControlInterface`,
  `DuplicateConfirmationInterface`.
- **Records written before 1.6** (no AEAT environment) are never sent by the batch submitter: they could be sandbox
  records while the current environment is production (stamp them explicitly, see UPGRADING).
- **Network guard for tests:** `VERIFACTU_FORBID_NETWORK=1` (set by `phpunit.xml.dist` and CI) makes
  `CurlSoapTransport` refuse every request; only an owner-run `nowo:verifactu:submit-sandbox --submit` may reach the
  AEAT.
- **Tests:** batches of 1, 3 and 1000 records and corrections validated against the official `SuministroLR.xsd`; the
  response fixture against `RespuestaSuministro.xsd`; multi-process submission tests (submitters racing each other
  and the chaining processes, one lost answer) on MySQL, MariaDB and PostgreSQL; the audit LJ regressions of the
  queue (attempts at transmission, unconfirmed duplicates, split and failed batches, the global lock, records without
  environment) on SQLite, MySQL, MariaDB and PostgreSQL.

### Changed

- **Record log columns:** `verifactu_billing_record` gets `submission_attempts`, `submission_claim`,
  `submission_claimed_at`, `submission_error_code`, `submission_error`, `submission_failures`, `submitted_at` and the
  index `idx_verifactu_billing_record_submission`. `StoredBillingRecord` exposes the attempts, the submission failures
  and the last error.
- `psr/cache` is a dependency (flow control state).
- **SECURITY.md** covers 1.7: the record store table, the batch submitter, the Messenger message, the flow control
  pool, `submit-pending` and its manual options (`--fail`, `--resolve`, `--retry-failed`), and what each command writes.
- **Configuration:** `submission.issuer_fault_codes` / `record_fault_codes` take 4-digit codes only, and a record code
  that is also an issuer code (configured or default) is refused.

## [1.6.0] - 2026-11-11

Complete AEAT records (recipients, rectification, operation date, regime keys), runtime AEAT environment and per-issuer certificate providers with in-memory TLS keys, one hash chain per environment, issuer enforcement and reset-chain. See [UPGRADING](https://github.com/nowo-tech/VerifactuBundle/blob/v1.6.0/docs/UPGRADING.md#to-160).

Complete AEAT records (V4) and the rest of the runtime providers (V5) for Symfony Beacon spec 226. A record built as in
1.5 produces the same XML. See [UPGRADING](UPGRADING.md#unreleased).

### Added

- **Recipients, rectification and operation date (V4).** `BillingRecord` (and `InvoiceDraft`) take, as optional
  trailing arguments: `recipients` (`Model\Recipient::withNif()` / `withForeignId()`: Destinatarios with NIF or IDOtro),
  `rectificationType` (TipoRectificativa S / I), `rectifiedInvoices` (FacturasRectificadas), `substitutedInvoices`
  (FacturasSustituidas, F3), `rectificationAmount` (`Model\RectificationAmount`: ImporteRectificacion) and
  `operationDate` (FechaOperacion). The generator writes them in the order of the AEAT `SuministroInformacion.xsd`
  (`RegistroFacturacionAltaType`).
- **Tax qualification (V4).** `TaxBreakdown` takes `regimeKey` (ClaveRegimen), `qualification` (CalificacionOperacion
  S1 / S2 / N1 / N2; S1 when null, as before) and `exemption` (OperacionExenta E1 – E8). Not subject (N1 / N2) and
  exempt lines are written without TipoImpositivo and CuotaRepercutida (AEAT validations v1.2.2, §15.4 / §15.5).
  `xml.default_regime_key` fills lines without a ClaveRegimen.
- **AEAT business rules for the new fields** (validations v1.2.2, §3.1.3 items 3 – 7, 13, 15), translated in the 7
  locales, and `validation.strict` for the two rules 1.5 records could not meet (recipients on F1 / F3 / R1 – R4,
  TipoRectificativa on R1 – R5).
- **Runtime AEAT environment and certificate (V5, rest).** `Provider\AeatEnvironmentProviderInterface` and
  `Certificate\CertificateProviderInterface` (per issuer NIF; `Certificate::fromPath()` or `fromContents()` for
  PKCS#12 / PEM bytes kept in a database), resolved on every call by the SOAP client, the XAdES signer, the QR Twig
  functions and `nowo:verifactu:submit-sandbox`. The defaults read today's configuration. Contents go to a 0600
  temporary file for the cURL call only. `CertificateLoader::loadCertificate()` loads either form.
- **Tests:** every new element validated against the official AEAT XSD (F1 domestic / reverse charge / not subject /
  exempt / several lines, R1 by differences and by substitution, R5, F3).
- **One AEAT environment per chain.** Records and chain heads carry the environment they were chained for (new nullable
  `environment` column on `verifactu_hash_chain` and `verifactu_billing_record`; null = the configured
  `aeat.environment`). The processor refuses to chain a record of one environment onto a head of the other
  (`ChainEnvironmentMismatchException`); the SOAP client and the QR use the environment stored on the record.
- **`nowo:verifactu:reset-chain --issuer=X [--force]`** removes the chain head of an issuer so the next record starts a
  new chain, e.g. when moving from the sandbox to production. Stored records are kept.
- **`nowo:verifactu:verify-chain --environment=sandbox|production`**: the verifier checks one environment (default:
  the one of the current head), skips the records of the environment used before a reset and reports a record of
  another environment inside the chain.
- **`providers.issuer_enforced`** (default true): the opt-out of the issuer check below.

### Changed

- **Issuer provider is enforced (security).** With `providers.issuer` set, a record whose issuer NIF differs from the
  provider's NIF is refused (`validation.issuer_nif.provider_mismatch`): it is never chained, signed nor submitted, so a
  caller-supplied NIF can never select another issuer's certificate. Before, such a record kept its own NIF. Multi-NIF
  hosts should make the provider return the issuer of the current tenant; `providers.issuer_enforced: false` restores
  the 1.5 behaviour. The certificate provider receives the NIF upper-cased and trimmed.
- **AEAT validations v1.2.2:** a foreign recipient with country ES takes IDType 03 or 07, and IDType 07 only with ES
  (§3.1.3 item 13); ClaveRegimen only on IVA, IPSI (keys 01, 08, 11, 18, 19, 20) and IGIC lines, and the default
  regime key is only applied to them (§15.6); exemptions E2 / E3 are refused under the general regime of IVA or IGIC,
  also when 01 comes from `xml.default_regime_key` (§15.5).
- **An unknown environment is refused** (`InvalidAeatEnvironmentException`), never routed to a default; the
  environment provider returns `Model\AeatEnvironment`. The production / in-memory chain check also names
  `providers.aeat_environment` when that is what triggered it.
- **Temporary certificate file (worker-safe).** The fallback for runtimes without `CURLOPT_SSLCERT_BLOB` no longer
  changes the process umask nor keeps static state: a fresh 0700 directory, the file created exclusively and set to
  0600 before the secret is written, removed in `finally`, on destruction and, after a fatal error, by one shutdown
  function per process over a registry of the files in flight (emptied as each file is deleted, so memory stays flat
  in worker mode).

- **Docs:** `ChainTransactionLostException` and UPGRADING say not to run any further statement of the lost unit of work
  on the connection (audit KW-D2-I1).

## [1.5.0] - 2026-11-10

Record store with attribute/XML mapping and verify-chain, runtime issuer and software-identity providers, one canonical amount in XML/hash/QR, Madrid timestamps, no host EM flush, and a concurrency-safe per-issuer chain lock. See [UPGRADING](https://github.com/nowo-tech/VerifactuBundle/blob/v1.5.0/docs/UPGRADING.md#to-150).

The XML, the fingerprint and the QR carry one canonical amount string; the bundle never flushes the host
EntityManager; an optional record store with a chain verifier; runtime issuer / software-identity providers; a Madrid
time helper. See [UPGRADING](UPGRADING.md#unreleased).

### Fixed

- **XML amount = fingerprint amount (V1).** Up to 1.4.1 the Huella hashed the amount without insignificant zeros
  (`12.30` → `12.3`, `0.00` → `0`) while `<CuotaTotal>` / `<ImporteTotal>` carried the caller's string unchanged. The
  AEAT hash specification v0.1.2 (27/08/2024, §3) requires the hashed values to hold the same information as the XML
  field and treats one or two decimals as equivalent, so `12.30` vs `12.3` was within the rule, but whole amounts
  (`0.00` → `0`, `29.00` → `29`) were hashed with zero decimals, outside the "una o dos posiciones" wording. Now
  `Model\Amount::canonical()` gives one string, always two decimals (`12.30`, `0.00`, `-5.00`), used by
  `HashChainGenerator`, `BillingRecordXmlGenerator`, `QrUrlBuilder` (`importe`) and the record returned by
  `process()`. `HashChainGenerator::verifyHash()` accepts both the canonical hash and the hash 1.0 – 1.4.1 computed, so
  stored records still verify (1.1 – 1.4.1; 1.0.x used a float normaliser). A Huella that does not match the AEAT's
  calculation is never rejected: the record is "Aceptado con errores" (AEAT validations v1.2.2, §3.1.3 item 23), and
  §4.3.1 lists it among the admissible errors that **must be corrected ("subsanados")**.
- **The per-issuer chain lock could fork the chain (affected 1.1.0 – 1.4.1 and 1.5 before release).** The lock read
  the head with `SELECT … FOR UPDATE`, then the processor read it again with a plain SELECT. On MySQL / MariaDB
  (REPEATABLE READ) inside a host transaction that had already read something, that plain read returned a stale head:
  two records chained onto the same predecessor and the chain forked silently. On PostgreSQL (READ COMMITTED) two
  first records of a new issuer both chained onto an empty head, and the second INSERT turned into an UPDATE. Now
  `withChainLock()` creates the issuer row first (insert-if-absent), reads it once with the locking read and passes
  that head to the operation (`LockableHashChainRepositoryInterface` documents it; a custom repository that passes
  nothing keeps the old behaviour), `storeLastState()` only updates the locked row, and the record store takes its
  position from locking reads. A new CI job runs several PHP processes against MySQL 8.4, MariaDB 11.4 and
  PostgreSQL 17 (`tests/Concurrency`), including 8 processes racing on the first record of 30 new issuers.
- **A MySQL / MariaDB deadlock inside the host's transaction left the connection writing without the lock (affected
  1.1.0 – 1.4.1 and 1.5 before release).** InnoDB rolls back the victim's whole transaction, so the savepoint of the
  chain lock no longer exists, its rollback failed and DBAL's nesting level never went back to 0: on a kept-alive
  connection (FrankenPHP worker mode) every later record ran in autocommit while DBAL believed it was in a
  transaction, without the row lock (audit: most chains corrupted under contention). `withChainLock()` now closes the
  connection in that case and throws `Exception\ChainTransactionLostException`: roll back nothing more and retry the
  whole unit of work. Tested with a forced deadlock on a kept-alive connection and in the concurrency suite.
- If several processes created records for the same issuer concurrently with 1.1 – 1.4.1, check the chain (two
  records with the same previous hash, or a head that does not reach every record).
- **The bundle never flushes or resets the host's EntityManager (V2, KS-V-I4, architecture rule 6).**
  `DoctrineHashChainRepository::storeLastState()` called `flush()` on the manager of the chain entity, usually the
  host's default one, so the host's pending entities were written too (`process()` is meant to run before the host
  stores its invoice). The chain head is now written with DBAL (`Doctrine\DbalRowWriter`, table and columns from the
  class metadata, so `doctrine.table_prefix` still applies) and read with array-hydrated DQL: nothing enters the
  identity map. The repository no longer resets a closed manager (it was the host's); it only uses its connection, so
  it keeps working after the host's manager was closed. `withChainLock()` nests in the host's transaction when one is
  open (head and record commit or roll back with the host's data).
- **`process()` docblock (KS-V-I5)** lists `AFTER_BILLING_RECORD_GENERATED` with the other contained listeners.

### Compliance

- **Users of 1.0 – 1.4.1 who submitted records with whole amounts or a `0.00` tax** (`29.00`, `0.00`, hashed as `29`,
  `0`): the AEAT hash specification v0.1.2 states that one or two decimals are equal, but it does not say so explicitly
  for zero decimals. If the AEAT computed those fingerprints differently, the records were "Aceptado con errores"
  (`AceptadoConErrores`, reported by the bundle as `success: false`). Review those AEAT responses for hash errors and
  correct the records (validations v1.2.2 §4.3.1). A sandbox submission of such a record in the 1.4.1 form settles
  the question for your installation.

### Added

- **Record store (V6):** `nowo_verifactu.record_store.storage: none|memory|doctrine` or `record_store.service`
  (`Repository\BillingRecordStoreInterface`). Every chained record (fields, hash, previous hash, XML, signed XML) is
  appended inside the chain lock, in the same transaction as the chain head; `process(..., submitToAeat: true)` then
  records `accepted` / `failed` and the AEAT reference (a store failure there is logged and returned in
  `store_errors`, never thrown). Doctrine table `verifactu_billing_record` (primary key issuer NIF + position, unique
  issuer NIF + hash), written with DBAL, no flush.
- **Chain verifier:** `Service\ChainVerifier` and `nowo:verifactu:verify-chain --issuer=NIF [--anchor=HASH]`
  (registered only with a record store): consecutive positions, fingerprint, previous-hash link, XML `Huella` /
  `CuotaTotal` / `ImporteTotal` equal to the hashed values, chain head on the last record. A log enabled on an issuer
  that already had a chain starts from the old head: it is accepted and reported as `continuesFrom` (`--anchor`
  requires that exact head). The Doctrine store reads the log in pages of 500 records.
- **Generation time inside the lock:** a record passed with an empty `generatedAt` is stamped with
  `GenerationTimestamp::now()` (the Symfony `clock` service when available) inside the chain lock, so the generation
  times follow the chain order. A record that brings its own value keeps it.
- **XML mapping for DDD hosts:** `nowo_verifactu.doctrine.mapping: attribute|xml` (default `attribute`). With `xml`
  the bundle entities are mapped from `src/Resources/config/doctrine/*.orm.xml`.
- **Runtime providers (V5, issuer and software identity):** `Provider\IssuerProviderInterface` (NIF, name) and
  `Provider\SoftwareIdentityProviderInterface` (producer name and NIF, software name, IdSistemaInformatico, version,
  NumeroInstalacion, solo-VERI*FACTU), resolved on every `process()` call and every SOAP submission. Defaults
  `ConfigIssuerProvider` / `ConfigSoftwareIdentityProvider` read today's configuration; point
  `nowo_verifactu.providers.issuer` / `providers.software_identity` at your service to read them from your database.
  The issuer is resolved once per `process()` call: a record with an empty issuer NIF or name takes the provider's,
  and the SOAP Cabecera uses the record's name, so the XML and the Cabecera agree.
- **Generation time helper (V8):** `Clock\GenerationTimestamp::now(?ClockInterface)`, `format()` and `issueDate()`
  give `FechaHoraHusoGenRegistro` (`Y-m-d\TH:i:sP`) and `FechaExpedicionFactura` (`d-m-Y`) in Europe/Madrid whatever
  the server time zone (PSR-20, `psr/clock` is now required). `nowo:verifactu:submit-sandbox` uses it for its defaults.
- **Worker mode without kernel reset:** tests run 1,000 records for two issuers in one kernel without reset (flat
  memory, no managed entity, no open transaction, chains kept apart) and change the provider values between two calls.

### Changed

- **QR (V10):** `importe` is the canonical amount, the same string as `ImporteTotal` in the XML and the fingerprint.
- **Docs:** INTEGRATION-NOWO and USAGE use `GenerationTimestamp` instead of `format('c')` in the server time zone.

## [1.4.1] - 2026-11-10

The AEAT submission events are dispatched, and a failing listener after chaining no longer loses or duplicates a fiscal record (failures in `event_errors`). See [UPGRADING](https://github.com/nowo-tech/VerifactuBundle/blob/v1.4.1/docs/UPGRADING.md#to-141).

### Fixed

- **AEAT submission events are dispatched.** `VerifactuEvents::BEFORE_AEAT_SUBMISSION` and `AFTER_AEAT_SUBMISSION` were
  declared (and the README advertised "AEAT submission hooks") but never dispatched. `BillingRecordProcessor::process()`
  with `submitToAeat: true` now dispatches `BeforeAeatSubmissionEvent` right before the AEAT client is called and
  `AfterAeatSubmissionEvent` (record + submission result, `isSuccessful()`) after it returned. Both are informational
  (no veto) and carry a copy of the record, so listeners cannot change what is sent to the AEAT nor what `process()`
  returns. The XML, the hash chain and the AEAT request are unchanged. No certificate or password in the payloads.
- **A failing listener after chaining cannot lose or duplicate a fiscal record.** An exception from an
  `AFTER_BILLING_RECORD_GENERATED`, `BEFORE_AEAT_SUBMISSION` or `AFTER_AEAT_SUBMISSION` listener is caught, with or
  without submission: the record is still submitted (when requested), `process()` still returns the record (and
  `submission`), and the failure goes to the new `$result['event_errors']` key (event name
  + exception) and to the optional logger (`error`, event name and exception class only). Letting it propagate would
  lose the CSV, hash and XML of a record the AEAT accepted (or of a record already chained), and a retry would
  duplicate it or chain onto a record nobody stored. For `AFTER_BILLING_RECORD_GENERATED` this was the case since
  1.0.0. `psr/log` is now declared in `require` (it was already used through Symfony).
  `BillingRecordProcessor` takes an optional `?LoggerInterface $logger` as its last constructor argument (`@?logger`).
- **`AFTER_BILLING_RECORD_GENERATED` carries a copy of the record.** It passed the same `BillingRecord` instance that is
  then submitted and returned, so a listener that set `xml`, `signedXml` or `hash` changed what the AEAT received while
  the stored chain head kept the real hash. Listeners now get `clone $record`.

### Changed

- **Docs:** USAGE lists the personal data in the event payloads (issuer NIF / name, XML, `raw_response`, a certificate
  path in cURL errors) and that the chain row lock is held while listeners run inside a host transaction.

## [1.4.0] - 2026-10-30

SEC-004 hardening: PEM loader fix, submit-sandbox refuses production, production requires persistent hash chain, CIF check digit, strict dates/amounts, explicit cURL TLS policy, dist export-ignore See [UPGRADING](UPGRADING.md#to-140).

Security hardening from the independent SEC-004 review of 1.3.0 (Pass (good) / Low, Appendix BI). See [UPGRADING](UPGRADING.md#unreleased).

### Security

- **L1 — PEM certificates:** `CertificateLoader` returned the *public* key as `private_key` and the whole PEM file (private key block included) as `certificate`, so PEM signing failed and the key travelled with the certificate. It now exports the private key and the X.509 certificate separately, refuses a PEM file without a certificate or with a certificate that does not match the key, and no longer returns the password in the key material.
- **L2 — `nowo:verifactu:submit-sandbox`:** `--submit` outside the sandbox environment is refused unless `--allow-production`, explicit `--nif` / `--numserie` and an interactive confirmation are given (it used to only print a warning).
- **L3 — production chain storage:** the container refuses `aeat.environment: production` with an AEAT client (`certificate_path` or `submission_client`) while `hash_chain.storage` is `memory` and no `hash_chain.repository` is set.
- **L4 — dist archives:** `.gitattributes` `export-ignore` and `composer.json` `archive.exclude` keep `tests/` (throwaway `test.p12`), `demo/`, `scripts/`, `specs/`, `.specify/`, `.cursor/` and tooling out of dist archives.
- `#[\SensitiveParameter]` on the certificate password of `SoapAeatSubmissionClient` and `XadesBillingRecordSigner`; `scripts/aeat-sandbox-submit.php` reads `AEAT_CERT_PASSWORD` and warns when `--cert-password` is used.
- `CurlSoapTransport` sets an explicit TLS policy: HTTPS only, no redirects, `CURLOPT_SSL_VERIFYPEER` / `CURLOPT_SSL_VERIFYHOST = 2`, 10 MiB response cap (`MAX_RESPONSE_BYTES`).
- `twig/twig` floor raised to `^3.19` (patched line).
- Demo: published port bound to `127.0.0.1`; the audit log masks the issuer NIF.

### Fixed

- `SpanishTaxIdValidator` now checks the CIF control character (digit or letter, by entity type); before, any CIF with a valid shape was accepted although the docs claimed a check digit.
- `AeatBusinessRulesValidator` rejects impossible calendar dates (`31-02-2026`) and amounts outside the AEAT format (exponents, more than 2 decimals, more than 12 integer digits).
- XML text values use `ENT_SUBSTITUTE`, so invalid UTF-8 no longer produces an empty element.
- `doctrine.table_prefix` validation regex is anchored with `D` (a trailing newline is rejected).

### Documentation

- SECURITY.md: AI audit record 2026-10-30 (Pass (good) / Low, L1–L4 fixed), PEM file requirements, business rule details, host transaction around `BillingRecordProcessor::process()` and `BillingRecord` mutability. CONFIGURATION.md: production storage requirement and host transaction. COMMANDS.md: `--allow-production`.

## [1.3.0] - 2026-10-30

doctrine.table_prefix (TablePrefixListener), SECURITY.md threat model, SensitiveParameter on certificate passwords, PHP 8.5 curl_close deprecation See [UPGRADING](UPGRADING.md#to-130).

### Added

- **`doctrine.table_prefix` (REQ-ORM-002).** New option `nowo_verifactu.doctrine.table_prefix` (default `''`, letters / digits / underscores only). A Doctrine `loadClassMetadata` listener (`Nowo\VerifactuBundle\Doctrine\TablePrefixListener`) prepends it to the `verifactu_hash_chain` table, its unique constraint and any many-to-many join table of bundle entities (e.g. `app_` yields `app_verifactu_hash_chain`). Host and third-party entities are never touched; the default keeps the historical name.

### Security

- Certificate passwords are `#[\SensitiveParameter]` in `CertificateLoader::load()` and `CurlSoapTransport::send()` (redacted from stack traces).

### Fixed

- `CurlSoapTransport` no longer calls `curl_close()` (no effect since PHP 8.0, deprecated in PHP 8.5).

### Deprecated

- `nowo_verifactu.hash_chain.table_prefix` was never applied; setting it now triggers a deprecation pointing to `doctrine.table_prefix`. It is still accepted and still has no effect.

### Changed

- Developer tooling: Rector targets PHP 8.2, the declared `php` floor (REQ-CS-003, REQ-SF-001).
### Added

- Developer tooling: `make check-no-raw-html-form` fails on raw `<form>` / `<input>` / `<textarea>` / `<select>` in bundle views and demo templates, and runs in `release-check` (REQ-TWIG-005).

### Changed

- Demo: the invoice generator renders through a Symfony Form Type (`App\Form\InvoiceType`; flat field names unchanged, CSRF token id `demo_invoice`) with `form_start` / `form_row` / child loop / `form_end`; the controller validates with `handleRequest` + `isValid()`; the demo now requires `symfony/form` and `symfony/security-csrf` (REQ-TWIG-005).
- Developer tooling: `make release-check` runs the full REQ-MAKE-002 chain in order (`ensure-up` first, then `check-no-cursor-coauthor`, `check-open-prs`, `composer-sync`, `cs-fix`, `cs-check`, `rector-dry`, `phpstan`, `igor`, `test-coverage-100`, demo release-check); `check-open-prs` also fails on conflicted pull requests (REQ-REL-003).
- Dependencies refreshed (Composer and pnpm/npm lock files of the bundle and its demos, campaign 2026-10-19).
- `.php-cs-fixer.dist.php`: `new ParallelConfig(2)` (REQ-CS-001).

### Documentation

- PHPDoc descriptions on every public method in `src/` (REQ-CS-001).
- `docs/SECURITY.md` restructured as a security analysis: scope, attack surface, threat model (threat → mitigation), secrets and cryptography, logging policy, dependency policy, exposure (REQ-SEC-001).
- `docs/CONFIGURATION.md` documents `doctrine.table_prefix` with the resulting table names.
- Documentation: wave-1 REQ compliance — README documentation list order (canonical labels, PSR under Additional documentation), Symfony badge lists 7.4 explicitly and GitHub stars image uses the package name, canonical issue templates, demo README links DEMO-FRANKENPHP, per-variable comments in the demo `.env.example`, CONTRIBUTING Cursor Agent settings, TOCs for long docs, Spec Kit inventory refresh (55/55, LockableHashChainRepositoryInterface).

## [1.2.0] - 2026-10-13

### Fixed

- `TaxBreakdown::$taxType` is now emitted as `<Impuesto>` (first child of `DetalleDesglose`, as required by the AEAT `DetalleType` sequence) when it is not empty. Previously it was ignored, so IGIC (`03`), IPSI (`02`) and other (`05`) lines were sent without their tax type and AEAT assumed IVA. Generated XML with `Impuesto` is XSD-validated against the bundled `SuministroInformacion.xsd` in tests.

### Changed

- Requirements: all `symfony/*` constraints are now `^6.4 || ^7.0 || ^8.0` (was `^6.0 || ...`) so the lowest allowed version is the one tested in CI. README badge and INSTALLATION aligned (PHP requirement in INSTALLATION corrected to 8.2+).

## [1.1.0] - 2026-10-13

### Security

- `nowo:verifactu:submit-sandbox` without `--submit` (or with `--dry-run`) no longer stores the generated record as the new hash chain head, so smoke tests cannot rewrite the real persistent chain. `BillingRecordProcessor::process()` gains a `persistChainState` argument (default `true`).
- AEAT SOAP responses are only reported as successful when the HTTP status is 2xx and `EstadoRegistro` is `Correcto`. A missing `EstadoRegistro` (e.g. HTML error pages) or a non-2xx status is now a failure; namespace-prefixed AEAT responses (`<tikR:EstadoRegistro>`) are parsed and `DescripcionErrorRegistro` is reported.
- The chain head read-then-write is now serialized per issuer NIF: new `LockableHashChainRepositoryInterface`; the Doctrine repository runs it in a transaction with `SELECT … FOR UPDATE`, preventing forked chains under concurrent requests.

### Fixed

- Apps without `doctrine/doctrine-bundle` no longer fail at container build: the extension checks `hash_chain.storage` first and probes `ServiceEntityRepository` instead of autoloading the Doctrine repository. `storage: doctrine` without Doctrine now throws a clear `LogicException`.
- Anulación fingerprints now use `IDEmisorFacturaAnulada` / `NumSerieFacturaAnulada` / `FechaExpedicionFacturaAnulada`, as required by the AEAT hash specification (verified with the official test vector).
- Hash amounts are normalized as strings (insignificant zeros only) instead of through `float`, so large amounts such as `999999999999.99` are no longer altered.
- `Desglose` emits one `DetalleDesglose` per `TaxBreakdown` entry instead of a single line with the first rate and the invoice totals.
- QR URLs use `ValidarQRNoVerifactu` in `no_verifactu` mode (`QrUrlBuilder` takes the configured mode; `buildUrl()` accepts an optional per-call `$mode`).

### Changed

- Documented that `XadesBillingRecordSigner` produces plain enveloped XML-DSig, not full XAdES, and that `hash_chain.table_prefix` and `aeat_spec_version` are reserved options with no effect.

## [1.0.12] - 2026-10-13

### Changed

- Development: `composer.json` pins `config.platform.php` to 8.2.0 so the committed lock stays installable on the minimum PHP; CI overrides the platform per matrix cell.

### Dependencies

- `doctrine/orm` 3.7.4 → 3.7.5 (development lock; #12).

## [1.0.11] - 2026-10-09

### Dependencies

- Dev tooling (Dependabot + lockfile refresh): `igor-php/igor-php` `^0.10.0`, `doctrine/orm` 3.7.3, `phpstan/phpstan` 2.3.1, `phpstan/phpstan-phpunit` 2.1.1, `phpstan/phpstan-symfony` 2.1.0, `nowo-tech/phpstan-frankenphp` 1.2.3, `rector/rector` 2.7.0. The bundle lockfile stays resolved for the PHP 8.2 floor (Symfony 7.4).
- Demo (`demo/symfony8`): Symfony 8.1.8, `doctrine/orm` 3.7.4, `doctrine/dbal` 4.5.0, `twig/twig` 3.30.0; regenerated `config/reference.php`.

## [1.0.10] - 2026-09-27

### Added

- **REQ-CS-008:** `igor-php/igor-php` (require-dev only), root `igor.json`, Composer/`Makefile` `igor` target, and `release-check` wiring for FrankenPHP worker-state audit.

### Changed

- **Worker safety (Igor):** justified `// @igor-ignore` annotations and/or `ResetInterface` / request-scoped fixes so `make igor` passes on package `src/`.

[1.0.10]: https://github.com/nowo-tech/VerifactuBundle/releases/tag/v1.0.10

## [1.0.9] - 2026-09-25

### Fixed

- **`hash_chain.repository`** now also aliases `HashChainRepositoryInterface` (previously only `nowo_verifactu.hash_chain_repository`, so `BillingRecordProcessor` kept using the in-memory storage).
- **FrankenPHP worker mode (no kernel reset between requests):** see `docs/FRANKENPHP-WORKER-AUDIT.md`.
  - `InMemoryHashChainRepository` is request-scoped: new `clear()` method, tagged `kernel.reset`, and called at the start of every main request by the new `EventSubscriber\WorkerStateResetSubscriber` (priority 4096). Workers no longer build per-worker hash chains. When `kernel.debug` is false it logs a warning on every store (the chain is not persistent).
  - `DoctrineHashChainRepository` reads the last state fresh from the database (`Query::HINT_REFRESH`), detaches the entity after each read/write, deletes with a DQL `DELETE`, and resets a closed EntityManager when a flush/query fails before rethrowing.
  - `XsdValidator` restores the previous `libxml_use_internal_errors()` value in `finally`.

[1.0.9]: https://github.com/nowo-tech/VerifactuBundle/releases/tag/v1.0.9

## [1.0.8] - 2026-09-03

### Fixed

- **FrankenPHP / PHPStan:** replace mutable static `$translations` with immutable class constant in unit helper.
- **CI:** drop obsolete PHP 8.1 from the test matrix (composer requires PHP ≥8.2).
- **Demo:** install `pdo_mysql` in FrankenPHP image (demo was HTTP 500: could not find driver).

### Changed

- **Deps (dev):** refresh Composer lockfiles (`xmlseclibs` and demo lock alignment).

[1.0.8]: https://github.com/nowo-tech/VerifactuBundle/releases/tag/v1.0.8

## [1.0.7] - 2026-08-24

### Changed

- Raise minimum PHP to **8.2** and sync README badge (REQ-SF-001).
- **Demos:** MySQL env policy in FrankenPHP stack (REQ-DEMO-011).
- **Makefile:** detect Docker Compose V2 (REQ-MAKE-010).
- **QA:** add `phpstan-frankenphp` extension (REQ-CS-005).
- **README:** FrankenPHP-friendly worker-mode banner (REQ-DOCS-017).
- **Docs:** PHP-FIG PSR evaluation (REQ-CS-007).
- **Style:** PHP CS Fixer alignment.

### Notes

- **No API or configuration changes** for integrators unless noted above.

[1.0.7]: https://github.com/nowo-tech/VerifactuBundle/releases/tag/v1.0.7

## [1.0.6] - 2026-08-19

### Security

- **CI:** run `composer audit --locked` after dependency install (REQ-SEC / P3).

### Fixed

- **CI:** skip `GetFiltersAndFunctionsToAsTwigAttributeRector` in `rector.php` (conflicts with project Rector config).

## [1.0.5] - 2026-08-18

### Changed

- **Demos:** pin `nowo-tech/hot-reload-bundle` to `^1.4` with FrankenPHP Mercure/`hot_reload` (`dev`/`test` only).

[1.0.5]: https://github.com/nowo-tech/VerifactuBundle/releases/tag/v1.0.5

## [1.0.4] - 2026-08-04

### Fixed

- XAdES signing: pass the `DOMDocument` to `XMLSecurityDSig::addReference()` (library PHPDoc / runtime expect the document, which signs `documentElement`).
- PHPStan: drop baseline file; keep `ignoreErrors: []` in `phpstan.neon.dist`.

### Changed

- Demo `.env.example` tweak.

[1.0.4]: https://github.com/nowo-tech/VerifactuBundle/releases/tag/v1.0.4

## [1.0.3] - 2026-08-03

### Changed

- CI: `actions/stale` v11 (Dependabot).
- Rector: skip `ParamAndEnvAttributeRector` so `#[Autowire('%param%')]` stays compatible with Symfony `^6.0`.
- PHP-CS-Fixer: exclude auto-generated `config/reference.php` dumps (Symfony regenerates without `declare(strict_types=1)`).
- Refresh Symfony `reference.php` fixtures (demo + test kernels) and demo `composer.lock`.
- Dev lock refresh on the reference matrix (`composer-sync`).

### Upgrade

```bash
composer require nowo-tech/verifactu-bundle:^1.0.3
```

No public API or config schema changes.

## [1.0.2] - 2026-07-31

### Fixed

- **REQ-GIT-001:** stripped Cursor co-author trailer from `v1.0.0` history (CI `git-hygiene`).
- CI matrix install uses `composer update --with-all-dependencies` so PHP 8.1 / Symfony 8 jobs are not blocked by lock pins (`endroid/qr-code`, `doctrine/doctrine-bundle`).
- `require-dev`: `doctrine/doctrine-bundle` `^2.18 || ^3.2`; `symfony/var-exporter` `^6.4 || ^7.0 || ^8.0` (PHP 8.1 compatible).

### Upgrade

```bash
composer require nowo-tech/verifactu-bundle:^1.0.2
```

No public API changes.

## [1.0.1] - 2026-07-31

### Added

- **REQ-GIT-001:** `.githooks/commit-msg`, `make setup-hooks` / `check-no-cursor-coauthor` / `check-open-prs`, CI `git-hygiene` job, and [`docs/GITHUB_CI.md`](GITHUB_CI.md).
- **REQ-TEST-003 / REQ-TEST-006:** PHP line coverage gate at **100%** (`.scripts/coverage-check-100.php`); CI and `make release-check` enforce it.
- **REQ-RUNTIME-001:** Documented AEAT SOAP timeout hierarchy (operation &lt; PHP &lt; Caddy write); demo Caddy/PHP ini aligned (30s / 45s / 60s).
- Root [`CODE_OF_CONDUCT.md`](../CODE_OF_CONDUCT.md); README badges (stars, coverage 100%).
- Extra unit/integration tests (Doctrine hash-chain fixtures, SOAP transport, processor, sandbox command) for the coverage floor.

### Fixed

- Leftover **SEPA** naming in `.github/FUNDING.yml`, `.github/SECURITY.md`, and Copilot instructions (now Veri*Factu / AEAT).
- Soft `-include` for optional monorepo `update-deps` helpers so standalone GitHub checkouts do not break Make (REQ-MAKE-009).
- Demo Symfony 8: `doctrine/doctrine-bundle` for Symfony 8 (`^2.18 || ^3.2`); image installs `pdo_sqlite` + `gd`; adds `symfony/property-info` / `symfony/validator`; Doctrine ORM config without removed `enable_lazy_ghost_objects`; PHPUnit `KERNEL_CLASS` + test env; default `FRANKENPHP_MODE=classic` for reliable demo/dev; demo `release-check` = `update-bundle` + tests + HTTP verify.

### Changed

- Demo Symfony 8: FrankenPHP production Caddyfile uses worker mode with explicit timeouts; `release-check` on demos runs `update-bundle` + tests.
- Maintainer docs (CONFIGURATION, DEMO-FRANKENPHP, SECURITY SEC-002/004, RELEASE, SPEC-DRIVEN-DEVELOPMENT) aligned with the above.

### Upgrade

```bash
composer require nowo-tech/verifactu-bundle:^1.0.1
```

No public API or config schema breaks. If you host under FrankenPHP and raise `aeat.timeout`, raise PHP `max_execution_time` and Caddy write timeouts in the same step — see [UPGRADING.md](UPGRADING.md).

## [1.0.0] - 2026-07-18

### Added

- **Initial stable release** of `nowo-tech/verifactu-bundle` for Spanish Veri*Factu compliance (RD 1007/2023).
- **Hash chain (SHA-256)**: AEAT-compliant fingerprint calculation and encadenamiento via `HashChainGenerator` and `HashChainRepositoryInterface` (in-memory and Doctrine backends).
- **Billing record XML**: Registro de facturación generation (Alta / Anulación) with official AEAT XSD schemas shipped in the bundle.
- **QR codes**: ISO/IEC 18004 QR with AEAT verification URL and mandatory legend; Twig helpers `verifactu_qr_data_uri`, `verifactu_qr_url`, `verifactu_legend`.
- **Business rules validation**: NIF/CIF/NIE, dates, amounts, invoice types (`AeatBusinessRulesValidator`, `SpanishTaxIdValidator`).
- **Symfony events**: Before/after billing record generation and AEAT submission hooks.
- **Console commands**: `nowo:verifactu:validate-record`, `nowo:verifactu:verify-hash`, `nowo:verifactu:submit-sandbox`.
- **AEAT SOAP client**: mTLS submission to sandbox/production with client certificate (`SoapAeatSubmissionClient`).
- **XAdES signing**: No-Veri*Factu modalidad with PKCS#12/PEM certificates (`XadesBillingRecordSigner`).
- **Nowo integration**: `InvoiceDraft` + `InvoiceToBillingRecordMapper` for ERP adoption.
- **Modes**: Configuration-driven Veri*Factu and No-Veri*Factu behaviour (`nowo_verifactu.mode`).
- **i18n**: Validation messages in 7 locales (`en`, `es`, `it`, `fr`, `pt`, `de`, `nl`) under domain `NowoVerifactuBundle`.
- **Demo**: Symfony 8 + FrankenPHP demo under `demo/symfony8`.
- **Tooling**: PHPUnit (~98% coverage), PHPStan, PHP-CS-Fixer, Rector, GitHub Actions CI, and `make release-check`.

### Documentation

- README, INSTALLATION, CONFIGURATION, USAGE, SANDBOX, INTEGRATION-NOWO, SECURITY, CONTRIBUTING, SPEC-DRIVEN-DEVELOPMENT, SPEC-KIT, RELEASE, UPGRADING.

For first-time install steps, see [UPGRADING.md](UPGRADING.md) and [INSTALLATION.md](INSTALLATION.md).

[Unreleased]: https://github.com/nowo-tech/VerifactuBundle/compare/v1.6.0...HEAD
[1.6.0]: https://github.com/nowo-tech/VerifactuBundle/compare/v1.5.0...v1.6.0
[1.5.0]: https://github.com/nowo-tech/VerifactuBundle/compare/v1.4.1...v1.5.0
[1.4.1]: https://github.com/nowo-tech/VerifactuBundle/compare/v1.4.0...v1.4.1
[1.4.0]: https://github.com/nowo-tech/VerifactuBundle/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/nowo-tech/VerifactuBundle/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/nowo-tech/VerifactuBundle/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/nowo-tech/VerifactuBundle/compare/v1.0.12...v1.1.0
[1.0.12]: https://github.com/nowo-tech/VerifactuBundle/compare/v1.0.11...v1.0.12
[1.0.11]: https://github.com/nowo-tech/VerifactuBundle/compare/v1.0.10...v1.0.11
[1.0.3]: https://github.com/nowo-tech/VerifactuBundle/compare/v1.0.2...v1.0.3
[1.0.2]: https://github.com/nowo-tech/VerifactuBundle/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/nowo-tech/VerifactuBundle/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/nowo-tech/VerifactuBundle/releases/tag/v1.0.0
