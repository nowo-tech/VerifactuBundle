<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Certificate;

use RuntimeException;
use SensitiveParameter;

use function strlen;

/**
 * Fallback for runtimes whose cURL cannot take a certificate from memory (CertificateAwareSoapTransportInterface is
 * preferred: no file at all). Gives a file path for a certificate given by contents:
 *
 * - a fresh private directory (random name, created with mkdir(), which never follows an existing path or symlink)
 *   under the system temp dir, set to 0700;
 * - the key file created exclusively inside it (fopen "x": O_CREAT | O_EXCL) and set to 0600 before the secret is
 *   written; the process umask is never changed (it is process-wide and would leak into other worker requests);
 * - every return value checked;
 * - removed when the call returns or throws (finally), when the instance is destroyed, and by a shutdown function
 *   of that file only after exit(), a fatal error or a max_execution_time timeout (not after SIGKILL).
 *
 * No static state: every call has its own instance. A certificate given by path is passed through untouched.
 *
 * @internal
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class TemporaryCertificateFile
{
    private function __construct(
        private readonly string $directory,
        public readonly string $path,
    ) {
        // Only for a fatal error / exit() while the file exists: the closure captures the directory, never the
        // instance nor the certificate, and removing an already removed directory is a no-op. The blob transport
        // is the primary path, so this fallback (and its registration) is rare.
        // @igor-ignore - One handler per temporary file (rare fallback), capturing only its directory path.
        // @phpstan-ignore frankenphp.worker.noRegisterShutdownFunction (cleanup of one file after a fatal error; the request cleanup is finally / __destruct)
        register_shutdown_function(static function () use ($directory): void {
            // @codeCoverageIgnoreStart
            self::removeDirectory($directory);
            // @codeCoverageIgnoreEnd
        });
    }

    /**
     * Removes the file and its directory when the instance is destroyed (e.g. an unexpected exit from with()).
     */
    // @igor-ignore - Per-call value object, never a shared service: destroyed when its own call ends.
    public function __destruct()
    {
        $this->remove();
    }

    /**
     * Runs $operation with a path to the certificate and returns its result.
     *
     * @template T
     *
     * @param callable(string): T $operation
     *
     * @return T
     */
    public static function with(#[SensitiveParameter] Certificate $certificate, callable $operation): mixed
    {
        if ($certificate->path !== null) {
            return $operation($certificate->path);
        }

        $file = self::create($certificate);
        try {
            return $operation($file->path);
        } finally {
            // @igor-ignore - $file is this call's own temporary file, not a shared service.
            $file->remove();
        }
    }

    /**
     * Removes the file and its directory (idempotent).
     */
    public function remove(): void
    {
        self::removeDirectory($this->directory);
    }

    private static function create(#[SensitiveParameter] Certificate $certificate): self
    {
        $directory = sys_get_temp_dir() . '/verifactu-cert-' . bin2hex(random_bytes(16));
        // @codeCoverageIgnoreStart
        if (!@mkdir($directory, 0o700)) {
            throw new RuntimeException('Unable to create a private directory for the AEAT certificate.');
        }
        // @codeCoverageIgnoreEnd

        $file = new self($directory, $directory . '/certificate.' . $certificate->format);
        try {
            // @codeCoverageIgnoreStart
            if (!@chmod($directory, 0o700)) {
                throw new RuntimeException('Unable to protect the private directory of the AEAT certificate.');
            }
            // @codeCoverageIgnoreEnd
            $handle = @fopen($file->path, 'x');
            // @codeCoverageIgnoreStart
            if ($handle === false) {
                throw new RuntimeException('Unable to create the temporary AEAT certificate file.');
            }
            // @codeCoverageIgnoreEnd
            try {
                // @codeCoverageIgnoreStart
                if (!@chmod($file->path, 0o600)) {
                    throw new RuntimeException('Unable to protect the temporary AEAT certificate file.');
                }
                // @codeCoverageIgnoreEnd
                $contents = (string) $certificate->contents();
                $written  = fwrite($handle, $contents);
            } finally {
                fclose($handle);
            }
            // @codeCoverageIgnoreStart
            if ($written !== strlen($contents)) {
                throw new RuntimeException('Unable to write the temporary AEAT certificate file.');
            }
        } catch (RuntimeException $exception) {
            // @igor-ignore - $file is this call's own temporary file, not a shared service.
            $file->remove();

            throw $exception;
            // @codeCoverageIgnoreEnd
        }

        return $file;
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach ((array) glob($directory . '/*') as $entry) {
            @unlink((string) $entry);
        }
        @rmdir($directory);
    }
}
