# Future Improvements and Features

Roadmap ideas for the Verifactu Bundle. Items marked ✅ are already in **1.0.0**.

## Table of contents

- [Priority: High](#priority-high)
- [Priority: Medium](#priority-medium)
- [Priority: Low](#priority-low)
- [Documentation / DX](#documentation-dx)
- [Getting help](#getting-help)

## Priority: High

1. ✅ SHA-256 hash chain (Alta / Anulación) per AEAT field order
2. ✅ Billing record XML + official XSD validation
3. ✅ AEAT SOAP submission (sandbox / production) with mTLS
4. ✅ Doctrine and in-memory hash-chain storage
5. ✅ XAdES signing for No-Veri*Factu mode

## Priority: Medium

6. ✅ QR codes + Twig helpers and mandatory legend
7. ✅ Console commands (validate, verify-hash, submit-sandbox)
8. ✅ Nowo ERP mapper (`InvoiceDraft` → `BillingRecord`)
9. ✅ i18n validation messages (7 locales)
10. Batch submission / retry queue for AEAT timeouts
11. Structured submission audit log entity (request/response CSV refs)

## Priority: Low

12. REST admin endpoints for hash-chain inspection (optional)
13. Metrics (Prometheus/OpenTelemetry) for submission success rates
14. Additional invoice types / regimes as AEAT schemas evolve
15. GraphQL or messenger-based async submission worker

## Documentation / DX

- Keep Spec Kit baseline (`specs/001-baseline/`) in sync with `src/`
- Expand sandbox fixture vectors when AEAT publishes new test cases
- Packagist / Symfony Flex recipe when the package is published

## Getting help

Open an issue on [GitHub](https://github.com/nowo-tech/VerifactuBundle/issues) if you need a roadmap item prioritized.
