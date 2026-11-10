<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\VerifactuBundle\Doctrine\DbalRowWriter;
use Nowo\VerifactuBundle\Entity\BillingRecordEntry;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Model\StoredBillingRecord;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Doctrine record log (record_store.storage: doctrine), table `verifactu_billing_record`.
 *
 * Like DoctrineHashChainRepository it never flushes: rows are inserted and updated with DBAL on the connection of the
 * EntityManager that maps BillingRecordEntry, and read with array-hydrated DQL. Called inside withChainLock(), the
 * append joins the same transaction as the chain head (and the host's transaction, when one is open). The primary key
 * (issuer_nif, position) refuses a second record at the same position, so a race can never fork the log.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[AsAlias(id: self::SERVICE_NAME, public: true)]
final class DoctrineBillingRecordStore implements BillingRecordStoreInterface
{
    public const SERVICE_NAME = 'nowo_verifactu.repository.doctrine_billing_record_store';

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

        $last = $entityManager->createQueryBuilder()
            ->select('MAX(e.position)')
            ->from(BillingRecordEntry::class, 'e')
            ->where('e.issuerNif = :issuerNif')
            ->setParameter('issuerNif', $key)
            ->getQuery()
            ->getSingleScalarResult();
        $position = (int) $last + 1;

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
     * Returns the records of the issuer in chain order.
     *
     * @return list<StoredBillingRecord>
     */
    public function findByIssuer(string $issuerNif): iterable
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->resolveEntityManager()->createQueryBuilder()
            ->select('e')
            ->from(BillingRecordEntry::class, 'e')
            ->where('e.issuerNif = :issuerNif')
            ->setParameter('issuerNif', $this->normalizeKey($issuerNif))
            ->orderBy('e.position', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $records = [];
        foreach ($rows as $row) {
            $records[] = new StoredBillingRecord(
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

        return $records;
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
