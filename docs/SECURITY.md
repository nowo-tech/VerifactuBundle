# Security policy

## Supported versions

Security fixes are applied to the current major version line. Please upgrade to the latest patch release to receive security updates.

## Reporting a vulnerability

If you discover a security issue, please report it responsibly:

1. **Do not** open a public GitHub issue for security-sensitive bugs.
2. Send details to the maintainers (e.g. via the contact information on the [repository](https://github.com/nowo-tech/VerifactuBundle) or the Nowo.tech website).
3. Include a clear description, steps to reproduce, and the impact of the issue.
4. Allow time for a fix and coordinated disclosure before any public disclosure.

We will acknowledge your report and work on a fix. We appreciate responsible disclosure and will credit reporters when the issue is fixed (unless you prefer to remain anonymous).

## Security considerations for this bundle

- **No secrets in config files:** AEAT certificates and passwords must be injected via environment variables (`AEAT_CERT_PATH`, `AEAT_CERT_PASSWORD`) or Symfony secrets — never committed to the repository.
- **Certificate handling:** PKCS#12/PEM files grant mTLS access to AEAT. Restrict file permissions (e.g. `0600`) and rotate certificates before expiry.
- **Hash chain integrity:** The SHA-256 chain is append-only per issuer NIF. Protect `HashChainRepository` storage (Doctrine table or custom backend) from unauthorized modification.
- **Input validation:** NIF/CIF/NIE, dates, amounts, and invoice types are validated before XML generation. Do not bypass `AeatBusinessRulesValidator` or XSD validation in production.
- **XAdES signing:** Private keys are loaded in memory only during signing. Ensure OpenSSL and certificate stores are patched on the host.
- **SOAP transport:** `CurlSoapTransport` sends billing records to official AEAT endpoints only. Verify endpoint URLs when switching between sandbox and production.
- **Dependencies:** Run `composer audit` in projects that use this bundle to check for known vulnerabilities in dependencies.

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
