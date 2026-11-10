# Usage

## Table of contents

- [Process a billing record](#process-a-billing-record)
- [Production AEAT submission](#production-aeat-submission)
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
    generatedAt: '2026-07-09T16:00:00+02:00',
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
relation to your entities; Doctrine is optional and only stores the last hash per issuer NIF. See also the
[integration guide](INTEGRATION-NOWO.md).

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
            issueDate: $invoice->issuedOn()->format('d-m-Y'),
            totalTaxAmount: $invoice->taxTotal()->toDecimalString(),
            totalAmount: $invoice->total()->toDecimalString(),
            generatedAt: $this->clock->now()->format(DATE_ATOM),
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

Store the hash, XML and AEAT response in your own model; the bundle does not keep billing records. To raise your own
domain event when the AEAT answers, either map `$result['submission']` in the adapter above or listen to
`VerifactuEvents::AFTER_AEAT_SUBMISSION` (see [Events](#events)).

### Ports you can replace

- `Repository\HashChainRepositoryInterface` (or `LockableHashChainRepositoryInterface`) via `hash_chain.repository`,
  to keep the chain in your own persistence.
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
