<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Model;

/**
 * Last billing record fingerprint state for hash chain linking.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class HashChainState
{
    /**
     * Creates the chain head state of an issuer.
     *
     * $environment is the AEAT environment of the chain (null for heads written before 1.6: the configured
     * `aeat.environment` applies).
     */
    public function __construct(
        public readonly string $issuerNif,
        public readonly string $invoiceSeriesNumber,
        public readonly string $issueDate,
        public readonly string $hash,
        public readonly ?AeatEnvironment $environment = null,
    ) {
    }
}
