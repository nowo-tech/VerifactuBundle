<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\VerifactuBundle\Entity\BillingRecordEntry;
use Nowo\VerifactuBundle\Model\AeatEnvironment;
use Nowo\VerifactuBundle\Model\BillingRecordCriteria;
use Nowo\VerifactuBundle\Model\BillingRecordSort;
use Nowo\VerifactuBundle\Model\BillingRecordView;
use Nowo\VerifactuBundle\Model\RecordType;

use function array_slice;
use function count;

/**
 * Doctrine read port over `verifactu_billing_record` (record_store.storage: doctrine), 1.10.
 *
 * Reads with array-hydrated DQL on the EntityManager that maps BillingRecordEntry (the bundle's mapping, or the host's
 * when doctrine.register_mapping is false): no entity is ever managed, nothing is written, locked or flushed, and no
 * state is kept between calls (FrankenPHP worker mode without kernel reset). The XML columns are never selected.
 *
 * Invoice numbers are matched byte for byte: the database may compare them case- or pad-insensitively (MySQL /
 * MariaDB collations), so candidate rows are re-checked in PHP before they count. Issue dates are stored as
 * dd-mm-yyyy and compared through their yyyymmdd form (portable SUBSTRING / CONCAT).
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class DoctrineBillingRecordQuery implements BillingRecordQueryInterface
{
    public const SERVICE_NAME = 'nowo_verifactu.repository.doctrine_billing_record_query';

    /** Invoice numbers per IN list in findLatestByInvoices(). */
    public const INVOICE_CHUNK = 500;

    private const ISSUE_KEY = 'CONCAT(SUBSTRING(e.issueDate, 7, 4), SUBSTRING(e.issueDate, 4, 2), SUBSTRING(e.issueDate, 1, 2))';

    private const FIELDS = [
        'e.issuerNif', 'e.position', 'e.recordType', 'e.invoiceSeriesNumber', 'e.issueDate', 'e.invoiceType',
        'e.totalTaxAmount', 'e.totalAmount', 'e.generatedAt', 'e.previousHash', 'e.hash', 'e.issuerName',
        'e.environment', 'e.submissionStatus', 'e.submissionReference', 'e.submissionAttempts', 'e.submissionErrorCode',
        'e.submissionError', 'e.createdAt', 'e.submittedAt',
    ];

    /**
     * Receives the Doctrine registry managing the BillingRecordEntry entity.
     */
    public function __construct(
        private readonly ManagerRegistry $managerRegistry,
    ) {
    }

    public function page(BillingRecordCriteria $criteria, int $offset = 0, int $limit = 50): array
    {
        BillingRecordQueryPaging::assertPage($offset, $limit);

        $queryBuilder = $this->filtered($this->entityManager(), $criteria);
        if (!$queryBuilder instanceof QueryBuilder) {
            return [];
        }
        $queryBuilder->select(self::FIELDS);

        $direction = $criteria->sort === BillingRecordSort::PositionAsc || $criteria->sort === BillingRecordSort::IssueDateAsc ? 'ASC' : 'DESC';
        if ($criteria->sort === BillingRecordSort::IssueDateAsc || $criteria->sort === BillingRecordSort::IssueDateDesc) {
            $queryBuilder->addSelect(self::ISSUE_KEY . ' AS HIDDEN issueKey')->orderBy('issueKey', $direction);
        }
        $queryBuilder->addOrderBy('e.position', $direction)
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        return $this->views($queryBuilder, $criteria->issuerNif);
    }

    public function count(BillingRecordCriteria $criteria): int
    {
        $queryBuilder = $this->filtered($this->entityManager(), $criteria);
        if (!$queryBuilder instanceof QueryBuilder) {
            return 0;
        }

        return (int) $queryBuilder->select('COUNT(e.position)')->getQuery()->getSingleScalarResult();
    }

    public function findByPosition(string $issuerNif, int $position): ?BillingRecordView
    {
        $key = BillingRecordCriteria::normalizeIssuer($issuerNif);
        if ($key === '') {
            return null;
        }

        $queryBuilder = $this->scoped($this->entityManager(), $key)
            ->select(self::FIELDS)
            ->andWhere('e.position = :position')
            ->setParameter('position', $position);

        return $this->views($queryBuilder, $key)[0] ?? null;
    }

    public function findByInvoice(string $issuerNif, string $invoiceSeriesNumber): ?BillingRecordView
    {
        $key = BillingRecordCriteria::normalizeIssuer($issuerNif);
        if ($key === '') {
            return null;
        }

        $queryBuilder = $this->scoped($this->entityManager(), $key)
            ->select(self::FIELDS)
            ->andWhere('e.invoiceSeriesNumber = :invoice')
            ->setParameter('invoice', $invoiceSeriesNumber)
            ->orderBy('e.position', 'DESC');

        foreach ($this->views($queryBuilder, $key) as $view) {
            if ($view->invoiceSeriesNumber === $invoiceSeriesNumber) {
                return $view;
            }
        }

        return null;
    }

    public function findLatestByInvoices(string $issuerNif, array $invoiceSeriesNumbers): array
    {
        $key     = BillingRecordCriteria::normalizeIssuer($issuerNif);
        $numbers = BillingRecordQueryPaging::distinctInvoices($invoiceSeriesNumbers);
        if ($key === '' || $numbers === []) {
            return [];
        }

        $entityManager = $this->entityManager();
        $wanted        = array_flip($numbers);
        $latest        = [];
        for ($offset = 0, $total = count($numbers); $offset < $total; $offset += self::INVOICE_CHUNK) {
            $queryBuilder = $this->scoped($entityManager, $key)
                ->select(self::FIELDS)
                ->andWhere('e.invoiceSeriesNumber IN (:invoices)')
                ->setParameter('invoices', array_slice($numbers, $offset, self::INVOICE_CHUNK))
                ->orderBy('e.position', 'ASC');

            foreach ($this->views($queryBuilder, $key) as $view) {
                // Exact match only (a collation may return "fac-1" for "FAC-1"); the highest position wins.
                if (isset($wanted[$view->invoiceSeriesNumber])) {
                    $latest[$view->invoiceSeriesNumber] = $view;
                }
            }
        }

        $found = [];
        foreach ($numbers as $number) {
            if (isset($latest[$number])) {
                $found[] = $latest[$number];
            }
        }

        return $found;
    }

    /**
     * The scoped, filtered query (no select, no order), or null when nothing can match.
     */
    private function filtered(EntityManagerInterface $entityManager, BillingRecordCriteria $criteria): ?QueryBuilder
    {
        if ($criteria->isEmptyScope()
            || ($criteria->issuedFrom !== null && $criteria->issuedTo !== null && $criteria->issuedFrom > $criteria->issuedTo)
        ) {
            return null;
        }

        $queryBuilder = $this->scoped($entityManager, $criteria->issuerNif);

        if ($criteria->statuses !== []) {
            $queryBuilder->andWhere('e.submissionStatus IN (:statuses)')->setParameter('statuses', $criteria->statuses);
        }
        if ($criteria->recordTypes !== []) {
            $queryBuilder->andWhere('e.recordType IN (:recordTypes)')
                ->setParameter('recordTypes', array_map(static fn (RecordType $type): string => $type->value, $criteria->recordTypes));
        }
        if ($criteria->invoiceTypes !== []) {
            $queryBuilder->andWhere('e.invoiceType IN (:invoiceTypes)')->setParameter('invoiceTypes', $criteria->invoiceTypes);
        }
        if ($criteria->invoiceSeriesNumber !== null) {
            $positions = $this->exactInvoicePositions($entityManager, $criteria->issuerNif, $criteria->invoiceSeriesNumber);
            if ($positions === []) {
                return null;
            }
            $queryBuilder->andWhere('e.position IN (:positions)')->setParameter('positions', $positions);
        }
        if ($criteria->issuedFrom !== null) {
            $queryBuilder->andWhere(self::ISSUE_KEY . ' >= :issuedFrom')->setParameter('issuedFrom', $criteria->issuedFrom);
        }
        if ($criteria->issuedTo !== null) {
            $queryBuilder->andWhere(self::ISSUE_KEY . ' <= :issuedTo')->setParameter('issuedTo', $criteria->issuedTo);
        }

        return $queryBuilder;
    }

    /**
     * Positions of the issuer's records whose invoice number is exactly $invoiceSeriesNumber.
     *
     * @return list<int>
     */
    private function exactInvoicePositions(EntityManagerInterface $entityManager, string $key, string $invoiceSeriesNumber): array
    {
        /** @var list<array{position: int|string, invoiceSeriesNumber: string}> $rows */
        $rows = $this->scoped($entityManager, $key)
            ->select('e.position', 'e.invoiceSeriesNumber')
            ->andWhere('e.invoiceSeriesNumber = :invoice')
            ->setParameter('invoice', $invoiceSeriesNumber)
            ->getQuery()
            ->getArrayResult();

        $positions = [];
        foreach ($rows as $row) {
            if ($row['invoiceSeriesNumber'] === $invoiceSeriesNumber) {
                $positions[] = (int) $row['position'];
            }
        }

        return $positions;
    }

    private function scoped(EntityManagerInterface $entityManager, string $key): QueryBuilder
    {
        return $entityManager->createQueryBuilder()
            ->from(BillingRecordEntry::class, 'e')
            ->where('e.issuerNif = :issuerNif')
            ->setParameter('issuerNif', $key);
    }

    /**
     * Runs the query and maps the rows of the issuer (re-checked exactly) to views.
     *
     * @return list<BillingRecordView>
     */
    private function views(QueryBuilder $queryBuilder, string $key): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows  = $queryBuilder->getQuery()->getArrayResult();
        $views = [];
        foreach ($rows as $row) {
            if ($row['issuerNif'] === $key) {
                $views[] = $this->toView($row);
            }
        }

        return $views;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toView(array $row): BillingRecordView
    {
        return new BillingRecordView(
            (string) $row['issuerNif'],
            (int) $row['position'],
            RecordType::from((string) $row['recordType']),
            (string) $row['invoiceSeriesNumber'],
            (string) $row['issueDate'],
            (string) $row['invoiceType'],
            (string) $row['totalTaxAmount'],
            (string) $row['totalAmount'],
            (string) $row['generatedAt'],
            (string) $row['previousHash'],
            (string) $row['hash'],
            isset($row['issuerName']) ? (string) $row['issuerName'] : null,
            isset($row['environment']) ? AeatEnvironment::fromValue((string) $row['environment']) : null,
            (string) $row['submissionStatus'],
            isset($row['submissionReference']) ? (string) $row['submissionReference'] : null,
            (int) $row['submissionAttempts'],
            isset($row['submissionErrorCode']) ? (int) $row['submissionErrorCode'] : null,
            isset($row['submissionError']) ? (string) $row['submissionError'] : null,
            $row['createdAt'] instanceof DateTimeImmutable ? $row['createdAt'] : null,
            $row['submittedAt'] instanceof DateTimeImmutable ? $row['submittedAt'] : null,
        );
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = $this->managerRegistry->getManagerForClass(BillingRecordEntry::class);
        if (!$entityManager instanceof EntityManagerInterface) {
            throw new LogicException('No Doctrine ORM entity manager is configured for ' . BillingRecordEntry::class . '.');
        }

        return $entityManager;
    }
}
