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
    public function __construct(
        public readonly string $issuerNif,
        public readonly string $invoiceSeriesNumber,
        public readonly string $issueDate,
        public readonly string $hash,
    ) {
    }
}
