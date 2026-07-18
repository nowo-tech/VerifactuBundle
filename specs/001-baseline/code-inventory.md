# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/verifactu-bundle`  
**Last audited**: 2026-07-09

Every production artifact under `src/` is mapped below (53/53).

## Bundle & DI

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `NowoVerifactuBundle.php` | Bundle entry | FR-BUNDLE-001 |
| `DependencyInjection/Configuration.php` | Config tree | FR-CFG-001 |
| `DependencyInjection/NowoVerifactuExtension.php` | DI extension | FR-CFG-002 |
| `Resources/config/services.yaml` | Service wiring | FR-CFG-002 |
| `Resources/config/services_doctrine.yaml` | Doctrine repo wiring | FR-STORE-001 |

## Models

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Model/BillingRecord.php` | Billing record DTO | FR-PROC-001 |
| `Model/HashChainState.php` | Hash chain state | FR-PROC-001 |
| `Model/InvoiceLine.php` | Invoice line DTO | FR-PROC-001 |
| `Model/RecordType.php` | Alta / Anulacion enum | FR-XML-001 |
| `Model/TaxBreakdown.php` | Tax breakdown DTO | FR-PROC-001 |

## Integration (Nowo ERP)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Integration/InvoiceDraft.php` | Portable invoice draft | FR-INT-001 |
| `Integration/InvoiceToBillingRecordMapper.php` | ERP → BillingRecord | FR-INT-001 |

## Generators

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Generator/HashChainGenerator.php` | SHA-256 hash chain | FR-HASH-001, FR-HASH-002 |
| `Generator/BillingRecordXmlGenerator.php` | RegistroAlta/Anulacion XML | FR-XML-001 |

## Validators

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Validator/AeatBusinessRulesValidator.php` | Business rules | FR-VAL-001 |
| `Validator/SpanishTaxIdValidator.php` | NIF/NIE/CIF | FR-VAL-002 |
| `Validator/XsdValidator.php` | AEAT XSD | FR-XSD-001 |

## QR

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Qr/QrUrlBuilder.php` | AEAT verification URL | FR-QR-001 |
| `Qr/QrCodeGenerator.php` | PNG / data URI QR | FR-QR-002 |

## AEAT clients

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Client/AeatSubmissionClientInterface.php` | Submission contract | FR-CLIENT-001 |
| `Client/NullAeatSubmissionClient.php` | Dev null client | FR-CLIENT-001 |
| `Client/SoapAeatSubmissionClient.php` | Production SOAP client | FR-CLIENT-002 |
| `Client/SoapEnvelopeBuilder.php` | SOAP envelope | FR-CLIENT-002 |
| `Client/AeatEndpointResolver.php` | Endpoint resolution | FR-CLIENT-002 |
| `Client/CurlSoapTransport.php` | mTLS transport | FR-CLIENT-002 |
| `Client/SoapTransportInterface.php` | Transport contract | FR-CLIENT-002 |

## Signing & certificates

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Certificate/CertificateLoader.php` | PKCS#12 / PEM loader | FR-CERT-001 |
| `Signer/BillingRecordSignerInterface.php` | Signer contract | FR-SIGN-001 |
| `Signer/XadesBillingRecordSigner.php` | XAdES signer | FR-SIGN-001 |

## Storage

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Repository/HashChainRepositoryInterface.php` | Hash chain contract | FR-PROC-001 |
| `Repository/InMemoryHashChainRepository.php` | In-memory storage | FR-PROC-001 |
| `Repository/DoctrineHashChainRepository.php` | Doctrine storage | FR-STORE-001 |
| `Entity/BillingRecordHashChain.php` | Doctrine entity | FR-STORE-001 |

## Processing & events

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Service/BillingRecordProcessor.php` | Orchestration | FR-PROC-001, FR-PROC-002 |
| `Event/VerifactuEvents.php` | Event names | FR-PROC-002 |
| `Event/BeforeBillingRecordGenerationEvent.php` | Before hook | FR-PROC-002 |
| `Event/AfterBillingRecordGeneratedEvent.php` | After hook | FR-PROC-002 |

## CLI

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Command/ValidateBillingRecordCommand.php` | validate-record | FR-CLI-001 |
| `Command/VerifyHashChainCommand.php` | verify-hash | FR-CLI-001 |
| `Command/SubmitToAeatSandboxCommand.php` | submit-sandbox | FR-CLI-001 |

## Twig

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Twig/VerifactuTwigExtension.php` | QR Twig helpers | FR-TWIG-001 |

## Translations (`Resources/translations/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/translations/NowoVerifactuBundle.en.yaml` | English messages | FR-I18N-001 |
| `Resources/translations/NowoVerifactuBundle.es.yaml` | Spanish messages | FR-I18N-001 |
| `Resources/translations/NowoVerifactuBundle.it.yaml` | Italian messages | FR-I18N-001 |
| `Resources/translations/NowoVerifactuBundle.fr.yaml` | French messages | FR-I18N-001 |
| `Resources/translations/NowoVerifactuBundle.pt.yaml` | Portuguese messages | FR-I18N-001 |
| `Resources/translations/NowoVerifactuBundle.de.yaml` | German messages | FR-I18N-001 |
| `Resources/translations/NowoVerifactuBundle.nl.yaml` | Dutch messages | FR-I18N-001 |

## AEAT XSD schemas (`Resources/schemas/aeat/`)

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Resources/schemas/aeat/SuministroInformacion.xsd` | Registro Alta/Anulacion | FR-XSD-001 |
| `Resources/schemas/aeat/SuministroLR.xsd` | SOAP submission | FR-XSD-001 |
| `Resources/schemas/aeat/ConsultaLR.xsd` | Consulta (reference) | FR-XSD-001 |
| `Resources/schemas/aeat/RespuestaSuministro.xsd` | Response schema | FR-XSD-001 |
| `Resources/schemas/aeat/xmldsig-core-schema.xsd` | XML-DSig | FR-XSD-001 |

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| PHP classes | 39 | 39 |
| YAML config | 2 | 2 |
| Translation locales | 7 | 7 |
| XSD schemas | 5 | 5 |
| **Total `src/` artifacts** | **53** | **53** |
