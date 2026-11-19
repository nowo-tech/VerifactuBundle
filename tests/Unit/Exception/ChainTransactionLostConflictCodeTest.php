<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Exception;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\CommitMark;
use Nowo\VerifactuBundle\Exception\ChainTransactionLostException;
use PHPUnit\Framework\TestCase;

/**
 * The bundle answers no HTTP itself: lock conflicts surface to the host as the DBAL exception, or as
 * ChainTransactionLostException wrapping it. This pins the contract with a host on nowo-tech/transaction-doctrine
 * (REQ-RUNTIME-003 rule 6, docs/CONCURRENCY.md): both are transient, so the host's conflict responder answers 409 +
 * Retry-After + CONFLICT_001, or CONFLICT_002 with no Retry-After when something of the request committed earlier.
 * Unique violations and connection loss are not conflicts.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class ChainTransactionLostConflictCodeTest extends TestCase
{
    public function testLostChainTransactionIsARetrySafeConflict(): void
    {
        $failure = new ChainTransactionLostException(new DeadlockException(self::driverException('40001', 1213), null));

        self::assertTrue((new TransientFailureClassifier())->isTransient($failure));
        self::assertSame(ConflictCode::RetrySafe, ConflictCode::forFailure($failure));
        self::assertSame(ConflictCode::RETRY_SAFE, ConflictCode::forFailure($failure)->value);
    }

    public function testLockWaitTimeoutInsideTheChainLockIsARetrySafeConflict(): void
    {
        $failure = new LockWaitTimeoutException(self::driverException('HY000', 1205), null);

        self::assertTrue((new TransientFailureClassifier())->isTransient($failure));
        self::assertSame(ConflictCode::RetrySafe, ConflictCode::forFailure($failure));
    }

    public function testAnEarlierCommitOfTheSameRequestMakesItCommitted(): void
    {
        $ledger  = new CommitLedger();
        $mark    = new CommitMark($ledger);
        $failure = new ChainTransactionLostException(new DeadlockException(self::driverException('40001', 1213), null));

        self::assertSame(ConflictCode::RetrySafe, ConflictCode::forFailure($failure, $mark));
        $ledger->record();
        self::assertSame(ConflictCode::Committed, ConflictCode::forFailure($failure, $mark));
        self::assertSame(ConflictCode::COMMITTED, ConflictCode::forFailure($failure, $mark)->value);
    }

    public function testUniqueViolationAndConnectionLossAreNotConflicts(): void
    {
        $classifier = new TransientFailureClassifier();

        self::assertFalse($classifier->isTransient(new UniqueConstraintViolationException(self::driverException('23000', 1062), null)));
        self::assertFalse($classifier->isTransient(new ConnectionLost(self::driverException('HY000', 2006), null)));
    }

    private static function driverException(string $sqlState, int $code): AbstractException
    {
        return new class('database error', $sqlState, $code) extends AbstractException {};
    }
}
