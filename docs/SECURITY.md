# Security policy

## Table of contents

- [Supported versions](#supported-versions)
- [Reporting a vulnerability](#reporting-a-vulnerability)
- [Scope](#scope)
- [Attack surface](#attack-surface)
- [Threat model](#threat-model)
- [Mitigations](#mitigations)
- [Secrets and cryptography](#secrets-and-cryptography)
- [Logging](#logging)
- [Dependencies and updates](#dependencies-and-updates)
- [Permissions and exposure](#permissions-and-exposure)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)
- [AI security audit (REQ-SEC-004)](#ai-security-audit-req-sec-004)

## Supported versions

Security fixes are applied to the current major version line. Please upgrade to the latest patch release to receive security updates.

## Reporting a vulnerability

If you discover a security issue, please report it responsibly:

1. **Do not** open a public GitHub issue for security-sensitive bugs.
2. Send details to the maintainers (e.g. via the contact information on the [repository](https://github.com/nowo-tech/VerifactuBundle) or the Nowo.tech website).
3. Include a clear description, steps to reproduce, and the impact of the issue.
4. Allow time for a fix and coordinated disclosure before any public disclosure.

We will acknowledge your report and work on a fix. We appreciate responsible disclosure and will credit reporters when the issue is fixed (unless you prefer to remain anonymous).

## Scope

VerifactuBundle builds Spanish Veri*Factu billing records (registros de facturación): it validates invoice data, computes the SHA-256 hash chain per issuer NIF, renders the AEAT XML, optionally signs it, renders the invoice QR code and legend, and optionally submits the record to the AEAT SOAP service over mutual TLS.

**Covered:** the bundle services, the Doctrine hash chain storage, the three console commands (`nowo:verifactu:validate-record`, `nowo:verifactu:verify-hash`, `nowo:verifactu:submit-sandbox`), the Twig functions and the AEAT client.

**Not covered (host responsibility):** authentication / authorisation of whoever creates invoices in the host application, storage and backup of issued invoices, protection of the certificate files and of the database, the HTTP endpoints of the host (the bundle ships **no controllers or routes**), and legal compliance of the host invoicing workflow.

## Attack surface

| Input | Source | Trust level |
|-------|--------|-------------|
| Bundle configuration (`nowo_verifactu.*`: issuer, software, certificate path / password, environment, table prefix) | Host `config/packages`, environment variables / Symfony secrets | Operator (trusted) |
| `BillingRecord` / `InvoiceDraft` values (NIF, series and number, dates, amounts, invoice type, issuer name, description, lines, tax breakdown) | Host code, often derived from user-entered invoice data | **Untrusted** |
| Console command options (`--nif`, `--numserie`, `--fecha`, `--importe`, `--hash`, `--submit`, …) | CLI operator | Operator |
| Certificate files (PKCS#12 / PEM) read by `CertificateLoader` | Path from configuration | Operator; file contents are secret |
| AEAT SOAP responses parsed by `SoapAeatSubmissionClient` | Network (official AEAT endpoints over TLS) | Semi-trusted |
| Hash chain state (`verifactu_hash_chain` table or custom repository) | Database | Trusted storage, integrity-critical |
| Twig functions `verifactu_qr_data_uri()`, `verifactu_qr_url()`, `verifactu_legend()` | Host templates | Output only |

## Threat model

| Threat | Applies | Mitigation |
|--------|---------|------------|
| XML injection in the generated record / SOAP envelope | Yes (untrusted invoice data) | Every text value is escaped with `htmlspecialchars(…, ENT_XML1 \| ENT_QUOTES)` (`BillingRecordXmlGenerator::escape()`, `SoapEnvelopeBuilder`); the result is validated against the official AEAT XSD (`XsdValidator`, enabled by default). |
| Invalid or malicious business data (bad NIF, dates, amounts, invoice types) | Yes | `AeatBusinessRulesValidator` + `SpanishTaxIdValidator` (NIF / NIE / CIF check digits) run before hashing; records with errors are not submitted. |
| XXE / entity expansion when parsing XML | Low | Only bundle-generated XML is parsed with `DOMDocument::loadXML()` without `LIBXML_NOENT` / `LIBXML_DTDLOAD` (libxml ≥ 2.9 does not load external entities); AEAT responses are read with bounded regular expressions, not an XML parser. |
| Hash chain tampering or forking | Yes (legal integrity) | The chain head is read and written inside `withChainLock()` (transaction + `SELECT … FOR UPDATE` in the Doctrine repository); a unique constraint on `issuer_nif` blocks a concurrent first insert; `nowo:verifactu:verify-hash` recomputes a record hash. Protect the table with database permissions. |
| SSRF / request to an attacker-controlled host | No | Endpoints are fixed AEAT URLs chosen by `AeatEndpointResolver` from `mode` / `environment` / `certificate_type` (enums); no URL comes from input. |
| Man-in-the-middle on AEAT submission | Low | cURL with default TLS peer and host verification plus client certificate (mTLS). |
| Denial of service (slow AEAT, huge payloads) | Yes | `aeat.timeout` (5–120 s, default 30) bounds `CURLOPT_TIMEOUT` and `CURLOPT_CONNECTTIMEOUT`; keep AEAT timeout < PHP `max_execution_time` < proxy timeout ([DEMO-FRANKENPHP](DEMO-FRANKENPHP.md)). |
| XSS through Twig output | Low | `verifactu_qr_data_uri()` is marked safe but only returns `data:image/png;base64,…` built by the bundle; `verifactu_qr_url()` and `verifactu_legend()` are auto-escaped by Twig. |
| CSRF / authorisation bypass | Not applicable | No HTTP endpoints, forms or routes. Hosts that expose invoice creation must protect their own routes. |
| Deserialization of untrusted data | No | No `unserialize()`; value objects are built from typed constructors. |
| Path traversal | Low | The only file paths are the certificate path from configuration (operator) and the shipped XSD files. |
| Command injection | No | No subprocess or shell execution. |
| Cross-request state leaks (FrankenPHP worker) | Low | The in-memory repository is cleared at the start of every main request and on `kernel.reset`; see [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md). |
| Table name collisions in a shared database | Low | `doctrine.table_prefix` namespaces the bundle table (REQ-ORM-002). |

## Mitigations

- **Validate, then hash:** `BillingRecordProcessor` validates business rules and (by default) the XSD before the record is chained and stored; do not disable `aeat.validate_xsd` or bypass `AeatBusinessRulesValidator` in production.
- **Strict AEAT success detection:** a submission is successful only when the HTTP status is 2xx and `EstadoRegistro` = `Correcto`; missing status, `AceptadoConErrores`, `Incorrecto`, SOAP faults and non-2xx responses are failures.
- **Dry runs do not advance the chain:** `nowo:verifactu:submit-sandbox` without `--submit` (or with `--dry-run`) never stores a new chain head.
- **Locked read-then-write:** custom repositories should implement `LockableHashChainRepositoryInterface`; without a lock, concurrent requests can fork the chain. SQLite ignores `FOR UPDATE`: use a server database in production.
- **Safe defaults:** `aeat.environment: sandbox`, `hash_chain.storage: memory` (non-persistent, warns outside debug), `validate_xsd: true`, no certificate configured → `NullAeatSubmissionClient` (never contacts the AEAT).

## Secrets and cryptography

- **Certificates and passwords** come from configuration backed by environment variables (`AEAT_CERT_PATH`, `AEAT_CERT_PASSWORD`) or Symfony secrets; never commit certificate files or passwords. Restrict certificate file permissions (e.g. `0600`) and rotate them before expiry.
- Certificate passwords are `#[\SensitiveParameter]` in `CertificateLoader::load()` and `CurlSoapTransport::send()`, so they are redacted from stack traces.
- **Hash chain:** SHA-256 over the AEAT-defined fields (validated against the official AEAT vectors); the hash is integrity evidence, not a secret.
- **Signing:** private keys are loaded in memory only while signing. **Known limitation:** `XadesBillingRecordSigner` emits a plain enveloped XML-DSig signature (RSA-SHA256, exclusive C14N), not full XAdES (no `QualifyingProperties` / `SignedProperties`), so No-Veri*Factu records signed by the bundle do not yet meet the AEAT XAdES requirement. Provide your own `BillingRecordSignerInterface` if you need XAdES today.
- No secrets are hard-coded; test fixtures use throwaway certificates and placeholder passwords.

## Logging

- The bundle logs through an optional PSR-3 `LoggerInterface` only: `InMemoryHashChainRepository` logs a warning with the issuer NIF only when the non-persistent storage is used outside debug.
- It never logs certificate passwords, private keys, certificate contents or SOAP payloads. The AEAT raw response is returned to the caller (`raw_response`) and is not logged by the bundle; hosts that log it must treat it as containing personal and tax data (NIF, names, amounts).
- Console commands print validation errors and AEAT error messages to the operator only.

## Dependencies and updates

- Runtime dependencies: Symfony components, `robrichards/xmlseclibs` (XML-DSig), `endroid/qr-code`, `ext-openssl` / `ext-curl` / `ext-dom`; Doctrine ORM / DoctrineBundle are optional.
- CI runs `composer audit` and Dependabot (Composer + GitHub Actions) proposes updates weekly; Critical / High advisories are fixed in a patch release.
- Host applications should run `composer audit` and keep OpenSSL / libxml / cURL patched on the host.

## Permissions and exposure

- **HTTP:** none — the bundle registers no routes or controllers.
- **CLI:** `nowo:verifactu:submit-sandbox --submit` sends a real record to the configured AEAT environment with the configured certificate; restrict who can run console commands in production. The other two commands are read-only.
- **Database:** only the bundle table (`verifactu_hash_chain`, optionally prefixed) is written; grant the application user the minimum privileges and audit changes to it.
- **Network:** outbound HTTPS to the official AEAT endpoints only, and only when a certificate is configured.

## Release security checklist (12.4.1)

Before tagging a release, confirm:

| Item | Notes |
|------|--------|
| **SECURITY.md** | This document is current and linked from the README where applicable. |
| **`.gitignore` and `.env`** | `.env` and local env files are ignored; no committed secrets. |
| **No secrets in repo** | No API keys, passwords, certificate files, or tokens in tracked files. |
| **Recipe / Flex** | Default recipe or installer templates do not ship production secrets. |
| **Input / output** | Inputs validated; XSD enforced when enabled; Twig outputs escaped where user-controlled. |
| **Dependencies** | `composer audit` run; issues triaged. |
| **Logging** | Logs do not print certificate passwords, private keys, or full SOAP payloads with PII unnecessarily. |
| **Cryptography** | Certificates from secure config; never hardcoded. |
| **Permissions / exposure** | AEAT submission routes and CLI commands documented; production roles configured. |
| **Limits / DoS** | SOAP `timeout` configured; hierarchy AEAT op &lt; PHP &lt; Caddy write (see DEMO-FRANKENPHP); avoid unbounded XML processing on untrusted input. |
| **AI security audit (REQ-SEC-004)** | Pass recorded in monorepo `BUNDLES_SECURITY_ANALYSIS.md`. |

Record confirmation in the release PR or tag notes.

## AI security audit (REQ-SEC-004)

| Field | Value |
|-------|--------|
| Date | 2026-07-30 |
| Method | Cursor / Nowo static AI security review (diff + baseline AEAT/mTLS / certs / hash / XAdES / Twig) |
| Grade | **Pass (good)** |
| Overall risk | **Medium** (host-owned certificates + AEAT network; unchanged by this remediation) |
| Open Critical / High / Medium | None |
| Notes | Certs via env only; mTLS to official AEAT endpoints; SOAP timeout hierarchy in demos; hash chain storage host-owned |
