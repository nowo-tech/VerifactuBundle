# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/verifactu-bundle`  
**Last audited**: 2026-11-16

Every production artifact under `src/` is mapped below (117/117; audit: `git ls-files src | wc -l`).

## Bundle & DI

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `NowoVerifactuBundle.php` | Bundle entry | FR-BUNDLE-001 |
| `DependencyInjection/Configuration.php` | Config tree | FR-CFG-001 |
| `DependencyInjection/NowoVerifactuExtension.php` | DI extension | FR-CFG-002, FR-WORKER-001, FR-WORKER-003 |
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
| `Model/Amount.php` | The one amount string used in XML, fingerprint and QR | FR-HASH-001, FR-XML-001, FR-QR-001 |
| `Model/AeatEnvironment.php` | `sandbox` / `production` enum used everywhere | FR-PROV-001 |
| `Model/IssuerIdentity.php` | Issuer (obligado a expedir) NIF and name for one call | FR-PROV-001 |
| `Model/SoftwareIdentity.php` | SistemaInformatico block (producer + installation) | FR-PROV-001, FR-XML-001 |
| `Model/Recipient.php` | Invoice recipient (NIF or IDOtro) | FR-XML-001, FR-VAL-001 |
| `Model/RectifiedInvoice.php` | IDFacturaAR of a rectified / substituted invoice | FR-XML-001 |
| `Model/RectificationAmount.php` | ImporteRectificacion of a substitution rectifying invoice | FR-XML-001 |
| `Model/BillingRecordResult.php` | Typed outcome of `processToResult()` | FR-PROC-001 |
| `Model/BillingRecordBatchResult.php` | Typed outcome of `processBatchToResult()` | FR-PROC-001 |
| `Model/StoredBillingRecord.php` | Record read back from the store with chain position and submission state | FR-STORE-002 |
| `Model/RecordSubmissionStatus.php` | EstadoRegistro of a stored record | FR-STORE-002, FR-BATCH-001 |
| `Model/RecordSubmissionOutcome.php` | What a batch submission learnt about one stored record | FR-BATCH-001 |
| `Model/AeatBatchResponse.php` | AEAT answer to one submission (EstadoEnvio, CSV, TiempoEsperaEnvio, lines) | FR-BATCH-001, FR-FLOW-001 |
| `Model/AeatRecordResponse.php` | One RespuestaLinea of an AEAT response | FR-BATCH-001 |
| `Model/BatchSubmissionResult.php` | Result of one `submitPending()` call (idle, in flight, paused…) | FR-BATCH-001 |
| `Model/ChainVerificationResult.php` | Outcome of `ChainVerifier::verify()` | FR-VERIFY-001 |
| `Clock/GenerationTimestamp.php` | Record dates in Europe/Madrid whatever the server time zone | FR-HASH-001, FR-XML-001 |

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
| `Validator/XsdValidator.php` | AEAT XSD | FR-XSD-001, FR-XSD-002 |

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
| `Client/BatchAeatSubmissionClientInterface.php` | Several records of one issuer in one SuministroLR request | FR-CLIENT-001, FR-BATCH-001 |
| `Client/CertificateAwareSoapTransportInterface.php` | Transport that takes the certificate itself (contents, no temp file) | FR-CLIENT-002, FR-CERT-002 |
| `Client/AeatResponseParser.php` | Parses RespuestaSuministro into `AeatBatchResponse` | FR-CLIENT-002, FR-BATCH-001 |

## Signing & certificates

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Certificate/CertificateLoader.php` | PKCS#12 / PEM loader | FR-CERT-001 |
| `Signer/BillingRecordSignerInterface.php` | Signer contract | FR-SIGN-001 |
| `Signer/XadesBillingRecordSigner.php` | XAdES signer | FR-SIGN-001 |
| `Certificate/Certificate.php` | Certificate for one call: file path or contents (PKCS#12 / PEM) | FR-CERT-002 |
| `Certificate/CertificateProviderInterface.php` | Runtime certificate per issuer NIF | FR-CERT-002 |
| `Certificate/ConfigCertificateProvider.php` | Default provider from `aeat.certificate_path` | FR-CERT-002 |
| `Certificate/TemporaryCertificateFile.php` | Temp-file fallback for cURL builds without in-memory certificates | FR-CERT-002 |

## Storage

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Repository/HashChainRepositoryInterface.php` | Hash chain contract | FR-PROC-001 |
| `Repository/LockableHashChainRepositoryInterface.php` | Per-issuer chain lock around read-then-write (`withChainLock()`) | FR-PROC-001, FR-WORKER-002 |
| `Repository/TransactionStateAwareInterface.php` | Whether the chain's connection is inside a transaction (listener database errors rethrown before the AEAT submission, REQ-RUNTIME-003 rule 8) | FR-PROC-001 |
| `Repository/InMemoryHashChainRepository.php` | In-memory storage | FR-PROC-001, FR-WORKER-001 |
| `Repository/DoctrineHashChainRepository.php` | Doctrine storage | FR-STORE-001, FR-WORKER-002 |
| `Entity/BillingRecordHashChain.php` | Doctrine entity | FR-STORE-001 |
| `Doctrine/TablePrefixListener.php` | `doctrine.table_prefix` applied at metadata load (REQ-ORM-002) | FR-STORE-001 |
| `Repository/BillingRecordStoreInterface.php` | Optional per-issuer record log in chain order | FR-STORE-002 |
| `Repository/SubmissionQueueInterface.php` | Record log seen as the AEAT submission queue (1.7) | FR-STORE-002, FR-BATCH-001 |
| `Repository/DoctrineBillingRecordStore.php` | Doctrine record log + queue | FR-STORE-002, FR-WORKER-002 |
| `Repository/InMemoryBillingRecordStore.php` | Request-scoped record log for development | FR-STORE-002, FR-WORKER-001 |
| `Entity/BillingRecordEntry.php` | Record log entity (referenced by ids only) | FR-STORE-002 |
| `Doctrine/DbalRowWriter.php` | Writes bundle rows through DBAL, never `EntityManager::flush()` | FR-STORE-001, FR-STORE-002, FR-WORKER-002 |
| `Resources/config/doctrine/BillingRecordHashChain.orm.xml` | XML mapping of the hash chain entity | FR-STORE-001 |
| `Resources/config/doctrine/BillingRecordEntry.orm.xml` | XML mapping of the record log entity | FR-STORE-002 |

## Processing & events

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Service/BillingRecordProcessor.php` | Orchestration | FR-PROC-001, FR-PROC-002 |
| `EventSubscriber/WorkerStateResetSubscriber.php` | Worker request-scope reset | FR-WORKER-001 |
| `Event/VerifactuEvents.php` | Event names | FR-PROC-002 |
| `Event/BeforeBillingRecordGenerationEvent.php` | Before hook | FR-PROC-002 |
| `Event/AfterBillingRecordGeneratedEvent.php` | After hook | FR-PROC-002 |
| `Service/BillingRecordProcessorInterface.php` | Public processor contract (REQ-ARCH-001 rule 1) | FR-PROC-001 |
| `Event/BeforeAeatSubmissionEvent.php` | Informational hook before a record is submitted | FR-PROC-002 |
| `Event/AfterAeatSubmissionEvent.php` | Informational hook with the submission result | FR-PROC-002 |
| `Event/RecordSubmittedEvent.php` | Once per record after the AEAT answer is committed | FR-BATCH-002 |
| `Event/RecordSubmissionFailedEvent.php` | Once per record after it is committed as failed | FR-BATCH-002 |
| `Event/IssuerSubmissionPausedEvent.php` | Once per pause of an issuer | FR-BATCH-002, FR-FLOW-001 |
| `Event/IssuerSubmissionStalledEvent.php` | AEAT gave no answer to two submissions in a row | FR-BATCH-002 |
| `Service/ChainVerifier.php` | Walks and checks the stored chain of an issuer | FR-VERIFY-001 |

## CLI

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Command/ValidateBillingRecordCommand.php` | validate-record | FR-CLI-001 |
| `Command/VerifyHashChainCommand.php` | verify-hash | FR-CLI-001 |
| `Command/SubmitToAeatSandboxCommand.php` | submit-sandbox | FR-CLI-001 |
| `Command/SubmitPendingCommand.php` | `submit-pending`: next batch per issuer, once (cron) | FR-CLI-001, FR-BATCH-001 |
| `Command/VerifyChainCommand.php` | `verify-chain`: stored chain verification | FR-CLI-001, FR-VERIFY-001 |
| `Command/ResetChainCommand.php` | `reset-chain`: removes the chain head so the next record starts a chain | FR-CLI-001, FR-STORE-001 |

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

## Runtime providers

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Provider/IssuerProviderInterface.php` | Issuer at runtime (per record, never at container build) | FR-PROV-001 |
| `Provider/ConfigIssuerProvider.php` | Default: static `nowo_verifactu.issuer` | FR-PROV-001 |
| `Provider/SoftwareIdentityProviderInterface.php` | SistemaInformatico identity at runtime | FR-PROV-001 |
| `Provider/ConfigSoftwareIdentityProvider.php` | Default: static `nowo_verifactu.software` | FR-PROV-001 |
| `Provider/AeatEnvironmentProviderInterface.php` | AEAT environment at runtime | FR-PROV-001 |
| `Provider/ConfigAeatEnvironmentProvider.php` | Default: static `aeat.environment` | FR-PROV-001 |

## Batch submission & flow control

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Service/BillingRecordBatchSubmitter.php` | Sends stored records in batches (≤1000 per SuministroLR, chain order) | FR-BATCH-001, FR-BATCH-002, FR-FLOW-001 |
| `Submission/FlowControlInterface.php` | AEAT TiempoEsperaEnvio flow control (HAC/1177/2024 art. 16.2) | FR-FLOW-001 |
| `Submission/CacheFlowControl.php` | Flow control in a PSR-6 pool shared by every process | FR-FLOW-001, FR-WORKER-001 |
| `Submission/BatchSplit.php` | Halves a batch the AEAT refuses as a whole, never below 1 | FR-BATCH-001 |
| `Submission/AeatFaultClassifier.php` | Classifies SOAP Client faults by Codigo | FR-BATCH-001 |
| `Submission/DuplicateConfirmationInterface.php` | Confirms a RegistroDuplicado answer refers to this very record | FR-BATCH-001 |

## Messenger

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Messenger/SubmitPendingBillingRecords.php` | Message: send the waiting records of an issuer | FR-BATCH-003 |
| `Messenger/SubmitPendingBillingRecordsHandler.php` | Sends the next batch and schedules the following one | FR-BATCH-003 |

## Exceptions

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `Exception/InvalidAeatEnvironmentException.php` | Environment other than exactly `sandbox` / `production` | FR-PROV-001 |
| `Exception/ChainEnvironmentMismatchException.php` | Chain head belongs to another AEAT environment | FR-STORE-001, FR-PROV-001 |
| `Exception/ChainTransactionLostException.php` | Database ended the host transaction while the chain lock ran | FR-STORE-001 |

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| Bundle & DI | 5 | 5 |
| Models | 22 | 22 |
| Integration (Nowo ERP) | 2 | 2 |
| Generators | 2 | 2 |
| Validators | 3 | 3 |
| QR | 2 | 2 |
| AEAT clients | 10 | 10 |
| Signing & certificates | 7 | 7 |
| Storage | 15 | 15 |
| Processing & events | 13 | 13 |
| CLI | 6 | 6 |
| Twig | 1 | 1 |
| Translations | 7 | 7 |
| AEAT XSD schemas | 5 | 5 |
| Runtime providers | 6 | 6 |
| Batch submission & flow control | 6 | 6 |
| Messenger | 2 | 2 |
| Exceptions | 3 | 3 |
| **Total production sources** | **117** | **117** |
