<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Certificate;

use JsonSerializable;
use LogicException;
use SensitiveParameter;
use SensitiveParameterValue;

use function in_array;
use function is_string;

use const PATHINFO_EXTENSION;

/**
 * The AEAT client certificate for one call: a file path, or the certificate contents (PKCS#12 or PEM with its private
 * key) a host keeps in its database.
 *
 * The contents and the password are kept in private properties wrapped in PHP's SensitiveParameterValue, so they never
 * show in var_dump / print_r / var_export, array casts, get_object_vars(), Symfony's VarDumper, json_encode() nor
 * exception traces; serialize() is refused. Read them only through contents() / password(), right where they are
 * used.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class Certificate implements JsonSerializable
{
    public const FORMAT_PKCS12 = 'p12';
    public const FORMAT_PEM    = 'pem';

    private const MASK = '***';

    private readonly SensitiveParameterValue $contents;

    private readonly SensitiveParameterValue $password;

    private function __construct(
        public readonly ?string $path,
        #[SensitiveParameter]
        ?string $contents,
        #[SensitiveParameter]
        ?string $password,
        public readonly string $format,
    ) {
        $this->contents = new SensitiveParameterValue($contents);
        $this->password = new SensitiveParameterValue($password);
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
     * The certificate contents (null for a certificate given by path).
     */
    public function contents(): ?string
    {
        $contents = $this->contents->getValue();

        return is_string($contents) ? $contents : null;
    }

    /**
     * The certificate password (null when there is none).
     */
    public function password(): ?string
    {
        $password = $this->password->getValue();

        return is_string($password) ? $password : null;
    }

    /**
     * Masked: the path and the format only.
     *
     * @return array{path: ?string, contents: ?string, password: ?string, format: string}
     */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /**
     * Masked: the path and the format only.
     *
     * @return array{path: ?string, contents: ?string, password: ?string, format: string}
     */
    public function __debugInfo(): array
    {
        return [
            'path'     => $this->path,
            'contents' => $this->contents() === null ? null : self::MASK,
            'password' => $this->password() === null ? null : self::MASK,
            'format'   => $this->format,
        ];
    }

    /**
     * A certificate is never serialized (cache, message queue, session): ask the provider again.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A Veri*Factu certificate cannot be serialized: ask the certificate provider again where it is needed.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A Veri*Factu certificate cannot be unserialized.');
    }
}
