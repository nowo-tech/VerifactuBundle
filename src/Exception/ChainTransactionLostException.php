<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Exception;

use RuntimeException;
use Throwable;

/**
 * The database ended the host's whole transaction while the chain lock ran inside it (typically a MySQL / MariaDB
 * deadlock: InnoDB rolls back the entire transaction, so the savepoint of the chain lock no longer exists), or the
 * bundle found the new chain head gone from the host's transaction before an AEAT submission.
 *
 * MySQL / MariaDB ($connectionClosed true): the bundle has closed the connection (DBAL's transaction nesting level is
 * back to 0; the next statement reconnects), so nothing keeps writing outside a transaction. Everything the host did in
 * that transaction is gone: roll back nothing more. PostgreSQL and other platforms ($connectionClosed false): the
 * (aborted) transaction is still open, roll it back. In both cases `if ($connection->isTransactionActive())
 * $connection->rollBack();` is right. Then retry the WHOLE unit of work (open the transaction again, redo the host's
 * writes, call process() again), never process() alone. getPrevious() is the database error (usually a DBAL
 * RetryableException), or null when the head read returned another head.
 *
 * Inside Doctrine's Connection::transactional() on MySQL / MariaDB, its rollBack() finds no open transaction and throws
 * NoActiveTransaction with this exception as its previous one: look for it along getPrevious().
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class ChainTransactionLostException extends RuntimeException
{
    /**
     * Wraps the database error that ended the transaction (null when the head read returned another head).
     *
     * @param bool $connectionClosed whether the bundle closed the connection (MySQL / MariaDB); false when the host's
     *                               transaction is still open and must be rolled back
     */
    public function __construct(?Throwable $previous = null, public readonly bool $connectionClosed = true)
    {
        parent::__construct(
            $connectionClosed
                ? 'The database rolled back your whole transaction while the Veri*Factu chain lock ran inside it; the connection was closed. Restart your unit of work (transaction, your writes and process()), not process() alone.'
                : 'Your transaction can no longer commit the Veri*Factu chain head (the database aborted it). Roll it back and restart your unit of work (transaction, your writes and process()), not process() alone.',
            0,
            $previous,
        );
    }
}
