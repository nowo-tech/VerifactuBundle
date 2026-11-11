<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Certificate;

use RuntimeException;
use SensitiveParameter;

use function count;
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
 * - removed when the call returns or throws (finally), when the instance is destroyed, and after exit(), a fatal
 *   error or a max_execution_time timeout (not after SIGKILL) by one shutdown function per process that removes the
 *   files still pending.
 *
 * The only static state is that registry of pending files: an entry is added when a file is created and removed as
 * soon as the file is deleted, so it holds the files of the calls in flight (normally none) and never grows across
 * worker requests. A certificate given by path is passed through untouched.
 *
 * @internal
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class TemporaryCertificateFile
{
    /**
     * Directories of the files not deleted yet (the calls in flight), for the shutdown cleanup after a fatal error.
     * Bounded: each entry is removed when its file is deleted (finally / __destruct).
     *
     * @var array<string, true>
     */
    // @phpstan-ignore frankenphp.worker.noMutableStaticProperty (registry of in-flight files only; emptied as each file is deleted)
    private static array $pending = [];

    // @phpstan-ignore frankenphp.worker.noMutableStaticProperty (set once per process so a single shutdown handler exists)
    private static bool $shutdownRegistered = false;

    private function __construct(
        private readonly string $directory,
        public readonly string $path,
    ) {
        // @igor-ignore - Registry of in-flight files only: the entry is removed when the file is deleted.
        // @phpstan-ignore frankenphp.worker.noMutableStaticProperty (in-flight registry, emptied as each file is deleted)
        self::$pending[$directory] = true;
        if (!self::$shutdownRegistered) {
            // @igor-ignore - Set once per process: a single shutdown handler, never one per file or per request.
            // @phpstan-ignore frankenphp.worker.noMutableStaticProperty (set once per process)
            self::$shutdownRegistered = true;
            // @igor-ignore - One handler per process; it only deletes files a fatal error / exit() left pending.
            // @phpstan-ignore frankenphp.worker.noRegisterShutdownFunction (one handler per process for files left by a fatal error; request cleanup is finally / __destruct)
            register_shutdown_function(self::removePending(...));
        }
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
     * Removes the file and its directory, and drops it from the pending registry (idempotent).
     */
    public function remove(): void
    {
        self::removeDirectory($this->directory);
        // @igor-ignore - Registry of in-flight files only: the entry of a deleted file.
        unset(self::$pending[$this->directory]);
    }

    /**
     * Number of files not deleted yet (the calls in flight); 0 between calls.
     */
    public static function pendingCount(): int
    {
        return count(self::$pending);
    }

    /**
     * Deletes every file still pending (the shutdown handler; also safe to call any time).
     */
    public static function removePending(): void
    {
        foreach (array_keys(self::$pending) as $directory) {
            self::removeDirectory($directory);
            // @igor-ignore - Registry of in-flight files only: the entry of a deleted file.
            unset(self::$pending[$directory]);
        }
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
