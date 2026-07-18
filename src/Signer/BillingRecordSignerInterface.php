<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Signer;

/**
 * Signs billing record XML with XAdES for No-Veri*Factu systems.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
interface BillingRecordSignerInterface
{
    /**
     * Returns XML with an enveloped XAdES signature appended to the record root element.
     */
    public function sign(string $xml): string;
}
