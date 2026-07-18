# Spec-driven development

Three synchronized layers:

1. **GitHub Spec Kit baseline** — `specs/001-baseline/` ([`spec.md`](../specs/001-baseline/spec.md), [`code-inventory.md`](../specs/001-baseline/code-inventory.md))
2. **Product behavior** — [`USAGE.md`](USAGE.md), [`CONFIGURATION.md`](CONFIGURATION.md), [`INTEGRATION-NOWO.md`](INTEGRATION-NOWO.md), [`SANDBOX.md`](SANDBOX.md)
3. **Traceability** — `REQ-*` in Makefiles, CI, and demos

## Non-goals

- Full ERP / invoicing module (only AEAT compliance layer)
- AEAT Consulta (query) web service (planned)
- PDF invoice generation (integrator responsibility)
- Async retry queue (Symfony Messenger — planned)

## User stories

| ID | Story |
| --- | --- |
| US-01 | **As an** integrator, **I want** SHA-256 hash chains **so that** billing records comply with AEAT Veri*Factu. |
| US-02 | **As an** integrator, **I want** XML registro de facturación (Alta/Anulación) **so that** records can be submitted to AEAT. |
| US-03 | **As an** integrator, **I want** QR codes on invoices **so that** recipients can verify at AEAT. |
| US-04 | **As an** integrator, **I want** SOAP submission with client certificate **so that** sandbox/production AEAT accepts records. |
| US-05 | **As an** integrator, **I want** Doctrine hash chain storage **so that** production restarts preserve encadenamiento. |
| US-06 | **As an** integrator, **I want** XAdES signing **so that** No-Veri*Factu records are legally valid. |
| US-07 | **As a** Nowo developer, **I want** `InvoiceDraft` mapping **so that** ERP invoices plug into Veri*Factu without tight coupling. |
| US-08 | **As a** maintainer, **I want** PHPUnit + PHPStan + ~100% coverage **so that** regressions are caught. |

## Functional scope

**Configuration root:** `nowo_verifactu`

| Area | Responsibility |
| --- | --- |
| Hash | `HashChainGenerator` — SHA-256 per Orden HAC/1177/2024 |
| XML | `BillingRecordXmlGenerator` — Alta / Anulación (official XSD field names) |
| XSD | `XsdValidator` — official AEAT schemas bundled |
| QR | `QrUrlBuilder`, `QrCodeGenerator` |
| Validation | `AeatBusinessRulesValidator`, `SpanishTaxIdValidator` |
| Processing | `BillingRecordProcessor` — orchestration + events |
| Storage | `HashChainRepositoryInterface` — memory or Doctrine |
| AEAT | `SoapAeatSubmissionClient` / `NullAeatSubmissionClient` |
| Signing | `XadesBillingRecordSigner`, `CertificateLoader` |
| Integration | `InvoiceDraft`, `InvoiceToBillingRecordMapper` |
| CLI | validate, verify-hash, submit-sandbox |
| Twig | `VerifactuTwigExtension` |

## Requirement identifiers

| ID | Where | What |
| --- | --- | --- |
| REQ-MAKE-008 | Root/demo Makefiles | `update-deps` targets |
| REQ-TEST-003 | PHPUnit | ~100% PHP coverage (see exclusions below) |

## Validation

```bash
make release-check
composer qa
make validate-translations
```

### Coverage exclusions (justified)

| Excluded | Reason |
| --- | --- |
| `NowoVerifactuBundle.php` | Empty bundle class |
| XSD files under `Resources/schemas/` | Official AEAT artifacts, validated indirectly |
| GD-dependent QR PNG paths in CI without `ext-gd` | Skipped in PHPUnit when extension missing |

## Engram relationship

Store AEAT sandbox outcomes, certificate rotation notes, and integrator decisions in Engram (`docs/ENGRAM.md`). Spec Kit baseline remains the source of truth for code inventory; Engram captures runtime/integration context.
