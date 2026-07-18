# Feature Specification: VerifactuBundle baseline (production AEAT compliance)

**Feature Branch**: `001-baseline`  
**Created**: 2026-07-09  
**Status**: Active  
**Last updated**: 2026-07-09

**Related docs**: [`docs/SPEC-DRIVEN-DEVELOPMENT.md`](../../docs/SPEC-DRIVEN-DEVELOPMENT.md), [`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md), [`docs/USAGE.md`](../../docs/USAGE.md), [`docs/SANDBOX.md`](../../docs/SANDBOX.md), [`docs/INTEGRATION-NOWO.md`](../../docs/INTEGRATION-NOWO.md)  
**Code inventory**: [`code-inventory.md`](code-inventory.md)

---

## Summary

**Package**: `nowo-tech/verifactu-bundle`  
**Configuration root**: `nowo_verifactu`

Symfony bundle for Spanish Veri*Factu (RD 1007/2023): SHA-256 hash chains, billing record XML (Alta/Anulación), XSD validation, XAdES signing, AEAT SOAP submission, QR codes, and Nowo ERP integration hooks.

---

## User Scenarios

### US-01 — Hash chain compliance (P1)

**Given** a valid `BillingRecord`, **When** `HashChainGenerator::computeHash()` runs, **Then** the SHA-256 fingerprint matches AEAT field order and test vectors.

### US-02 — XML generation (P1)

**Given** a record with computed hash, **When** `BillingRecordXmlGenerator::generate()` runs, **Then** XML contains mandatory AEAT nodes and passes official XSD validation.

### US-03 — QR on invoices (P2)

**Given** a processed record, **When** Twig helpers or `QrCodeGenerator` run, **Then** a scannable QR with AEAT URL and legend is produced.

### US-04 — AEAT SOAP submission (P1)

**Given** a configured client certificate, **When** `SoapAeatSubmissionClient::submit()` runs, **Then** the record is sent via mTLS SOAP to AEAT sandbox/production.

### US-05 — Persistent hash chain (P1)

**Given** `hash_chain.storage: doctrine`, **When** multiple records are processed, **Then** encadenamiento survives container restarts.

### US-06 — No-Veri*Factu XAdES (P2)

**Given** `mode: no_verifactu` and a certificate, **When** a record is processed, **Then** signed XML is produced.

### US-07 — Nowo ERP adoption (P2)

**Given** an `InvoiceDraft`, **When** `InvoiceToBillingRecordMapper::map()` runs, **Then** a `BillingRecord` is ready for `BillingRecordProcessor`.

### US-08 — Sandbox smoke test (P3)

**Given** CLI access, **When** `nowo:verifactu:submit-sandbox --dry-run` runs, **Then** hash/XML are generated without AEAT credentials.

---

## Requirements

### Bundle & configuration

- **FR-BUNDLE-001**: Bundle MUST expose alias `nowo_verifactu`.
- **FR-CFG-001**: Config MUST support `mode`, `issuer`, `software`, `installation`, `hash_chain`, `aeat`, `qr`.
- **FR-CFG-002**: Extension MUST load services, publish parameters, and prepend Doctrine mapping when applicable.

### Hash & XML

- **FR-HASH-001**: `HashChainGenerator` MUST build Alta and Anulación input strings per AEAT order.
- **FR-HASH-002**: Hash MUST be uppercase SHA-256 hex (64 chars).
- **FR-XML-001**: `BillingRecordXmlGenerator` MUST produce RegistroAlta/Anulacion XML with official field names.
- **FR-XSD-001**: `XsdValidator` MUST validate against bundled AEAT XSD schemas.

### Validation & QR

- **FR-VAL-001**: `AeatBusinessRulesValidator` MUST validate NIF, dates, amounts, invoice type.
- **FR-VAL-002**: `SpanishTaxIdValidator` MUST accept valid NIF/NIE/CIF formats.
- **FR-QR-001**: `QrUrlBuilder` MUST build sandbox/production AEAT URLs.
- **FR-QR-002**: `QrCodeGenerator` MUST produce PNG/data URI at configured size.

### Processing & integration

- **FR-PROC-001**: `BillingRecordProcessor` MUST chain hashes via `HashChainRepositoryInterface`.
- **FR-PROC-002**: Processor MUST dispatch before/after generation events.
- **FR-INT-001**: `InvoiceToBillingRecordMapper` MUST map `InvoiceDraft` to `BillingRecord`.
- **FR-STORE-001**: `DoctrineHashChainRepository` MUST persist hash chain state per issuer NIF.

### AEAT client & signing

- **FR-CLIENT-001**: `AeatSubmissionClientInterface` MUST be replaceable.
- **FR-CLIENT-002**: `SoapAeatSubmissionClient` MUST submit via SOAP 1.1 + mTLS.
- **FR-SIGN-001**: `XadesBillingRecordSigner` MUST sign records with PKCS#12/PEM certificates.
- **FR-CERT-001**: `CertificateLoader` MUST load PKCS#12 and PEM material.

### CLI & Twig

- **FR-CLI-001**: Commands: validate-record, verify-hash, submit-sandbox.
- **FR-TWIG-001**: Twig extension with qr_data_uri, qr_url, legend functions.
- **FR-I18N-001**: Seven locale translation files for validation messages.

---

## Success Criteria

- **SC-001**: **53/53** artifacts mapped in [`code-inventory.md`](code-inventory.md).
- **SC-002**: AEAT example hash test vector passes in PHPUnit.
- **SC-003**: PHPUnit + PHPStan pass in CI; coverage target ~100% (minimum 80% enforced in CI).
- **SC-004**: XSD validation passes for generated Alta and Anulación XML in tests.

---

## Out of scope

- Full ERP / invoicing module
- AEAT Consulta (query) web service
- PDF invoice generation
- Symfony Messenger retry queue (planned)
