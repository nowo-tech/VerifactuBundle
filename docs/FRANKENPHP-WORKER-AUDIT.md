# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/verifactu-bundle` (`symfony-bundle`) |
| Audited revision | `v1.0.9` (release of worker-mode remediations) |
| Audit date | 2026-09-23 (re-confirmed 2026-09-25) |
| Method | Manual review of every file under `src/` (services, repositories, entity, clients, signer, validators, Twig extension, commands, DI extension, `Resources/config`) |
| Remediation (2026-09-23 / shipped in 1.0.9) | W-01 resolved (request-scoped in-memory storage via `src/EventSubscriber/WorkerStateResetSubscriber.php` + `kernel.reset`, non-debug warning, `hash_chain.repository` alias bug fixed); W-02/W-03 resolved in `src/Repository/DoctrineHashChainRepository.php` (fresh reads, detach, closed-manager reset); W-04 resolved; W-05 accepted. Tests: `tests/Integration/WorkerModeIntegrationTest.php`, `tests/Integration/DoctrineHashChainWorkerModeTest.php` |
| **Verdict** | ✅ **Viable under scenario B** — the in-memory storage can no longer build per-worker chains (it is reset at the start of each main request, like classic mode, and warns outside debug); the Doctrine storage reads the last hash fresh from the database and recovers from a closed EntityManager. For a legally valid chain, `hash_chain.storage: doctrine` (or a persistent `hash_chain.repository`) is still required, and per-issuer serialization remains the application's responsibility (W-05) |

## Execution model assumed

FrankenPHP worker mode boots the Symfony kernel once per worker and serves many requests with the same container. This audit assumes the **strict** variant: the kernel is **not** rebooted between requests, so every shared service, static property and PHP global survives from one request to the next. Two scenarios are evaluated:

- **A — kernel not rebooted, `services_resetter` still runs:** services tagged `kernel.reset` (or implementing `ResetInterface`) are reset between requests.
- **B — no reset at all:** nothing is reset; any per-request state kept in a service leaks into the next request.

A bundle that is safe under **B** is safe under **A** and under classic mode / PHP-FPM.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | `InMemoryHashChainRepository::$states` is cleared at the start of each main request (`WorkerStateResetSubscriber`) and on `kernel.reset`; everything else is `readonly` |
| Static properties / `static` locals | ✅ | None in `src/` |
| `ResetInterface` / `kernel.reset` coverage | ✅ | `InMemoryHashChainRepository` tagged `kernel.reset` (method `clear`; `ResetInterface` not implemented because `HashChainRepositoryInterface::reset(string)` already uses the name); scenario B does not depend on it |
| Request / user / locale captured in services | ✅ | No request, token or locale is stored; the bundle works on `BillingRecord` arguments |
| Superglobals, `$_ENV`, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None; the libxml error-mode flag is saved and restored in `finally` (W-04) |
| Doctrine / EntityManager | ✅ | `DoctrineHashChainRepository` gets the manager per call from `ManagerRegistry`, reads with `Query::HINT_REFRESH`, detaches after use and resets a closed manager (W-02, W-03). Clearing the application's own identity map between requests remains the application's responsibility under B |
| Output, headers, `exit`, shutdown functions | ✅ | None in HTTP code; commands use `SymfonyStyle` |
| Resources (files, sockets, cURL) held open | ✅ | cURL handle created and closed per call (`src/Client/CurlSoapTransport.php:47-79`); certificates read per call |
| Memory growth across requests | ✅ | In-memory repository bounded by one request; Doctrine entities detached after use |
| Blocking I/O and timeouts | ✅ | AEAT SOAP uses explicit `CURLOPT_CONNECTTIMEOUT` / `CURLOPT_TIMEOUT` from `aeat.timeout` (5–120 s) |
| Third-party static state | ✅ | `robrichards/xmlseclibs` and `endroid/qr-code` are used through per-call instances; no static caches found |
| PHPStan FrankenPHP rulesets | ✅ | `ruleset-classic.neon` + `ruleset-worker.neon` included in `phpstan.neon.dist` |

## Services reviewed

| Service | Shared | Mutable state | Scenario A | Scenario B |
|---------|--------|---------------|------------|------------|
| `Repository\InMemoryHashChainRepository` (default `HashChainRepositoryInterface`) | yes (public alias) | `private array $states` (request-scoped) | ✅ `kernel.reset` → `clear()` | ✅ cleared per main request |
| `Repository\DoctrineHashChainRepository` (`storage: doctrine`) | yes | none (`readonly` registry); fresh reads, detached entities | ✅ | ✅ |
| `Service\BillingRecordProcessor` | yes (public alias) | none (`readonly` collaborators and config) | inherits repository status | inherits repository status |
| `Generator\HashChainGenerator` | yes | none | ✅ | ✅ |
| `Generator\BillingRecordXmlGenerator` | yes | none | ✅ | ✅ |
| `Validator\XsdValidator` | yes | none; saves/restores libxml global flag | ✅ | ✅ |
| `Validator\AeatBusinessRulesValidator` / `Validator\SpanishTaxIdValidator` | yes | none | ✅ | ✅ |
| `Client\SoapAeatSubmissionClient` / `CurlSoapTransport` / `SoapEnvelopeBuilder` / `AeatEndpointResolver` | yes | none | ✅ | ✅ |
| `Client\NullAeatSubmissionClient` | yes | none | ✅ | ✅ |
| `Certificate\CertificateLoader` / `Signer\XadesBillingRecordSigner` | yes | none; certificate file read on every `sign()` | ✅ | ✅ |
| `Qr\QrCodeGenerator` / `Qr\QrUrlBuilder` | yes | none | ✅ | ✅ |
| `Twig\VerifactuTwigExtension` | yes | none (config-only `readonly`) | ✅ | ✅ |
| `Integration\InvoiceToBillingRecordMapper` | yes | none | ✅ | ✅ |
| 3 console commands | yes (CLI only) | none | ✅ | ✅ |
| `EventSubscriber\WorkerStateResetSubscriber` | yes | `readonly` in-memory repository reference | ✅ | ✅ |

`BillingRecord`, `HashChainState`, `InvoiceDraft` and the events are immutable value objects created per call. `BillingRecordHashChain` is a Doctrine entity only handled inside `DoctrineHashChainRepository`.

## Findings

### W-01 — Default in-memory hash-chain storage carries fiscal state across requests and forks per worker (High)

- **Where:** `src/Repository/InMemoryHashChainRepository.php:22` (`private array $states`), `:24-37`; wired as the default by `src/Resources/config/services.yaml:16-17` and `hash_chain.storage` default `memory` (`src/DependencyInjection/Configuration.php:59-62`). Read and written by `BillingRecordProcessor::process()` (`src/Service/BillingRecordProcessor.php:70`, `114-119`). The `hash_chain.repository` option does not fix it: `NowoVerifactuExtension::configureHashChainRepository()` only aliases `nowo_verifactu.hash_chain_repository` (`src/DependencyInjection/NowoVerifactuExtension.php:96-100`), not `HashChainRepositoryInterface`, so the autowired processor keeps using the in-memory repository.
- **Worker impact:** in classic mode the array is empty on every request (every record is a first-in-chain record). In a worker the array survives: request N+1 chains to the record produced by request N in the **same worker thread**, possibly by another user of the same issuer, and embeds its series number, date and hash (`RegistroAnterior`) in the new XML. With several worker threads each one keeps its own chain, so records of the same issuer are linked to different predecessors, and every worker restart silently starts a new chain. The result looks correct in a single-worker test but produces forked, non-compliant Veri*Factu hash chains in production. There is no `ResetInterface`, so scenario A is affected too.
- **Recommendation:** in the bundle, either make the in-memory repository implement `ResetInterface` (restoring classic behaviour) and refuse it when `kernel.debug` is false, or make `doctrine` the default. Fix `configureHashChainRepository()` so `hash_chain.repository` also aliases `HashChainRepositoryInterface`. For integrators: always set `hash_chain.storage: doctrine` (or alias `HashChainRepositoryInterface` to a persistent service yourself) before running in worker mode.
- **Status:** Resolved — `InMemoryHashChainRepository::clear()` (tagged `kernel.reset`, method `clear`) is called at the start of every main request by `src/EventSubscriber/WorkerStateResetSubscriber.php` (`kernel.request`, priority 4096, sub-requests ignored), so scenario B behaves like classic mode (each request starts a new chain) instead of forking per worker. When `kernel.debug` is false the repository logs a warning on every `storeLastState()` (`$warnNotPersistent`, set by `NowoVerifactuExtension`). Failing fast in production was not chosen to keep BC for existing classic-mode deployments; making `doctrine` the default was not possible because Doctrine is optional. `configureHashChainRepository()` now aliases `HashChainRepositoryInterface` to `hash_chain.repository`. Tests: `tests/Integration/WorkerModeIntegrationTest.php`, `tests/Unit/EventSubscriber/WorkerStateResetSubscriberTest.php`, `tests/Unit/Repository/InMemoryHashChainRepositoryTest.php`, `tests/Unit/DependencyInjection/NowoVerifactuExtensionTest.php`.

### W-02 — Doctrine repository can read stale chain state from the identity map (Medium, scenario B)

- **Where:** `src/Repository/DoctrineHashChainRepository.php:31-44` (`getLastState()` via `findOneBy()`), `:46-62` (`storeLastState()` keeps the managed entity).
- **Worker impact:** `findOneBy()` always runs SQL, but when the row's entity is already in the EntityManager identity map Doctrine returns the existing object **without refreshing its fields**. Under scenario A the `doctrine` registry is reset between requests, so the map is empty and this is fine. Under scenario B the `BillingRecordHashChain` loaded in an earlier request stays managed: after another worker updates the row, this worker returns the old hash and chains the new record to a stale predecessor (forked chain).
- **Recommendation:** in `getLastState()` use a query with `Query::HINT_REFRESH`, `$em->refresh()`, or a DBAL read; detach the entity after `storeLastState()`. Run with the Symfony `services_resetter` enabled.
- **Status:** Resolved — `getLastState()` and `storeLastState()` load the row with a DQL query using `Query::HINT_REFRESH` and detach the entity after reading/flushing; `reset()` uses a DQL `DELETE`. The manager is obtained per call from `ManagerRegistry::getManagerForClass()`. Tests: `tests/Integration/DoctrineHashChainWorkerModeTest.php` (entity kept in the identity map, row updated by "another worker" through DBAL, next record chains to the fresh hash; the existing integration test called `EntityManager::clear()`, which hid this).

### W-03 — A failed flush closes the EntityManager for the rest of the worker's life (Medium, scenario B)

- **Where:** `src/Repository/DoctrineHashChainRepository.php:56-61` and `:71-72` call `flush()` on the shared EntityManager. The first record for a new issuer inserts a row protected by `uniq_verifactu_hash_chain_issuer` (`src/Entity/BillingRecordHashChain.php:20`), so two concurrent first records for the same issuer can raise a unique-constraint violation.
- **Worker impact:** any exception during `flush()` closes the EntityManager. Under scenario A DoctrineBundle's resetter replaces the closed manager before the next request. Under scenario B every later `storeLastState()` (and every other Doctrine use in the app) fails with "EntityManager is closed" until the worker restarts.
- **Recommendation:** keep `services_resetter` enabled, or catch the exception and call `ManagerRegistry::resetManager()`. Also consider a row lock (`SELECT … FOR UPDATE` / pessimistic lock) around read-then-write, see W-05.
- **Status:** Resolved — `storeLastState()` and `reset()` catch `\Throwable`, reset the manager through `ManagerRegistry::resetManager()` when it was closed, and rethrow; each call also resets a manager found closed before use. Tests: `DoctrineHashChainWorkerModeTest::testFailedFlushResetsClosedEntityManagerSoTheWorkerRecovers`, `testFailedResetQueryResetsClosedEntityManager`, `tests/Unit/Repository/DoctrineHashChainRepositoryTest.php`.

### W-04 — `XsdValidator` forces the libxml internal-errors flag to `false` instead of restoring it (Low)

- **Where:** `src/Validator/XsdValidator.php:49-53` and `:59-63`.
- **Worker impact:** `libxml_use_internal_errors()` is process-wide state that is not reset between worker requests. If the host application enabled internal errors (for its own XML handling, possibly once at boot), every validation switches it off for the rest of the worker's life, so later libxml warnings become PHP warnings (and exceptions under Symfony's error handler). No data leak.
- **Recommendation:** `$previous = libxml_use_internal_errors(true); try { … } finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }`.
- **Status:** Resolved — both call sites in `src/Validator/XsdValidator.php` restore the previous value in `finally`. Test: `XsdValidatorTest::testLibxmlInternalErrorsModeIsRestored`.

### W-05 — Hash-chain read-then-write is not atomic (Info)

- **Where:** `src/Service/BillingRecordProcessor.php:70` (read previous state) and `:114-119` (store new state), with no lock in between.
- **Worker impact:** not specific to worker mode (PHP-FPM has the same concurrency), but worker deployments often run many threads: two concurrent records for the same issuer can both chain to the same predecessor.
- **Recommendation:** serialize per issuer (Symfony Lock keyed by NIF, or a pessimistic DB lock in the repository), or process records through a single Messenger consumer.
- **Status:** Accepted — not worker-specific; a correct lock spans the whole `BillingRecordProcessor::process()` (read, sign, store) and belongs to the application (Symfony Lock keyed by NIF or a single Messenger consumer). Documented below.

No other findings. AEAT submission uses explicit, configurable timeouts, the cURL handle is closed on every call, and the certificate is loaded per call (so certificate rotation does not need a worker restart).

## Usage recommendations in worker mode

- Set `nowo_verifactu.hash_chain.storage: doctrine` (the demo already does, `demo/symfony8/config/packages/nowo_verifactu.yaml:15`) or `hash_chain.repository` to a persistent service. The default in-memory storage is request-scoped and not a valid fiscal chain store.
- The bundle does not clear the application's EntityManager; under scenario B clearing the application's identity map between requests remains the application's responsibility (the hash-chain repository itself no longer depends on it).
- Serialize billing record generation per issuer (lock or single Messenger consumer) to keep the chain linear (W-05).
- Custom `HashChainRepositoryInterface`, `AeatSubmissionClientInterface` or `BillingRecordSignerInterface` implementations must not cache chain state, records or certificates in properties without a freshness check and `ResetInterface`.
- The demo (`demo/symfony8`) runs in worker mode by default (`FRANKENPHP_MODE=worker`, `worker { … }` block in `demo/symfony8/docker/frankenphp/Caddyfile:31`).

## Re-audit triggers

Re-run this audit when a change adds: a new hash-chain storage backend or changes to the repository wiring; caching of certificates, endpoints or AEAT responses; an HTTP client with persistent connections; event listeners that keep records between requests; or any use of `$_SERVER` / `$_ENV` / libxml / locale globals at runtime.
