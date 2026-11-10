<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Repository;

use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\VerifactuBundle\Doctrine\DbalRowWriter;
use Nowo\VerifactuBundle\Entity\BillingRecordHashChain;
use Nowo\VerifactuBundle\Model\HashChainState;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Throwable;

/**
 * Doctrine-backed hash chain repository for production environments.
 *
 * It never flushes, clears or resets an EntityManager (architecture rule 6): the chain head is written with DBAL on the
 * connection of the EntityManager that maps BillingRecordHashChain (usually the host's default one), and read with
 * array-hydrated DQL, so nothing enters the identity map and the host's pending entities are never written by the
 * bundle. It works even when the host's manager has been closed by a failed flush of the host.
 *
 * Transactions: withChainLock() opens a transaction on that connection. When the host already has one open, it nests
 * (DBAL savepoint or nesting level), so the chain head (and the record log, when enabled) commit or roll back with the
 * host's own data; without a host transaction it commits on its own, as before.
 *
 * Locking: the issuer row is read with SELECT … FOR UPDATE. For the very first record of an issuer there is no row to
 * lock; the unique constraint on issuer_nif makes a concurrent second insert fail instead of forking the chain.
 *
 * @extends ServiceEntityRepository<BillingRecordHashChain>
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[AsAlias(id: self::SERVICE_NAME, public: true)]
final class DoctrineHashChainRepository extends ServiceEntityRepository implements LockableHashChainRepositoryInterface
{
    public const SERVICE_NAME = 'nowo_verifactu.repository.doctrine_hash_chain';

    private readonly ManagerRegistry $managerRegistry;

    /**
     * Receives the Doctrine registry managing the BillingRecordHashChain entity.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BillingRecordHashChain::class);
        $this->managerRegistry = $registry;
    }

    /**
     * Returns the current chain head (last record state) of the issuer, or null when the chain is empty.
     */
    public function getLastState(string $issuerNif): ?HashChainState
    {
        $row = $this->findRow($this->resolveEntityManager(), $issuerNif, false);

        return $row === null ? null : new HashChainState(
            (string) $row['issuerNif'],
            (string) $row['invoiceSeriesNumber'],
            (string) $row['issueDate'],
            (string) $row['hash'],
        );
    }

    /**
     * Runs the operation in a transaction holding a pessimistic write lock on the issuer chain row.
     *
     * The transaction nests into the host's transaction when one is open on the same connection.
     */
    public function withChainLock(string $issuerNif, callable $operation): mixed
    {
        $connection = $this->resolveEntityManager()->getConnection();
        $connection->beginTransaction();

        try {
            $this->findRow($this->resolveEntityManager(), $issuerNif, true);

            $result = $operation();
            $connection->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Stores the new chain head of the issuer (DBAL insert or update; never a flush).
     */
    public function storeLastState(HashChainState $state): void
    {
        $entityManager = $this->resolveEntityManager();
        $key           = $this->normalizeKey($state->issuerNif);
        $fields        = [
            'invoiceSeriesNumber' => trim($state->invoiceSeriesNumber),
            'issueDate'           => trim($state->issueDate),
            'hash'                => strtoupper(trim($state->hash)),
            'updatedAt'           => new DateTimeImmutable(),
        ];

        if ($this->findRow($entityManager, $key, false) === null) {
            DbalRowWriter::insert($entityManager, BillingRecordHashChain::class, ['issuerNif' => $key] + $fields);

            return;
        }

        DbalRowWriter::update($entityManager, BillingRecordHashChain::class, $fields, ['issuerNif' => $key]);
    }

    /**
     * Removes the chain head of the issuer (the next record starts a new chain).
     */
    public function reset(string $issuerNif): void
    {
        $this->resolveEntityManager()->createQuery(
            'DELETE FROM ' . BillingRecordHashChain::class . ' h WHERE h.issuerNif = :issuerNif',
        )
            ->setParameter('issuerNif', $this->normalizeKey($issuerNif))
            ->execute();
    }

    /**
     * Returns the hash of the current chain head of the issuer (deprecated: use getLastState()).
     */
    public function getLastHash(string $issuerNif): ?string
    {
        return $this->getLastState($issuerNif)?->hash;
    }

    /**
     * Stores only a hash as chain head of the issuer (deprecated: use storeLastState()).
     */
    public function storeLastHash(string $issuerNif, string $hash): void
    {
        $existing = $this->getLastState($issuerNif);
        $this->storeLastState(new HashChainState(
            $issuerNif,
            $existing instanceof HashChainState ? $existing->invoiceSeriesNumber : '',
            $existing instanceof HashChainState ? $existing->issueDate : '',
            $hash,
        ));
    }

    /**
     * Reads the issuer row as an array (never a managed entity), optionally with SELECT … FOR UPDATE.
     *
     * @return array<string, mixed>|null
     */
    private function findRow(EntityManagerInterface $entityManager, string $issuerNif, bool $lock): ?array
    {
        $query = $entityManager->createQueryBuilder()
            ->select('h.issuerNif', 'h.invoiceSeriesNumber', 'h.issueDate', 'h.hash')
            ->from(BillingRecordHashChain::class, 'h')
            ->where('h.issuerNif = :issuerNif')
            ->setParameter('issuerNif', $this->normalizeKey($issuerNif))
            ->getQuery();
        if ($lock) {
            $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $query->getArrayResult();

        return $rows[0] ?? null;
    }

    /**
     * Returns the manager of the chain entity. It is only used for its metadata, DQL and connection, so a manager the
     * host has closed still works and is never reset by the bundle.
     */
    private function resolveEntityManager(): EntityManagerInterface
    {
        $entityManager = $this->managerRegistry->getManagerForClass(BillingRecordHashChain::class);
        if (!$entityManager instanceof EntityManagerInterface) {
            throw new LogicException('No Doctrine ORM entity manager is configured for ' . BillingRecordHashChain::class . '.');
        }

        return $entityManager;
    }

    private function normalizeKey(string $issuerNif): string
    {
        return strtoupper(trim($issuerNif));
    }
}
