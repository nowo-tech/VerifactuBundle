<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Exception;

use RuntimeException;
use Throwable;

/**
 * The database ended the host's whole transaction while the chain lock ran inside it (typically a MySQL / MariaDB
 * deadlock: InnoDB rolls back the entire transaction, so the savepoint of the chain lock no longer exists).
 *
 * The bundle has closed the connection (DBAL's transaction nesting level is back to 0; the next statement reconnects),
 * so nothing keeps writing outside a transaction. Everything the host did in that transaction is gone: roll back
 * nothing more, and retry the WHOLE unit of work (open the transaction again, redo the host's writes, call process()
 * again), never process() alone. Do not run any further statement of the lost unit on the connection before starting
 * the new transaction: it would autocommit on the reconnected session (a partial write). getPrevious() is the database
 * error (usually a DBAL RetryableException), or null when the bundle found the loss by reading the chain head again
 * before an AEAT submission (a listener swallowed the error).
 *
 * Do not call process() inside Doctrine's Connection::transactional() (or wrapInTransaction()): its rollBack() after
 * this exception finds no open transaction and throws a NoActiveTransaction / ConnectionException that hides it. Use
 * beginTransaction() / commit() / rollBack() yourself (skip the rollBack() on this exception) or transaction-doctrine's
 * runner.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class ChainTransactionLostException extends RuntimeException
{
    /**
     * Wraps the database error that ended the transaction (null when the loss was found by reading the head again).
     */
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('The database rolled back your whole transaction while the Veri*Factu chain lock ran inside it; the connection was closed. Restart your unit of work (transaction, your writes and process()), not process() alone.', 0, $previous);
    }
}
