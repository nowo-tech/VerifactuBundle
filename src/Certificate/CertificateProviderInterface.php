<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Certificate;

/**
 * Supplies the AEAT client certificate at runtime, per issuer NIF.
 *
 * The SOAP client (mTLS) and the XAdES signer ask it on every call, so a host can keep encrypted certificates in its
 * database, one per issuer, and rotate them without a cache clear or a worker restart. Return null when the issuer has
 * no certificate. The default (ConfigCertificateProvider) returns `nowo_verifactu.aeat.certificate_path` / `_password`
 * for every issuer. Do not cache decrypted contents in the service across requests (FrankenPHP worker mode).
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
interface CertificateProviderInterface
{
    /**
     * Returns the certificate to use for the issuer, or null when there is none.
     */
    public function getCertificate(string $issuerNif): ?Certificate;
}
