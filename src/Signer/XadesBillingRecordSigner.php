<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Signer;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;
use Nowo\VerifactuBundle\Certificate\CertificateLoader;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * XAdES enveloped signer for AEAT billing records (No-Veri*Factu).
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[AsAlias(id: self::SERVICE_NAME, public: true)]
final class XadesBillingRecordSigner implements BillingRecordSignerInterface
{
    public const SERVICE_NAME = 'nowo_verifactu.signer.xades';

    public function __construct(
        private readonly CertificateLoader $certificateLoader,
        private readonly ?string $certificatePath = null,
        private readonly ?string $certificatePassword = null,
    ) {
    }

    public function sign(string $xml): string
    {
        if ($this->certificatePath === null || $this->certificatePath === '') {
            throw new InvalidArgumentException('A certificate path is required for XAdES signing.');
        }

        $material = $this->certificateLoader->load($this->certificatePath, $this->certificatePassword);

        $document                     = new DOMDocument();
        $document->preserveWhiteSpace = false;
        $document->formatOutput       = false;
        if (!@$document->loadXML($xml)) {
            throw new InvalidArgumentException('Unable to load billing record XML for signing.');
        }

        $root = $document->documentElement;
        // @codeCoverageIgnoreStart
        if (!$root instanceof DOMElement) {
            throw new InvalidArgumentException('Billing record XML has no root element.');
        }
        // @codeCoverageIgnoreEnd

        $dsig = new XMLSecurityDSig();
        $dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
        // XMLSecurityDSig::addReference PHPDoc expects DOMDocument (library signs documentElement).
        $dsig->addReference(
            $document,
            XMLSecurityDSig::SHA256,
            ['http://www.w3.org/2000/09/xmldsig#enveloped-signature'],
            ['force_uri' => true],
        );

        $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $key->loadKey($material['private_key'], false);
        $dsig->sign($key);
        $dsig->add509Cert($material['certificate'], true, false, ['issuerSerial' => true]);
        $dsig->appendSignature($root);

        return $document->saveXML() ?: $xml;
    }
}
