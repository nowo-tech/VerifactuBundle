# Configuration

Configuration root: `nowo_verifactu`

```yaml
nowo_verifactu:
    mode: verifactu              # verifactu | no_verifactu
    issuer:
        nif: '89890001K'
        name: 'My Company SL'
    software:
        id: '01'
        name: 'MyApp'
        version: '1.0.0'
        manufacturer_nif: 'B12345678'
        manufacturer_name: 'Nowo.tech'
        solo_verifactu: true
    installation:
        number: '001'
    hash_chain:
        storage: memory          # memory | doctrine
        repository: null         # custom service id (optional)
        table_prefix: verifactu_
    aeat:
        environment: sandbox     # sandbox | production
        submission_client: null  # custom client service id (optional)
        certificate_path: '%env(AEAT_CERT_PATH)%'
        certificate_password: '%env(AEAT_CERT_PASSWORD)%'
        certificate_type: personal  # personal | seal
        timeout: 30
        validate_xsd: true
        sign_xades: false        # auto-enabled for no_verifactu mode
    qr:
        size_mm: 35
        legend: verifactu
```

## Production setup

### SOAP client (Veri*Factu)

When `aeat.certificate_path` is set, the bundle auto-wires `SoapAeatSubmissionClient` with mTLS via cURL against the official AEAT endpoints.

### Hash chain persistence

Set `hash_chain.storage: doctrine` and install `doctrine/orm` + `doctrine/doctrine-bundle`. The bundle registers the `BillingRecordHashChain` entity automatically.

### XAdES signing (No-Veri*Factu)

In `no_verifactu` mode, billing records are signed automatically when a certificate is configured. You can force signing in Veri*Factu mode with `aeat.sign_xades: true`.

### XSD validation

Official AEAT schemas ship under `src/Resources/schemas/aeat/`. Disable with `aeat.validate_xsd: false` only for debugging.

## Translations

Validation messages use the `NowoVerifactuBundle` translation domain under `src/Resources/translations/`.

Override any key from your application:

```yaml
# translations/NowoVerifactuBundle.es.yaml
validation:
  issuer_nif:
    invalid: 'NIF del emisor no válido (texto personalizado).'
```

Supported locales: `en`, `es`, `it`, `fr`, `pt`, `de`, `nl`.
