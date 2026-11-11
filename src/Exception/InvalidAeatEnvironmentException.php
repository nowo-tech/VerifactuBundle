<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Exception;

use InvalidArgumentException;

use function sprintf;

/**
 * An AEAT environment other than exactly "sandbox" or "production" (the call is refused, never routed to either).
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class InvalidAeatEnvironmentException extends InvalidArgumentException
{
    /**
     * Receives the refused value.
     */
    public function __construct(string $environment)
    {
        parent::__construct(sprintf('Unknown AEAT environment "%s": only "sandbox" and "production" are accepted (map your own values, e.g. "test", in your AeatEnvironmentProviderInterface).', $environment));
    }
}
