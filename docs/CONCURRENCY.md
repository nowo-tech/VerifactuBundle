# Concurrency (REQ-RUNTIME-003)

How the bundle stays correct when the same issuer's chain is written at the same time: two requests, two workers, a
retried request or a replayed Messenger message. The AEAT invoice chain is legally binding, so the one outcome that must
never happen is a **forked chain** (two records chained onto the same predecessor) or a record sent to the AEAT whose
chain head never committed.

## Table of contents

- [Summary](#summary)
- [Write paths](#write-paths)
- [Conflict codes](#conflict-codes)
- [Retries and replays](#retries-and-replays)
- [Listener and store failures (rule 8)](#listener-and-store-failures-rule-8)
  - [Submitted record not committed](#submitted-record-not-committed)
- [Host options](#host-options)
- [Worker mode](#worker-mode)
- [Residual risks](#residual-risks)
- [Tests and CI](#tests-and-ci)

## Summary

- The bundle has **no HTTP routes, controllers or API of its own**. It is called by the host (services, a console
  command, a Messenger handler), so it never answers a conflict itself: lock conflicts reach the host as Doctrine DBAL
  exceptions, and the host's conflict responder answers them (see [Conflict codes](#conflict-codes)).
- Every write is a **DBAL statement** on the connection of the EntityManager that maps the bundle entities (usually the
  host's default one). The bundle never calls `flush()`, `clear()`, `close()` or `resetManager()` on any EntityManager.
- The chain is serialised **per issuer** with `SELECT … FOR UPDATE` on the issuer's chain row
  (`DoctrineHashChainRepository::withChainLock()`), inside the host's transaction when one is open (DBAL savepoint) or in
  a transaction of its own.
- No connection is wrapped and no commit tracking is installed by the bundle (it does not need one: it answers no HTTP).
  It does not use transaction-doctrine's `DoctrineTransactionRunner::forHostEntityManager()`.

## Write paths

| Path | Transaction | Concurrency control (rule 4) | Conflict answer |
| --- | --- | --- | --- |
| `BillingRecordProcessor::process()` / `processBatch()`: chain head + record log append | `withChainLock()`: its own transaction, or a savepoint inside the host's open transaction | Insert-if-absent of the issuer row (`ON CONFLICT DO NOTHING` / `ON DUPLICATE KEY UPDATE` no-op), then one `SELECT … FOR UPDATE` of the issuer row; the head passed to the chaining is the one read under the lock. The record position comes from a locking read; primary key (issuer, position) and unique (issuer, hash) are the last guard | Propagates the DBAL `RetryableException` (deadlock, lock-wait timeout, serialization failure). Inside a host transaction on MySQL / MariaDB, every retryable error (a deadlock, or a lock-wait timeout with `innodb_rollback_on_timeout` ON, ends the whole transaction) closes the connection and throws `ChainTransactionLostException` (previous: the DBAL error), with or without savepoints |
| `HashChainRepositoryInterface::storeLastState()` / `storeLastHash()` called directly (deprecated hooks) | Caller's transaction, or autocommit | Keyed `UPDATE` of the issuer row (no read-modify-write unless `storeLastHash()`) | Propagates |
| `nowo:verifactu:reset-chain` (`reset()`) | Autocommit, one `DELETE` | Single keyed statement | Propagates (command exits non-zero) |
| `markSubmitted()` after `process(..., submitToAeat: true)` | Caller's transaction, or autocommit | Keyed `UPDATE` by (issuer, hash) | Outside a transaction: contained in `store_errors`. Inside your transaction: a database failure throws `SubmittedRecordNotCommittedException` with the record and the AEAT answer (see [below](#submitted-record-not-committed)) |
| Batch submitter claim: `claimNext()`, `claimRecord()`, `failRecord()`, `requeueFailed()`, `scheduleWakeUp()` | Inside the issuer's `withChainLock()` (the submitter refuses to run inside a host transaction); with `flow_control_scope: global` a shared lock row is taken first (fixed order, no cycle) | Row lock on the issuer row; the claim `UPDATE` only takes rows still `pending` (or with an expired claim) | Propagates the DBAL `RetryableException`: Messenger retry strategy, or the command exits non-zero; the records stay pending. A deadlock in the issuer lock nested in the global one closes the connection and comes out as the DBAL error itself (never `ChainTransactionLostException`: there is no host transaction); a lock-wait timeout there rolls back to the savepoint |
| `markTransmitted()`, `releaseClaim()`, `parkClaim()`, `confirmSuspects()`, `resolveUnconfirmed()` | Autocommit, one statement | Compare-and-set on the claim token and status; counters are atomic (`n = n + 1`) | Propagates; an abandoned claim expires after `submission.claim_timeout` and is claimed again |
| `recordOutcomes()`, `failClaim()` (the AEAT answers of one batch) | One transaction of their own | Compare-and-set on the claim token; events are dispatched only after the commit | Retried in-process up to 3 times on a lock conflict (the answers are in hand, the claim token makes the replay harmless); then propagates, and the batch is sent again after the claim timeout with duplicates resolved (see [Retries and replays](#retries-and-replays)). That last `RetryableException` comes **after** the AEAT submission: never expose it as a 409 / `CONFLICT_001` on an HTTP route (a client retry would trigger another submission); run the submitter from Messenger or a command |
| Flow control (`CacheFlowControl`) | PSR-6 cache, not the database | Read and reserved under the issuer (or global) chain lock | Not a database write |
| `SubmitPendingBillingRecordsHandler` (Messenger) | Calls the batch submitter | As above | Exceptions propagate to Messenger's retry and failure transport |

`grep -rn "flush()" src/` has no hit: there is no ORM write path.

The record read port (`BillingRecordQueryInterface`, 1.10) is not a write path: plain reads on the store's connection,
no lock, no transaction of its own, so it never waits on the chain lock nor makes a writer wait. Invoice numbers are
matched byte for byte in PHP on top of the SQL filter, so a case- or pad-insensitive collation never mixes two invoices.

Business keys (rule 5): the issuer NIF is case-insensitive by definition, so every key is normalised (trimmed and
upper-cased) before every statement; the column collation never decides a match. Hashes are stored and compared as
upper-case hexadecimal.

## Conflict codes

The bundle has no `conflict_response` option because it renders no response. A host on
[nowo-tech/transaction-doctrine](https://github.com/nowo-tech/TransactionDoctrine) ^1.6 gets the platform answer
without any glue:

- a DBAL `RetryableException` from the chain lock, and `ChainTransactionLostException` (its previous exception is the
  DBAL error), are **transient failures** (`TransientFailureClassifier::isTransient()`), so the conflict responder answers
  **409 + `Retry-After` + `CONFLICT_001`** (`X-Conflict-Code: CONFLICT_001`);
- when anything of the same request committed earlier (the `CommitMark` taken at `kernel.request`, for example a
  `process()` call that ran outside a host transaction and already committed its record), `ConflictCode::forRequest()`
  gives **409 with no `Retry-After` + `CONFLICT_002`**: read again, do not repeat (a blind retry would add a second fiscal
  record);
- unique-constraint violations and connection loss are not transient and are never mapped to a conflict. The bundle maps
  no unique violation to 409 either: the insert-if-absent of the issuer row never fails on a concurrent creation, and
  a duplicate (issuer, position) or (issuer, hash) is a real error (it would mean a broken lock), never a domain answer.

`tests/Unit/Exception/ChainTransactionLostConflictCodeTest.php` pins this contract (`CONFLICT_001`, `CONFLICT_002` with a
committed watermark, unique violation and connection loss not transient), and
`tests/Concurrency/DeadlockRecoveryTest.php` checks it on a real MySQL / MariaDB deadlock.

## Retries and replays

- **After an upgrade from 1.1.0 – 1.8.0** on DBAL 3 without savepoints, or 1.4.1 – 1.8.0 with `process()` inside your
  transaction on PostgreSQL (record-store failures: 1.5.0 – 1.8.0): run `nowo:verifactu:verify-chain` for each issuer and look for two records with the same
  previous hash (a fork) or a chain head missing from the record log.
- **Retry the whole unit of work, never `process()` alone.** After a lock conflict inside your transaction, roll back
  and run your transaction again (your writes and `process()`). After `ChainTransactionLostException` the server already
  rolled back your transaction and the bundle closed the connection: run nothing more of the lost unit on it.
- **`process()` is not idempotent**: each call chains a new record (the hash covers the generation time). Make your own
  unit idempotent with a natural key (for example a unique invoice number stored in the same transaction), so a replay
  of a unit that already committed is detected and answers the stored result. transaction-doctrine's
  `#[AsWriteUnit]` / Messenger `WriteUnitMiddleware` replay a unit only when nothing committed (`CONFLICT_001`).
- **No entity survives an attempt**: the bundle keeps no managed entity and no state between calls (array-hydrated
  reads, DBAL writes); providers (`IssuerProviderInterface`, `SoftwareIdentityProviderInterface`,
  `AeatEnvironmentProviderInterface`) are called again on every `process()` call.
- **Batch submission replays safely**: a batch is claimed with a token under the issuer lock; the HTTP request runs
  outside any transaction; if the process dies or the answer cannot be stored, the claim expires and the batch is sent
  again. The AEAT then answers "duplicate" (RegistroDuplicado), which is mapped to a registered state only when a
  `DuplicateConfirmationInterface` confirms the same Huella; otherwise the record is stored as `unconfirmed`, never
  reported as accepted. Events are dispatched only after the answers are committed.

## Listener and store failures (rule 8)

Listeners of `AFTER_BILLING_RECORD_GENERATED`, `BEFORE_AEAT_SUBMISSION` and `AFTER_AEAT_SUBMISSION` are informational:
once the chain head committed, a failing listener must not hide the record (a retry would chain a second record for the
same invoice), so its exception is logged (class only) and returned in `event_errors`.

**Exception (since 1.9.0):** a **database error** (any `Doctrine\DBAL\Exception` in the chain of
previous exceptions) thrown by an `AFTER_BILLING_RECORD_GENERATED` or `BEFORE_AEAT_SUBMISSION` listener **while a
transaction is open on the chain's connection** is rethrown. In that case the new chain head is not committed yet, and
the database may already have ended your transaction (MySQL / MariaDB deadlock) or aborted it (PostgreSQL: every later
statement fails and `COMMIT` silently rolls everything back). Containing it would submit to the AEAT, or return as
chained, a record whose head never commits, and your retry would then chain a second record onto the same predecessor.
Nothing was sent to the AEAT at that point: roll back and replay your unit. The check needs a repository that implements
`TransactionStateAwareInterface` (`DoctrineHashChainRepository` does).

### Submitted record not committed

With `process(..., submitToAeat: true)` inside your transaction (logged as a warning), the AEAT receives the record
before your transaction commits. The bundle therefore reads the chain head again on the chain's connection:

- **before the AEAT request:** when the read fails (PostgreSQL: the transaction is aborted, for example because a
  listener swallowed a database error) or returns another head (MySQL / MariaDB: the transaction is gone and the read ran
  in autocommit), `ChainTransactionLostException` is thrown. The connection is closed only on MySQL / MariaDB, where
  the transaction is already gone (`$e->connectionClosed`); on PostgreSQL the aborted transaction stays open for your
  rollback. Nothing was sent: roll back if `$connection->isTransactionActive()` and restart the unit;
- **after the AEAT request:** a database failure of the record store or of an `AFTER_AEAT_SUBMISSION` listener, or a
  head that is gone, throws `SubmittedRecordNotCommittedException` (logged as critical). Committing would lose the head
  silently (pdo_pgsql and mysqli commit without an error) while the AEAT holds the record, and the next record would fork
  the chain at the AEAT.

Whenever the bundle throws for a lost head or a submitted record not committed while your transaction is still open
(PostgreSQL, or MySQL / MariaDB when the database kept it), it first marks that transaction **rollback-only**: a host
that catches the exception and calls `commit()` anyway gets `CommitFailedRollbackOnly` (DBAL 3:
`ConnectionException`), never a silent loss. PostgreSQL would otherwise turn that COMMIT into a ROLLBACK and report
success; MySQL would commit your rows without the record the AEAT holds. The same applies to an outer commit after an
inner `transactional()` savepoint was rolled back. `transactional()` itself still rolls back cleanly.

**Never retry `SubmittedRecordNotCommittedException` automatically.** It is not a lock conflict: a retried `process()`
would send a second record for the same invoice. It carries no previous exception on purpose (the database error is in
`$e->databaseError`, the head loss in `$e->headLoss`), so transaction-doctrine's classifier never sees a transient
failure in it and answers 500. Automatic retry layers (HTTP clients and adapters, transaction-doctrine write units,
Messenger retry strategies) must not retry it: in a Messenger handler, catch it, repair, or wrap it in
`UnrecoverableMessageHandlingException`.

A head-check loss **before** the request (`ChainTransactionLostException` without a previous exception on MySQL /
MariaDB, or with PostgreSQL's 25P02 read error) is safe to retry as a whole unit, since nothing was sent. But
transaction-doctrine does not recognise it as transient and answers 500 by default; map it to 409 yourself if you want
clients to retry.

A listener that chains another record for the **same issuer** in the same transaction (a nested `process()` call) moves
the head, so the head check after it reports the first record's head as gone: chain the second record after
`process()` returns.

What to do with it. Catch `Throwable` and use `SubmittedRecordNotCommittedException::find()`: inside
`Connection::transactional()` / `wrapInTransaction()` on MySQL / MariaDB the bundle may have closed the connection, so
Doctrine's own `rollBack()` throws `NoActiveTransaction`, which keeps the bundle's exception only as its previous one. A
plain `catch (SubmittedRecordNotCommittedException $e)` would miss it there.

```php
try {
    $connection->transactional(function () use ($processor, $record): void {
        // ... your writes ...
        $processor->process($record, submitToAeat: true);
    });
} catch (Throwable $failure) {
    $e = SubmittedRecordNotCommittedException::find($failure);
    if ($e === null) {
        throw $failure;
    }
    if ($connection->isTransactionActive()) {
        $connection->rollBack();             // only with your own beginTransaction(); transactional() already did
    }
    // Outside any transaction now: persistSubmittedRecord() refuses to run inside one (LogicException), because a
    // later rollback would undo the repair (MySQL / MariaDB) or the aborted transaction would refuse it (PostgreSQL).
    // $e->record is the exact record the AEAT received (hash, XML), $e->submission its answer (CSV). Pass them
    // unchanged: persistSubmittedRecord() is not an import API. It refuses (InvalidArgumentException, nothing written)
    // a record whose fingerprint, parsed XML (no DOCTYPE; SuministroInformacion namespace; exactly one own Huella,
    // IDFactura and invoice-id fields, RegistroAnterior Huella), environment or issuer does not match, and an answer
    // without a boolean success. It also records the answer when the record is already the head,
    // so the batch submitter does not send it again.
    if (!$processor->persistSubmittedRecord($e->record, $e->submission)) {
        // The head moved to another record meanwhile: the chain is forked at the AEAT. Stop chaining for this issuer and
        // correct it by hand (nowo:verifactu:verify-chain).
    }
    // Then store your own invoice data again; never call process() again for the same invoice.
}
```

Outside any transaction the head committed before the AEAT request, so failures after it stay contained:
`AFTER_AEAT_SUBMISSION` listener errors in `event_errors`, record-store failures in `store_errors`, and the AEAT
answer (CSV) is always returned.

## Host options

| Option | Effect on concurrency |
| --- | --- |
| `hash_chain.storage: doctrine` (or a persistent `hash_chain.repository` implementing `LockableHashChainRepositoryInterface`) | Required for a valid chain under concurrency: the in-memory storage is request-scoped |
| `record_store.storage: doctrine` | Record log appended in the same lock and transaction as the chain head; enables the batch submitter |
| `submission.claim_timeout` | How long a claim is trusted before the batch is claimed again (a process that died after sending) |
| `submission.flow_control_scope: global` | One more lock row shared by every issuer, always taken before the issuer lock |
| Database lock-wait bound (`innodb_lock_wait_timeout`, PostgreSQL `lock_timeout`) | The bundle uses the server's bound; keep it short (seconds) so a lock wait becomes a `CONFLICT_001` instead of a hung worker |
| DBAL 3: `doctrine.dbal.use_savepoints: true` | Recommended. Without savepoints, a failure inside the chain lock marks your whole transaction rollback-only (DBAL 4 always uses savepoints). A MySQL / MariaDB retryable error (deadlock or lock-wait timeout) is detected from the error either way, so `ChainTransactionLostException` is thrown with or without savepoints |

There is no `conflict_response` option: configure the status (409, or 503) in transaction-doctrine's conflict responder.

## Worker mode

FrankenPHP worker mode with the kernel reset off is supported (see
[FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md)). The bundle only uses the host's EntityManager for its metadata,
DQL and connection, so it keeps working when the host's manager was closed by a failed flush of the host, and it never
resets or replaces it. With the kernel reset off, **a closed host EntityManager is not replaced** for the host's own code
unless something does it between requests: transaction-doctrine's worker hygiene (`WorkerConnectionHygiene`) or
`services_resetter`. **Always roll back.** When the bundle throws inside your transaction and the transaction is still
open (PostgreSQL: aborted; MySQL / MariaDB: when the database kept it), it is left open, rollback-only, for your
rollback. If your code lets the exception escape without rolling back, that transaction survives into the next request
of a kept-alive worker, on every platform: the next request's writes in autocommit style then join it and are lost when
it is finally rolled back. Do not rely on worker hygiene for this: transaction-doctrine's `WorkerConnectionHygiene`
does not recognise `SubmittedRecordNotCommittedException` or a `ChainTransactionLostException` without a previous
exception as database failures, and `services_resetter` never rolls connections back. Roll back in your own error
handling (`if ($connection->isTransactionActive()) $connection->rollBack();`, or `Connection::transactional()`). The
bundle never leaves the nesting level off by one: a chain lock whose SAVEPOINT fails in such a transaction closes the
connection and throws
`ChainTransactionLostException`. After `ChainTransactionLostException` on MySQL / MariaDB the connection is closed and reconnects on the next statement,
with DBAL's nesting level back to 0.

## Residual risks

1. **`process(..., submitToAeat: true)` inside your transaction.** The AEAT HTTP request runs while your transaction
   holds the issuer's chain row (rule 9). The bundle detects a lost head before and after the request (see
   [Submitted record not committed](#submitted-record-not-committed)), but a rollback that **you** decide after
   `process()` returned still leaves the AEAT with a record whose head is gone: store it with
   `persistSubmittedRecord()` in that case too. Prefer `record_store.storage: doctrine` with the batch submitter (it
   refuses to run inside a transaction), or call `process(..., submitToAeat: true)` outside any transaction.
2. **Outside a transaction, failures after the head committed are contained**, not rethrown (`event_errors`,
   `store_errors`): a deviation from rule 8 by design, because the head and the record are already committed and a retry
   would add a second fiscal record. The host reads both keys.
3. **`Connection::transactional()` / `wrapInTransaction()`** around `process()`: on MySQL / MariaDB, after the bundle
   closed the connection (`ChainTransactionLostException`, or `SubmittedRecordNotCommittedException` with a head loss),
   their `rollBack()` throws `NoActiveTransaction`, which keeps the bundle's exception only as its previous one. Catch
   `Throwable` and look along `getPrevious()` (`SubmittedRecordNotCommittedException::find()`). On PostgreSQL the
   connection is never closed for this, so their rollback works. `ChainTransactionLostException` does not implement DBAL's `RetryableException`
   marker, because doctrine/dbal is not a hard dependency of the bundle; transaction-doctrine finds the DBAL error
   through `getPrevious()`.
4. **SQLite** has no row locks: its database lock serialises writers, so it is a single-writer store for development and
   tests only.
5. **Custom chain repositories** that do not implement `TransactionStateAwareInterface` keep the 1.8 behaviour (listener
   database errors contained, no head check), and those that do not implement `LockableHashChainRepositoryInterface` are not
   serialised at all.

## Tests and CI

- `tests/Concurrency/ChainConcurrencyTest.php`: several PHP processes chain records for one issuer (and for many
  issuers) at once, inside and outside a host transaction, with and without the record store: no fork, contiguous
  chain, only retryable lock errors.
- `tests/Concurrency/SubmissionConcurrencyTest.php`, `SubmissionQueueServerDatabaseTest.php`, `ManualFailLockTest.php`:
  chainers and submitters racing on the same issuer; claims, manual fail and requeue under the lock.
- `tests/Concurrency/LockWaitConflictTest.php` (MySQL, MariaDB, PostgreSQL; a second connection holds the row): a lock
  wait after the AEAT submission is never a retry-safe conflict and the repair stores the record; a chain-lock wait in
  the host transaction is a `CONFLICT_001`; a lock wait in the batch submitter's own nested locks is a plain
  retryable error.
- `tests/Concurrency/DeadlockRecoveryTest.php`: a real deadlock inside the host transaction on MySQL / MariaDB closes the
  connection, throws `ChainTransactionLostException` (a `CONFLICT_001` for transaction-doctrine), and the next request
  chains with the lock again.
- `tests/Concurrency/HostTransactionListenerFailureTest.php` (MySQL, MariaDB, PostgreSQL): a listener database error
  inside the host transaction is rethrown, nothing commits, the connection stays usable and the replay chains once; a
  database error after the AEAT submission throws `SubmittedRecordNotCommittedException` and `persistSubmittedRecord()`
  repairs the chain; a listener that swallows the error before the submission is caught by the head check
  (PostgreSQL).
- Every test session waits at most 15 seconds for a row lock (`ConcurrencyKernel::LOCK_WAIT_SECONDS`).
- CI: the required "Chain concurrency" jobs run `tests/Concurrency` on MySQL 8.4, MariaDB 11.4 and PostgreSQL 17, on
  DBAL 4 and DBAL 3; "Dependency floor" runs the suites with the lowest allowed nowo-tech/transaction-* versions and
  checks the demo lock.
