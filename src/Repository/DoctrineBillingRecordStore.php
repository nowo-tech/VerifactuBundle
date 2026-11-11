<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\VerifactuBundle\Doctrine\DbalRowWriter;
use Nowo\VerifactuBundle\Entity\BillingRecordEntry;
use Nowo\VerifactuBundle\Model\AeatEnvironment;
use Nowo\VerifactuBundle\Model\AeatRecordResponse;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Model\StoredBillingRecord;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Throwable;

use function count;
use function sprintf;

/**
 * Doctrine record log (record_store.storage: doctrine), table `verifactu_billing_record`.
 *
 * Like DoctrineHashChainRepository it never flushes: rows are inserted and updated with DBAL on the connection of the
 * EntityManager that maps BillingRecordEntry, and read with array-hydrated DQL. Called inside withChainLock(), the
 * append joins the same transaction as the chain head (and the host's transaction, when one is open).
 *
 * Position: the record's previous hash is the head read under the chain lock, so the position is the position of that
 * previous record + 1, found with a locking read (it sees the latest committed rows whatever the transaction's
 * snapshot). Only when the previous record is not in the log (an empty log, or a chain started before the log was
 * enabled) it continues after the last stored position. The primary key (issuer_nif, position) and the unique
 * (issuer_nif, hash) are a last guard: a duplicate fails, it is never written as another position.
 *
 * findByIssuer() reads the log in pages of PAGE_SIZE records, so verifying a long chain keeps memory flat.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[AsAlias(id: self::SERVICE_NAME, public: true)]
final class DoctrineBillingRecordStore implements BillingRecordStoreInterface, SubmissionQueueInterface
{
    public const SERVICE_NAME = 'nowo_verifactu.repository.doctrine_billing_record_store';

    public const PAGE_SIZE = 500;

    /**
     * Receives the Doctrine registry managing the BillingRecordEntry entity.
     */
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
    ) {
    }

    /**
     * Inserts the record after the last position of its issuer.
     */
    public function append(BillingRecord $record): int
    {
        $entityManager = $this->resolveEntityManager();
        $key           = $this->normalizeKey($record->issuerNif);

        $position = $this->nextPosition($entityManager, $key, strtoupper(trim($record->previousHash ?? '')));

        DbalRowWriter::insert($entityManager, BillingRecordEntry::class, [
            'issuerNif'           => $key,
            'position'            => $position,
            'recordType'          => $record->recordType->value,
            'invoiceSeriesNumber' => trim($record->invoiceSeriesNumber),
            'issueDate'           => trim($record->issueDate),
            'invoiceType'         => trim($record->invoiceType),
            'totalTaxAmount'      => trim($record->totalTaxAmount),
            'totalAmount'         => trim($record->totalAmount),
            'generatedAt'         => trim($record->generatedAt),
            'previousHash'        => strtoupper(trim($record->previousHash ?? '')),
            'hash'                => strtoupper(trim((string) $record->hash)),
            'issuerName'          => $record->issuerName,
            'xml'                 => (string) $record->xml,
            'signedXml'           => $record->signedXml,
            'submissionStatus'    => StoredBillingRecord::STATUS_PENDING,
            'submissionReference' => null,
            'environment'         => $record->environment?->value,
            'createdAt'           => new DateTimeImmutable(),
        ]);

        return $position;
    }

    /**
     * Returns the records of the issuer in chain order, read page by page.
     *
     * @return iterable<StoredBillingRecord>
     */
    public function findByIssuer(string $issuerNif): iterable
    {
        $entityManager = $this->resolveEntityManager();
        $key           = $this->normalizeKey($issuerNif);
        $after         = 0;

        do {
            /** @var list<array<string, mixed>> $rows */
            $rows = $entityManager->createQueryBuilder()
                ->select('e')
                ->from(BillingRecordEntry::class, 'e')
                ->where('e.issuerNif = :issuerNif')
                ->andWhere('e.position > :after')
                ->setParameter('issuerNif', $key)
                ->setParameter('after', $after)
                ->orderBy('e.position', 'ASC')
                ->setMaxResults(self::PAGE_SIZE)
                ->getQuery()
                ->getArrayResult();

            foreach ($rows as $row) {
                $after = (int) $row['position'];

                yield $this->toStored($row);
            }
        } while (count($rows) === self::PAGE_SIZE);
    }

    /**
     * Records the outcome of the AEAT submission (DBAL update; never a flush).
     */
    public function markSubmitted(string $issuerNif, string $hash, bool $accepted, ?string $reference = null): void
    {
        DbalRowWriter::update(
            $this->resolveEntityManager(),
            BillingRecordEntry::class,
            [
                'submissionStatus'    => $accepted ? StoredBillingRecord::STATUS_ACCEPTED : StoredBillingRecord::STATUS_FAILED,
                'submissionReference' => $reference,
            ],
            ['issuerNif' => $this->normalizeKey($issuerNif), 'hash' => strtoupper(trim($hash))],
        );
    }

    public function hasSubmissionInFlight(string $issuerNif, DateTimeImmutable $now, int $claimTimeoutSeconds): bool
    {
        $count = $this->entityManager()->createQueryBuilder()
            ->select('COUNT(e.position)')
            ->from(BillingRecordEntry::class, 'e')
            ->where('e.issuerNif = :issuerNif')
            ->andWhere('e.submissionStatus = :sending')
            ->andWhere('e.submissionClaimedAt >= :cutoff')
            ->setParameter('issuerNif', $this->normalizeKey($issuerNif))
            ->setParameter('sending', StoredBillingRecord::STATUS_SENDING)
            ->setParameter('cutoff', $this->cutoff($now, $claimTimeoutSeconds), Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    public function countPending(string $issuerNif, DateTimeImmutable $now, int $claimTimeoutSeconds): int
    {
        return (int) $this->waitingQuery($issuerNif, $now, $claimTimeoutSeconds)
            ->select('COUNT(e.position)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function claimNext(string $issuerNif, int $limit, string $claimToken, DateTimeImmutable $now, int $claimTimeoutSeconds): array
    {
        $entityManager = $this->entityManager();
        $rows          = $this->waitingQuery($issuerNif, $now, $claimTimeoutSeconds)
            ->select('e')
            ->orderBy('e.position', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getArrayResult();

        $claimed = [];
        $seen    = [];
        foreach ($rows as $row) {
            $stored = $this->toStored($row);
            $key    = AeatRecordResponse::key($stored->record->issuerNif, $stored->record->invoiceSeriesNumber, $stored->record->issueDate, $stored->record->recordType);
            if (isset($seen[$key]) || ($claimed !== [] && $stored->record->environment !== $claimed[0]->record->environment)) {
                break;
            }
            $seen[$key] = true;
            $claimed[]  = $stored;
        }
        if ($claimed === []) {
            return [];
        }

        // The claimed records are the waiting ones between the first and the last claimed position: one statement.
        $column = static fn (string $field): string => DbalRowWriter::column($entityManager, BillingRecordEntry::class, $field);
        $entityManager->getConnection()->executeStatement(
            'UPDATE ' . DbalRowWriter::table($entityManager, BillingRecordEntry::class)
            . ' SET ' . $column('submissionStatus') . ' = ?, ' . $column('submissionClaim') . ' = ?, ' . $column('submissionClaimedAt') . ' = ?, '
            . $column('submissionAttempts') . ' = ' . $column('submissionAttempts') . ' + 1'
            . ' WHERE ' . $column('issuerNif') . ' = ? AND ' . $column('position') . ' BETWEEN ? AND ?'
            . ' AND (' . $column('submissionStatus') . ' = ? OR (' . $column('submissionStatus') . ' = ? AND ' . $column('submissionClaimedAt') . ' < ?))',
            [
                StoredBillingRecord::STATUS_SENDING,
                $claimToken,
                $now,
                $this->normalizeKey($issuerNif),
                $claimed[0]->position,
                $claimed[count($claimed) - 1]->position,
                StoredBillingRecord::STATUS_PENDING,
                StoredBillingRecord::STATUS_SENDING,
                $this->cutoff($now, $claimTimeoutSeconds),
            ],
            [
                Types::STRING, Types::STRING, Types::DATETIME_IMMUTABLE, Types::STRING, Types::INTEGER, Types::INTEGER,
                Types::STRING, Types::STRING, Types::DATETIME_IMMUTABLE,
            ],
        );

        return array_map(static fn (StoredBillingRecord $stored): StoredBillingRecord => new StoredBillingRecord(
            $stored->position,
            $stored->record,
            StoredBillingRecord::STATUS_SENDING,
            $stored->submissionReference,
            $stored->submissionAttempts + 1,
            $stored->submissionErrorCode,
            $stored->submissionError,
        ), $claimed);
    }

    public function recordOutcomes(string $issuerNif, string $claimToken, array $outcomes, DateTimeImmutable $now): array
    {
        $entityManager = $this->entityManager();
        $connection    = $entityManager->getConnection();
        $updated       = [];
        $connection->beginTransaction();
        try {
            foreach ($outcomes as $outcome) {
                $hash   = strtoupper(trim((string) $outcome->stored->record->hash));
                $answer = $outcome->status !== null;
                $count  = DbalRowWriter::update($entityManager, BillingRecordEntry::class, [
                    'submissionStatus'    => $outcome->storeStatus(),
                    'submissionReference' => $answer ? $outcome->reference : $outcome->stored->submissionReference,
                    'submissionErrorCode' => $outcome->errorCode,
                    'submissionError'     => $outcome->errorDescription,
                    'submissionClaim'     => null,
                    'submissionClaimedAt' => null,
                    'submittedAt'         => $answer ? $now : null,
                ], [
                    'issuerNif'       => $this->normalizeKey($issuerNif),
                    'hash'            => $hash,
                    'submissionClaim' => $claimToken,
                ]);
                if ($count > 0) {
                    $updated[] = $hash;
                }
            }
            $connection->commit();
            // @codeCoverageIgnoreStart
        } catch (Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $exception;
            // @codeCoverageIgnoreEnd
        }

        return $updated;
    }

    public function releaseClaim(string $issuerNif, string $claimToken, string $failure): int
    {
        return DbalRowWriter::update($this->entityManager(), BillingRecordEntry::class, [
            'submissionStatus'    => StoredBillingRecord::STATUS_PENDING,
            'submissionClaim'     => null,
            'submissionClaimedAt' => null,
            'submissionError'     => mb_substr($failure, 0, 1500),
        ], [
            'issuerNif'        => $this->normalizeKey($issuerNif),
            'submissionClaim'  => $claimToken,
            'submissionStatus' => StoredBillingRecord::STATUS_SENDING,
        ]);
    }

    public function issuersWithPendingRecords(): array
    {
        $rows = $this->entityManager()->createQueryBuilder()
            ->select('DISTINCT e.issuerNif')
            ->from(BillingRecordEntry::class, 'e')
            ->where('e.submissionStatus IN (:statuses)')
            ->setParameter('statuses', [StoredBillingRecord::STATUS_PENDING, StoredBillingRecord::STATUS_SENDING])
            ->orderBy('e.issuerNif', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_values(array_map(static fn (array $row): string => (string) $row['issuerNif'], $rows));
    }

    public function isInsideTransaction(): bool
    {
        return $this->entityManager()->getConnection()->isTransactionActive();
    }

    /**
     * Pending records, plus those of a stale claim (the process that claimed them died).
     */
    private function waitingQuery(string $issuerNif, DateTimeImmutable $now, int $claimTimeoutSeconds): QueryBuilder
    {
        return $this->entityManager()->createQueryBuilder()
            ->from(BillingRecordEntry::class, 'e')
            ->where('e.issuerNif = :issuerNif')
            ->andWhere('e.submissionStatus = :pending OR (e.submissionStatus = :sending AND e.submissionClaimedAt < :cutoff)')
            ->setParameter('issuerNif', $this->normalizeKey($issuerNif))
            ->setParameter('pending', StoredBillingRecord::STATUS_PENDING)
            ->setParameter('sending', StoredBillingRecord::STATUS_SENDING)
            ->setParameter('cutoff', $this->cutoff($now, $claimTimeoutSeconds), Types::DATETIME_IMMUTABLE);
    }

    private function cutoff(DateTimeImmutable $now, int $claimTimeoutSeconds): DateTimeImmutable
    {
        return $now->modify(sprintf('-%d seconds', max(1, $claimTimeoutSeconds)));
    }

    private function entityManager(): EntityManagerInterface
    {
        return $this->resolveEntityManager();
    }

    /**
     * Position of the previous record + 1, or the last stored position + 1 when the previous record is not in the log.
     * Both reads are locking reads inside a transaction (the chain lock), so they never see a stale snapshot.
     */
    private function nextPosition(EntityManagerInterface $entityManager, string $key, string $previousHash): int
    {
        $lock = $entityManager->getConnection()->isTransactionActive();

        if ($previousHash !== '') {
            $rows = $this->positions($this->positionQuery($entityManager, $key)
                ->andWhere('e.hash = :hash')
                ->setParameter('hash', $previousHash), $lock);
            if ($rows !== []) {
                return $rows[0] + 1;
            }
        }

        $rows = $this->positions($this->positionQuery($entityManager, $key)
            ->orderBy('e.position', 'DESC')
            ->setMaxResults(1), $lock);

        return ($rows[0] ?? 0) + 1;
    }

    /**
     * Runs a position query, as a locking read (SELECT … FOR UPDATE) when a transaction is open.
     *
     * @return list<int>
     */
    private function positions(QueryBuilder $queryBuilder, bool $lock): array
    {
        /** @var list<array{position: int|string}> $rows */
        $rows = $lock
            ? $queryBuilder->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->getArrayResult()
            : $queryBuilder->getQuery()->getArrayResult();

        return array_map(static fn (array $row): int => (int) $row['position'], $rows);
    }

    private function positionQuery(EntityManagerInterface $entityManager, string $key): QueryBuilder
    {
        return $entityManager->createQueryBuilder()
            ->select('e.position')
            ->from(BillingRecordEntry::class, 'e')
            ->where('e.issuerNif = :issuerNif')
            ->setParameter('issuerNif', $key);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toStored(array $row): StoredBillingRecord
    {
        return new StoredBillingRecord(
            (int) $row['position'],
            new BillingRecord(
                RecordType::from((string) $row['recordType']),
                (string) $row['issuerNif'],
                (string) $row['invoiceSeriesNumber'],
                (string) $row['issueDate'],
                (string) $row['invoiceType'],
                (string) $row['totalTaxAmount'],
                (string) $row['totalAmount'],
                (string) $row['generatedAt'],
                previousHash: (string) $row['previousHash'],
                hash: (string) $row['hash'],
                xml: (string) $row['xml'],
                signedXml: isset($row['signedXml']) ? (string) $row['signedXml'] : null,
                issuerName: isset($row['issuerName']) ? (string) $row['issuerName'] : null,
                environment: isset($row['environment']) ? AeatEnvironment::fromValue((string) $row['environment']) : null,
            ),
            (string) $row['submissionStatus'],
            isset($row['submissionReference']) ? (string) $row['submissionReference'] : null,
            (int) ($row['submissionAttempts'] ?? 0),
            isset($row['submissionErrorCode']) ? (int) $row['submissionErrorCode'] : null,
            isset($row['submissionError']) ? (string) $row['submissionError'] : null,
        );
    }

    private function resolveEntityManager(): EntityManagerInterface
    {
        $entityManager = $this->managerRegistry->getManagerForClass(BillingRecordEntry::class);
        if (!$entityManager instanceof EntityManagerInterface) {
            throw new LogicException('No Doctrine ORM entity manager is configured for ' . BillingRecordEntry::class . '.');
        }

        return $entityManager;
    }

    private function normalizeKey(string $issuerNif): string
    {
        return strtoupper(trim($issuerNif));
    }
}
