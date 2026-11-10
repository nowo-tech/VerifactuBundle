<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\VerifactuBundle\Doctrine\DbalRowWriter;
use Nowo\VerifactuBundle\Entity\BillingRecordEntry;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Model\StoredBillingRecord;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use function count;

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
final class DoctrineBillingRecordStore implements BillingRecordStoreInterface
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
            ),
            (string) $row['submissionStatus'],
            isset($row['submissionReference']) ? (string) $row['submissionReference'] : null,
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
