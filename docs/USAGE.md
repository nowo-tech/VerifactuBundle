# Usage

## Table of contents

- [Process a billing record](#process-a-billing-record)
- [Production AEAT submission](#production-aeat-submission)
- [Amounts: one string in the XML, the hash and the QR](#amounts-one-string-in-the-xml-the-hash-and-the-qr)
- [Dates in Europe/Madrid](#dates-in-europemadrid)
- [Issuer and software identity at runtime](#issuer-and-software-identity-at-runtime)
- [Record store and chain verification](#record-store-and-chain-verification)
- [Hash only](#hash-only)
- [QR in Twig](#qr-in-twig)
- [Console](#console)
- [Events](#events)
- [Using it from a DDD / hexagonal application](#using-it-from-a-ddd--hexagonal-application)
- [Forms in Twig (REQ-TWIG-005)](#forms-in-twig-req-twig-005)

## Process a billing record

```php
use Nowo\VerifactuBundle\Clock\GenerationTimestamp;
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
    generatedAt: GenerationTimestamp::now(), // e.g. 2026-07-09T16:00:00+02:00
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
VerifactuBundle 1.0 – 1.4.1 hashed).

## Dates in Europe/Madrid

The AEAT expects `FechaHoraHusoGenRegistro` with its offset (`2024-01-01T19:20:30+01:00`). `format('c')` uses the PHP
default time zone of the server; use the helper instead:

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
    $stored->position; $stored->record->hash; $stored->record->xml; $stored->submissionStatus; // pending|accepted|failed
}

$result = $chainVerifier->verify('89890001K');                    // Service\ChainVerifier
$result->isValid(); $result->problems;
```

`process(..., submitToAeat: true)` marks the record `accepted` (with the AEAT CSV) or `failed`. If that update fails,
the submission result is still returned and the exception is in `$result['store_errors']`. Your invoice references the
record by issuer NIF and hash (or position); there is no Doctrine relation to your entities.

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
- `Provider\IssuerProviderInterface` / `Provider\SoftwareIdentityProviderInterface` via `providers.*`.
- `Client\AeatSubmissionClientInterface` via `aeat.submission_client`; `Signer\BillingRecordSignerInterface`.

### Known limitations

- Results are arrays, not typed DTOs.
- The hash chain advances when `process()` runs: call it inside the transaction that stores your invoice (same
  connection), or implement the lockable repository in your own persistence. With the Doctrine storage inside your
  transaction, the chain row lock (`SELECT … FOR UPDATE` on the issuer row) is held until your transaction ends: across
  the AEAT HTTP call and while every event listener runs. Keep listeners short, and other `process()` calls for the
  same issuer wait meanwhile.

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
