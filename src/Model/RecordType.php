<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Model;

/**
 * AEAT billing record operation type.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
enum RecordType: string
{
    case Alta      = 'Alta';
    case Anulacion = 'Anulacion';
}
