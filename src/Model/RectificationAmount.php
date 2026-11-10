<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Model;

/**
 * ImporteRectificacion (AEAT DesgloseRectificacionType) of a rectifying invoice "por sustitución" (TipoRectificativa S):
 * the base, tax and optional equivalence surcharge of the rectified invoice.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class RectificationAmount
{
    /**
     * Creates the rectified amounts (decimal strings).
     */
    public function __construct(
        public readonly string $base,
        public readonly string $tax,
        public readonly ?string $surcharge = null,
    ) {
    }
}
