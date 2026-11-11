<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Model;

use Nowo\VerifactuBundle\Exception\InvalidAeatEnvironmentException;

/**
 * The AEAT environment of a call: the only two values the bundle accepts, everywhere (configuration, providers, SOAP
 * endpoint, QR URL, chain head, record store).
 *
 * Anything else fails closed with InvalidAeatEnvironmentException: no value is ever guessed into production (nor
 * sandbox). A host that stores another vocabulary (e.g. "test") maps it explicitly in its provider.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
enum AeatEnvironment: string
{
    case Sandbox    = 'sandbox';
    case Production = 'production';

    /**
     * Returns the environment for exactly "sandbox" or "production"; throws on any other value (case and spaces
     * included).
     *
     * @throws InvalidAeatEnvironmentException
     */
    public static function fromValue(self|string $environment): self
    {
        if ($environment instanceof self) {
            return $environment;
        }

        return self::tryFrom($environment) ?? throw new InvalidAeatEnvironmentException($environment);
    }
}
