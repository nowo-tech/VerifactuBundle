# Usage

## Table of contents

- [Process a billing record](#process-a-billing-record)
- [Production AEAT submission](#production-aeat-submission)
- [Amounts: one string in the XML, the hash and the QR](#amounts-one-string-in-the-xml-the-hash-and-the-qr)
- [Dates in Europe/Madrid](#dates-in-europemadrid)
- [Issuer and software identity at runtime](#issuer-and-software-identity-at-runtime)
- [Record store and chain verification](#record-store-and-chain-verification)
- [Batch submission to the AEAT](#batch-submission-to-the-aeat)
- [Complete records: recipients, rectifications, tax qualification](#complete-records-recipients-rectifications-tax-qualification)
- [Hash only](#hash-only)
- [QR in Twig](#qr-in-twig)
- [Console](#console)
- [Events](#events)
- [Using it from a DDD / hexagonal application](#using-it-from-a-ddd--hexagonal-application)
- [Forms in Twig (REQ-TWIG-005)](#forms-in-twig-req-twig-005)

## Process a billing record

```php
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Service\BillingRecordProcessor;

$result = $processor->process(new BillingRecord(
    RecordType::Alta,
    issuerNif: '89890001K',
    invoiceSeriesNumber: 'FAC-2026-001',
    issueDate: '09-07-2026',
    invoiceType: 'F1',
    totalTaxAmount: '21.00',
    totalAmount: '121.00',
    generatedAt: '', // stamped inside the chain lock, e.g. 2026-07-09T16:00:00+02:00
), submitToAeat: true);
```

The processor validates XML against official AEAT XSD schemas, optionally signs records with XAdES (No-Veri*Factu), persists the hash chain, and submits via SOAP when a certificate is configured.

## Production AEAT submission

Configure your AEAT certificate and environment:

```yaml
nowo_verifactu:
    mode: verifactu
    hash_chain:
        storage: doctrine
    aeat:
        environment: production
        certificate_path: '%env(AEAT_CERT_PATH)%'
        certificate_password: '%env(AEAT_CERT_PASSWORD)%'
        validate_xsd: true
```

For No-Veri*Factu with XAdES signing:

```yaml
nowo_verifactu:
    mode: no_verifactu
    aeat:
        certificate_path: '%env(AEAT_CERT_PATH)%'
        certificate_password: '%env(AEAT_CERT_PASSWORD)%'
        sign_xades: true
```

## Amounts: one string in the XML, the hash and the QR

Pass amounts as decimal strings (`'12.3'`, `'12.30'`, `'0'`, never through float). `process()` writes them in their
canonical form, `Model\Amount::canonical()`: two decimals, no leading zeros, no `+` (`12.30`, `0.00`, `-5.00`). The
same string goes into `<CuotaTotal>` / `<ImporteTotal>`, the fingerprint, the QR `importe` and the returned record.
The AEAT hash specification (v0.1.2, §3) asks for the hashed value to hold the same information as the XML field and
treats `123.1` and `123.10` as equal; writing the same string in both places is correct however the AEAT reads it.
`HashChainGenerator::verifyHash()` also accepts a fingerprint over amounts without trailing zeros (what
VerifactuBundle 1.1 – 1.4.1 hashed).

## Dates in Europe/Madrid

The AEAT expects `FechaHoraHusoGenRegistro` with its offset (`2024-01-01T19:20:30+01:00`). Leave `generatedAt` empty
and `process()` stamps it with `GenerationTimestamp::now()` (the Symfony `clock` service when available) inside the
chain lock, so the generation times follow the chain order even under concurrency. When you need the value yourself,
do not use `format('c')` (PHP default time zone of the server); use the helper:

```php
use Nowo\VerifactuBundle\Clock\GenerationTimestamp;

$generatedAt = GenerationTimestamp::now($clock);              // PSR-20 clock (Symfony's `clock` service), or now()
$issueDate   = GenerationTimestamp::issueDate($invoice->issuedAt()); // d-m-Y, the Madrid calendar day
```

## Issuer and software identity at runtime

Standard Symfony project: keep `nowo_verifactu.issuer`, `software` and `installation` in the configuration; nothing
else to do.

Host that keeps them in its database (an admin form, several tenants) or a DDD host: implement the providers and point
the configuration at them. The bundle calls them on every `process()` and every SOAP submission, so an edit applies
to the next record without a cache clear or a worker restart. Do not cache the values in the provider across requests
(FrankenPHP worker mode keeps the service alive).

```php
use Nowo\VerifactuBundle\Model\IssuerIdentity;
use Nowo\VerifactuBundle\Model\SoftwareIdentity;
use Nowo\VerifactuBundle\Provider\IssuerProviderInterface;
use Nowo\VerifactuBundle\Provider\SoftwareIdentityProviderInterface;

final readonly class BillingSettingsIdentityProvider implements IssuerProviderInterface, SoftwareIdentityProviderInterface
{
    public function __construct(private BillingSettingsRepository $settings) {}

    public function getIssuer(): IssuerIdentity
    {
        $s = $this->settings->current();

        return new IssuerIdentity($s->issuerTaxId(), $s->issuerName());
    }

    public function getSoftwareIdentity(): SoftwareIdentity
    {
        $s = $this->settings->current();

        // producer name, producer NIF, software name, IdSistemaInformatico (≤ 2 chars), version, NumeroInstalacion
        return new SoftwareIdentity($s->producerName(), $s->producerNif(), 'Beacon', 'SB', $s->appVersion(), '1');
    }
}
```

```yaml
nowo_verifactu:
    providers:
        issuer: App\Billing\BillingSettingsIdentityProvider
        software_identity: App\Billing\BillingSettingsIdentityProvider
```

A record passed with an empty `issuerNif` takes the provider's NIF; a record with its own NIF keeps it. The producer
(`SistemaInformatico/NombreRazon` and `NIF`) is the company that makes the software, not the issuer.

## Record store and chain verification

With `record_store.storage: doctrine` (or `memory`, or your own `record_store.service`) every chained record is stored
with its hash, previous hash, XML and submission state, in the same transaction as the chain head:

```php
foreach ($recordStore->findByIssuer('89890001K') as $stored) {   // BillingRecordStoreInterface, chain order
    $stored->position; $stored->record->hash; $stored->record->xml;
    $stored->submissionStatus; // pending|sending|accepted|accepted_errors|rejected|unconfirmed|failed (batch) or accepted|failed (process())
}

$result = $chainVerifier->verify('89890001K');                    // Service\ChainVerifier
$result->isValid(); $result->problems;
```

When you enable the store on an issuer that already has records, the first stored record continues from the old
head: `verify()` accepts it and returns that hash in `$result->continuesFrom`; `verify($nif, $anchor)` requires an
exact starting hash. The Doctrine store reads the log in pages, so long chains verify in flat memory.

`process(..., submitToAeat: true)` marks the record `accepted` (with the AEAT CSV) or `failed`. If that update fails,
the submission result is still returned and the exception is in `$result['store_errors']`. Your invoice references the
record by issuer NIF and hash (or position); there is no Doctrine relation to your entities.

## Batch submission to the AEAT

From 1.7 the stored records can be sent in batches (`Service\BillingRecordBatchSubmitter`, needs a record store): up to
**1000 records per request** ("El número máximo de registros por envío es de 1.000", AEAT *Sistemas Informáticos de
Facturación – Servicios web* v1.0.3, p. 10; `RegistroFactura maxOccurs="1000"` in `SuministroLR.xsd`), in chain order,
with the AEAT flow control and one answer per record.

```php
// 1. Chain and store (in your transaction), without submitting.
$processor->process($record);                                   // one record
$result = $processor->processBatch([$a, $b, $c]);               // several records of ONE issuer, one lock, all or nothing
$result['records']; $result['errors'];                          // errors: [index => list<string>]; nothing chained then

// 2. After your commit: send what is waiting (Messenger, a command or a scheduler).
$bus->dispatch(new SubmitPendingBillingRecords('89890001K'));   // Messenger\SubmitPendingBillingRecords
// or: php bin/console nowo:verifactu:submit-pending [--issuer=89890001K]
// or: $outcome = $submitter->submitPending('89890001K');        // BatchSubmissionResult
```

**Chain integrity.** Records are chained and stored before they are sent; sending never chains, re-chains or reorders
anything. `processBatch()` chains record n+1 onto record n inside one chain lock and stores nothing when any record
fails, so the head never points at a record that was not stored. The submitter claims the next batch inside the
issuer's chain lock (one batch in flight per issuer, oldest first) and sends it outside any transaction.

**Answers per record** (`EstadoRegistro`, AEAT *Servicios web* v1.0.3 p. 11):

| AEAT | Stored status | Meaning |
|---|---|---|
| `Correcto` | `accepted` | Registered; the submission CSV is stored as the reference |
| `AceptadoConErrores` | `accepted_errors` | Registered with errors (`submissionErrorCode`, `submissionError`); correct it with a new record, `correction: 'S'` (Subsanacion) |
| `Incorrecto` | `rejected` | Not registered. It stays in the chain; correct the invoice and chain a NEW record with `correction: 'S', previousRejection: 'X'` (Subsanacion S, RechazoPrevio X: *Servicios web* §9.1.3; AEAT FAQ v1.3 §17) |
| no line for the record | `pending` | Sent again with the next batch |
| `RegistroDuplicado`, not confirmed | `unconfirmed` | See duplicates below: never reported as accepted, not sent again |
| refused as a whole, again and again on its own | `failed` | See refused requests below |

**Requests without a per-record answer.** A transport error (no HTTP answer) or an AEAT that is unavailable (HTTP 502,
503, 504) puts the whole batch back to `pending` (`BatchSubmissionResult::isRetryable()`); nothing is counted against
its records. When the AEAT **refuses the request as a whole** (a SoapFault: an XML format error rejects the whole
message, *Servicios web* p. 10; an HTTP 4xx / 500; an unreadable answer or one without a valid `EstadoEnvio`), the
records go back to `pending` with one more submission failure, and the next batch is halved, again and again, until
the record at fault goes on its own. After `submission.max_attempts` refusals on its own (in all at most
`floor(log2(batch_size)) + max_attempts`) it is marked `failed`, `VerifactuEvents::RECORD_SUBMISSION_FAILED`
(`Event\RecordSubmissionFailedEvent`, after the commit) is dispatched and an error is logged; the records after it keep
flowing. Fix the cause and put it back with `nowo:verifactu:submit-pending --issuer=… --retry-failed`
(`BillingRecordBatchSubmitter::requeueFailed()`). An answer resets the failures of a record.

**Attempts and duplicates.** `submissionAttempts` counts the requests that carried the record, counted right before the
request is transmitted (a claim that died before sending, or a client that failed before sending, is not an attempt).
A resend carries the same stored XML. The AEAT "duplicate" answer (`RegistroDuplicado`) carries no Huella, so it does
not tell whether the record the AEAT holds is this one (an earlier send whose answer was lost) or another record that
used the same invoice id (FAQ v1.3 §6). Therefore:

- with a `Submission\DuplicateConfirmationInterface` (`submission.duplicate_confirmation`; e.g. query
  `ConsultaFactuSistemaFacturacion` and compare the returned `Huella` with `$record->hash`): same record → the state the
  AEAT holds, by record type (alta: `Correcta` → `accepted`, `AceptadaConErrores` → `accepted_errors`, `Anulada` →
  `accepted`, it was registered and annulled later; anulación: `Anulada` → `accepted`); another record → `rejected`;
  unknown → `unconfirmed`;
- without it: a record transmitted before → `unconfirmed` (no CSV, never reported as accepted, not sent again; check it
  at the AEAT and correct it if needed); a record never transmitted before → `rejected` (the id is used by another
  record).

`IdPeticionRegistroDuplicado` and `EstadoRegistroDuplicado` are kept in `submissionError`. A batch claimed by a process
that died is claimed again after `submission.claim_timeout`.

**Flow control** (Orden HAC/1177/2024 art. 16.2 c; *Servicios web* §6.4.4.1, p. 40): the AEAT returns
`TiempoEsperaEnvio` «t» (initially 60 s) with every answer; the next submission waits «t» seconds, unless it carries
the record-design limit of **1000 records** ("o deberá esperar a tener acumulados un número de registros de facturación
igual al límite establecido en el diseño de registro"), whichever comes first. A smaller `submission.batch_size` never
skips the wait, and neither does a claim that stops short of 1000 (an environment change, a second record of one
invoice). The submitter keeps «t» in a cache pool (`CacheFlowControl` over `submission.flow_control_cache_pool`,
default `cache.app`: **use a pool shared by every node**; or your own `Submission\FlowControlInterface` via
`submission.flow_control`), per issuer NIF, or for every issuer with `submission.flow_control_scope: global`, where the
check and a reservation of the slot run under one database lock shared by every issuer (see
[CONFIGURATION](CONFIGURATION.md#batch-submission-17) for which scope fits: AEAT FAQ v1.3 §4). A throttled call returns
`STATE_THROTTLED` and `nextSubmissionAt`; the Messenger handler dispatches itself again with a `DelayStamp`. Dispatch
one `SubmitPendingBillingRecords` per issuer, not one per invoice: every message keeps rescheduling itself while records
wait. Without any AEAT answer the message backs off (60 s, doubled, up to 1 h) and stops after
`SubmitPendingBillingRecordsHandler::MAX_FAILED_RUNS` runs in a row, logging an error; the records stay pending.

**Records written before 1.6** have no AEAT environment and are **never sent** by the batch submitter: they could be
sandbox records while the current environment is production. See [UPGRADING](UPGRADING.md#unreleased).

**Events after commit.** `VerifactuEvents::AFTER_RECORD_SUBMISSION` (`Event\RecordSubmittedEvent`) is dispatched once
per answered record, after the submitter has committed the answers. The submitter refuses to run inside a transaction
on the store's connection (`LogicException`), so the event never fires for an answer that could still roll back. A
listener failure is logged and returned in `BatchSubmissionResult::$eventErrors`; the stored answer stays.

**Worker mode.** The submitter, the handler and the command keep no state between calls: the queue is the record log,
the wait time lives in the cache pool. The in-memory record store is request-scoped, so in production use
`record_store.storage: doctrine` (or your own store implementing `Repository\SubmissionQueueInterface`).

## Complete records: recipients, rectifications, tax qualification

The AEAT needs more than the totals for most invoices. All of these are optional constructor arguments of
`BillingRecord` (and `InvoiceDraft`, for the mapper); left out, the XML is the one of 1.5.

```php
use Nowo\VerifactuBundle\Model\{BillingRecord, Recipient, RecordType, RectificationAmount, RectifiedInvoice, TaxBreakdown};

// F1 to a customer in another EU country, reverse charge, OSS regime key
$record = new BillingRecord(
    RecordType::Alta, '89890001K', 'FAC-2026-100', '09-07-2026', 'F1', '0.00', '100.00', '',
    taxBreakdown: [new TaxBreakdown('01', '0', '100.00', '0', regimeKey: '17', qualification: 'S2')],
    recipients: [Recipient::withForeignId('Kunde GmbH', Recipient::ID_TYPE_VAT_NUMBER, 'DE123456789', 'DE')],
);

// Outside the EU (not subject by location rules): no rate nor tax in the XML
new TaxBreakdown('01', '', '100.00', '', regimeKey: '01', qualification: 'N2');

// Exempt line
new TaxBreakdown('01', '', '50.00', '', regimeKey: '01', exemption: 'E1');

// Rectifying invoice by differences
new BillingRecord(
    RecordType::Alta, '89890001K', 'R-2026-001', '09-07-2026', 'R1', '-21.00', '-121.00', '',
    taxBreakdown: [new TaxBreakdown('01', '21.00', '-100.00', '-21.00', '01', 'S1')],
    recipients: [Recipient::withNif('Cliente SL', 'B12345674')],
    rectificationType: BillingRecord::RECTIFICATION_BY_DIFFERENCES,
    rectifiedInvoices: [new RectifiedInvoice('89890001K', 'FAC-2026-001', '01-07-2026')],
);
// By substitution: rectificationType: 'S' plus rectificationAmount: new RectificationAmount($base, $tax)
```

| AEAT element | Argument | Rule (validations v1.2.2, §3.1.3) |
| --- | --- | --- |
| Destinatarios | `recipients` | Required on F1, F3, R1 – R4; not allowed on F2, R5 (item 13). NIF, or IDOtro: IDType 02 – 07, country code unless 02, `ES` only with 07. |
| TipoRectificativa | `rectificationType` | `S` or `I`, only and (with `strict`) always on R1 – R5 (item 3). |
| FacturasRectificadas / FacturasSustituidas | `rectifiedInvoices` / `substitutedInvoices` | R1 – R5 / F3 only (items 4, 5). |
| ImporteRectificacion | `rectificationAmount` | With, and only with, `S` (item 6). |
| FechaOperacion | `operationDate` | When it differs from the issue date (item 7). |
| ClaveRegimen | `TaxBreakdown::$regimeKey` or `xml.default_regime_key` | AEAT list L8A; rejected without it for IVA from 2027 (§15.6). |
| CalificacionOperacion / OperacionExenta | `qualification` / `exemption` | One of them; S2 with rate and tax 0; N1, N2, E1 – E8 without rate nor tax (§15.4, §15.5). |

Set `nowo_verifactu.validation.strict: true` to refuse F1 / F3 / R1 – R4 without recipients and R1 – R5 without
TipoRectificativa before anything is chained. The element order and types follow the official
`SuministroInformacion.xsd` (shipped in `src/Resources/schemas/aeat`), and the tests validate every combination above
against it.

## Hash only

```php
use Nowo\VerifactuBundle\Generator\HashChainGenerator;

$hash = $hashChainGenerator->computeHash($record);
$input = $hashChainGenerator->buildInputString($record);
```

## QR in Twig

```twig
<img src="{{ verifactu_qr_data_uri(record) }}" alt="Veri*Factu QR">
<p>{{ verifactu_legend() }}</p>
<a href="{{ verifactu_qr_url(record) }}">Verify at AEAT</a>
```

## Console

```bash
php bin/console nowo:verifactu:validate-record \
  --nif=89890001K --numserie=FAC-001 --fecha=09-07-2026 \
  --cuota=21.00 --importe=121.00 --generated-at=2026-07-09T16:00:00+02:00

php bin/console nowo:verifactu:verify-hash \
  --nif=89890001K --numserie=12345678/G33 --fecha=01-01-2024 \
  --cuota=12.35 --importe=123.45 --generated-at=2024-01-01T19:20:30+01:00 \
  --hash=3C464DAF61ACB827C65FDA19F352A4E3BDC2C640E9E9FC4CC058073F38F12F60

# with a record store
php bin/console nowo:verifactu:verify-chain --issuer=89890001K
```

## Events

| Event name (`VerifactuEvents::`) | Event class | When | Can change the outcome |
|---|---|---|---|
| `BEFORE_BILLING_RECORD_GENERATION` | `BeforeBillingRecordGenerationEvent` | Before validation, hash and XML | Yes: `setRecord()` replaces the record |
| `AFTER_BILLING_RECORD_GENERATED` | `AfterBillingRecordGeneratedEvent` | After hash, XML (and signature); chain state stored | No: the event carries a copy of the record |
| `BEFORE_AEAT_SUBMISSION` | `BeforeAeatSubmissionEvent` | Right before the AEAT client is called (`submitToAeat: true` only) | No |
| `AFTER_AEAT_SUBMISSION` | `AfterAeatSubmissionEvent` | After the AEAT client returned | No |
| `AFTER_RECORD_SUBMISSION` | `RecordSubmittedEvent` | Batch submission (1.7): once per answered record, after its answer is committed | No |
| `RECORD_SUBMISSION_FAILED` | `RecordSubmissionFailedEvent` | Batch submission (1.7): once per record marked `failed` (the AEAT kept refusing it on its own), after that state is committed; alert someone | No |

The two AEAT submission events are informational (audit log, metrics, outbox): there is **no veto**. Whether a record is
submitted is decided by the caller (`process(..., submitToAeat: true)`), not by a listener, so a listener can never
silently keep a registered record away from the AEAT. `AfterBillingRecordGeneratedEvent` and both submission events
carry a **copy** of the `BillingRecord`: changing it does not change the hash or the XML sent to the AEAT, the record
returned by `process()`, nor the stored chain head.
`AfterAeatSubmissionEvent::getSubmission()` returns the same array as `$result['submission']` (`success`, `reference`,
`errors` and, for the SOAP client, `endpoint`, `status_code`, `raw_response`); `isSuccessful()` reads `success`. Neither
event carries the certificate or its password. The submission events are not dispatched when validation fails, when
`submitToAeat` is false, or when the client throws.

**A failing listener after chaining never loses or duplicates a record.** When `AFTER_BILLING_RECORD_GENERATED`,
`BEFORE_AEAT_SUBMISSION` and `AFTER_AEAT_SUBMISSION` run, the chain head already points at the record. An exception from
any of their listeners is caught, with or without `submitToAeat`: the record is still submitted (when requested),
`process()` still returns the record (and `submission`), and the failure is added to
`$result['event_errors']` (a list of `['event' => <event name>, 'exception' => <Throwable>]`; the key is absent when every
listener succeeded). It is also logged at `error` level with the event name and the exception class only (no record
data, no exception message). Listener failures never go into `errors`. As with any Symfony event, the remaining
listeners of that event are skipped once one throws. Check `event_errors` if your listener does work you rely on (e.g.
an outbox write). To veto or replace a record, use `BEFORE_BILLING_RECORD_GENERATION`: it runs before validation and
chaining, and its exceptions still propagate (nothing has been chained yet).

**Personal data in the payloads.** The record copy holds the issuer NIF and name (personal data for a self-employed
issuer), the invoice data and the full XML / signed XML (with the public certificate, which names its holder).
`getSubmission()` holds `raw_response`, the AEAT answer, which echoes the issuer and presenter, and `errors`, which for
transport failures can contain the local certificate file path from cURL. Do not log the record or the submission
wholesale: log identifiers such as the invoice number and the CSV, and mask the NIF as the demo subscriber does.

```php
use Nowo\VerifactuBundle\Event\AfterAeatSubmissionEvent;
use Nowo\VerifactuBundle\Event\VerifactuEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: VerifactuEvents::AFTER_AEAT_SUBMISSION)]
public function onSubmitted(AfterAeatSubmissionEvent $event): void
{
    $this->audit->record(
        $event->getRecord()->invoiceSeriesNumber,
        $event->isSuccessful(),
        $event->getSubmission()['reference'] ?? null,
    );
}
```

## Using it from a DDD / hexagonal application

VerifactuBundle is an **infrastructure adapter** for the AEAT VERI*FACTU system. It knows nothing about your invoice
model: it takes a DTO, returns a chained, validated (and optionally signed / submitted) `BillingRecord`. It has no
relation to your entities; Doctrine is optional and stores the chain head per issuer NIF and, with a record store, the
record log. It never flushes your EntityManager (it writes with DBAL inside your transaction) and can map its own
entities from XML (`doctrine.mapping: xml`). See also the [integration guide](INTEGRATION-NOWO.md).

### Keep the domain free of it

Define your own port in the application layer (e.g. `InvoiceRegistrar`) and implement it in infrastructure:

```php
final readonly class VerifactuInvoiceRegistrar implements InvoiceRegistrar
{
    public function __construct(
        private InvoiceToBillingRecordMapper $mapper,
        private BillingRecordProcessor $processor,
        private ClockInterface $clock,
    ) {}

    public function register(Invoice $invoice): RegistrationResult
    {
        $record = $this->mapper->map(new InvoiceDraft(
            issuerNif: $invoice->issuer()->nif(),
            invoiceSeriesNumber: $invoice->number(),
            issueDate: GenerationTimestamp::issueDate($invoice->issuedOn()),
            totalTaxAmount: $invoice->taxTotal()->toDecimalString(),
            totalAmount: $invoice->total()->toDecimalString(),
            generatedAt: GenerationTimestamp::now($this->clock),
        ));

        $result = $this->processor->process($record, submitToAeat: true);

        return new RegistrationResult(
            $result['record']->hash,
            $result['record']->signedXml ?? $result['record']->xml,
            $result['errors'],
            $result['submission']['success'] ?? false,
        );
    }
}
```

Store the hash, XML and AEAT response in your own model, or enable the record store and reference its records by
issuer NIF and hash. To raise your own
domain event when the AEAT answers, either map `$result['submission']` in the adapter above or listen to
`VerifactuEvents::AFTER_AEAT_SUBMISSION` (see [Events](#events)).

### Ports you can replace

- `Repository\HashChainRepositoryInterface` (or `LockableHashChainRepositoryInterface`) via `hash_chain.repository`,
  to keep the chain in your own persistence.
- `Repository\BillingRecordStoreInterface` via `record_store.service`, to keep the record log in your own persistence.
- `Provider\IssuerProviderInterface` / `Provider\SoftwareIdentityProviderInterface` /
  `Provider\AeatEnvironmentProviderInterface` / `Certificate\CertificateProviderInterface` via `providers.*` (values
  kept in your database, resolved on every call; see [CONFIGURATION](CONFIGURATION.md#runtime-aeat-environment-and-certificate)).
- `Client\AeatSubmissionClientInterface` via `aeat.submission_client`; `Signer\BillingRecordSignerInterface`.
- Batch submission (1.7): `Repository\SubmissionQueueInterface` (implemented by your `record_store.service`),
  `Submission\DuplicateConfirmationInterface` via `submission.duplicate_confirmation`,
  `Client\BatchAeatSubmissionClientInterface` via `submission.client`, `Submission\FlowControlInterface` via
  `submission.flow_control`. The standard path needs none of them.

### Known limitations

- Results are arrays, not typed DTOs.
- The hash chain advances when `process()` runs: call it inside the transaction that stores your invoice (same
  connection), or implement the lockable repository in your own persistence. With the Doctrine storage inside your
  transaction, the chain row lock (`SELECT … FOR UPDATE` on the issuer row) is held until your transaction ends: across
  the AEAT HTTP call and while every event listener runs. Keep listeners short, and other `process()` calls for the
  same issuer wait meanwhile.
- Retrying: outside a transaction of yours, retry `process()` on a DBAL `RetryableException`. Inside your transaction,
  roll back and retry the whole unit of work; on `ChainTransactionLostException` (MySQL / MariaDB deadlock) your
  transaction is already gone and the connection was closed, so run nothing more of it and start the unit of work again
  ([CONFIGURATION](CONFIGURATION.md#hash-chain-persistence)).

## Forms in Twig (REQ-TWIG-005)

Every UI form — bundle views and the demo apps — is a PHP Symfony Form Type (FormKit types when FormKit is in the stack) rendered with `form_start`, the REQ-TWIG-003 child loop and `form_end`; submit / cancel buttons may stay outside the loop:

```twig
{{ form_start(form) }}
    {% for child in form %}
        {% if not child.rendered %}
            {{ form_row(child) }}
        {% endif %}
    {% endfor %}
    <button type="submit">Save</button>
{{ form_end(form) }}
```

CSRF-only actions (delete, logout-style buttons, toggles) use an empty Form Type too, and login forms map `_username` / `_password` / `_csrf_token` (token id `authenticate`) on a Form Type. Host overrides and demos must not reintroduce raw `<form>` / `<input>` / `<textarea>` / `<select>` markup: `make check-no-raw-html-form` (part of `make release-check`) fails when it appears.
