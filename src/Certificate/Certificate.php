<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Certificate;

use SensitiveParameter;

use function in_array;

use const PATHINFO_EXTENSION;

/**
 * The AEAT client certificate for one call: a file path, or the certificate contents (PKCS#12 or PEM with its private
 * key) a host keeps in its database. The password and the contents never appear in var_dump / exceptions
 * (`__debugInfo()`), and the bundle never logs them.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class Certificate
{
    public const FORMAT_PKCS12 = 'p12';
    public const FORMAT_PEM    = 'pem';

    private function __construct(
        public readonly ?string $path,
        #[SensitiveParameter]
        public readonly ?string $contents,
        #[SensitiveParameter]
        public readonly ?string $password,
        public readonly string $format,
    ) {
    }

    /**
     * A certificate file (.p12 / .pfx = PKCS#12, anything else = PEM).
     */
    public static function fromPath(string $path, #[SensitiveParameter] ?string $password = null): self
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return new self($path, null, $password, in_array($extension, ['p12', 'pfx'], true) ? self::FORMAT_PKCS12 : self::FORMAT_PEM);
    }

    /**
     * Certificate contents: PKCS#12 bytes ("p12") or a PEM certificate with its private key ("pem").
     */
    public static function fromContents(#[SensitiveParameter] string $contents, #[SensitiveParameter] ?string $password = null, string $format = self::FORMAT_PKCS12): self
    {
        return new self(null, $contents, $password, $format === self::FORMAT_PEM ? self::FORMAT_PEM : self::FORMAT_PKCS12);
    }

    /**
     * Hides the contents and the password from dumps.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['path' => $this->path, 'contents' => $this->contents === null ? null : '***', 'password' => $this->password === null ? null : '***', 'format' => $this->format];
    }
}
