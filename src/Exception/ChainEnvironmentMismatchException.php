<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Exception;

use Nowo\VerifactuBundle\Model\AeatEnvironment;
use RuntimeException;

use function sprintf;

/**
 * The issuer's chain head belongs to another AEAT environment than the current call (e.g. the last record went to the
 * sandbox and the provider now says production). Nothing was chained. Reset the issuer's chain explicitly
 * (`nowo:verifactu:reset-chain --issuer=<NIF>` or HashChainRepositoryInterface::reset()) before the first record of the
 * new environment, so it starts a new chain (PrimerRegistro) instead of following a test record.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class ChainEnvironmentMismatchException extends RuntimeException
{
    /**
     * Receives the issuer and both environments.
     */
    public function __construct(
        public readonly string $issuerNif,
        public readonly AeatEnvironment $chainEnvironment,
        public readonly AeatEnvironment $currentEnvironment,
    ) {
        parent::__construct(sprintf(
            'The Veri*Factu chain of this issuer belongs to the AEAT "%s" environment, but this call is for "%s". Reset the issuer\'s chain first (nowo:verifactu:reset-chain).',
            $chainEnvironment->value,
            $currentEnvironment->value,
        ));
    }
}
