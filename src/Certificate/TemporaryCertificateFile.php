<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Certificate;

use RuntimeException;

/**
 * Gives a file path for a certificate (cURL's mTLS options take a file): the certificate's own path, or the contents
 * written to a private (0600) temporary file that is deleted right after the call, also when it fails. Nothing stays
 * on disk nor in memory between requests.
 *
 * @internal
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class TemporaryCertificateFile
{
    /**
     * Runs $operation with a path to the certificate and returns its result.
     *
     * @template T
     *
     * @param callable(string): T $operation
     *
     * @return T
     */
    public static function with(Certificate $certificate, callable $operation): mixed
    {
        if ($certificate->path !== null) {
            return $operation($certificate->path);
        }

        $base = tempnam(sys_get_temp_dir(), 'verifactu-cert-');
        // @codeCoverageIgnoreStart
        if ($base === false) {
            throw new RuntimeException('Unable to create a temporary file for the AEAT certificate.');
        }
        // @codeCoverageIgnoreEnd
        $path = $base . '.' . $certificate->format;

        try {
            touch($path);
            chmod($path, 0o600);
            file_put_contents($path, (string) $certificate->contents);

            return $operation($path);
        } finally {
            @unlink($path);
            @unlink($base);
        }
    }
}
