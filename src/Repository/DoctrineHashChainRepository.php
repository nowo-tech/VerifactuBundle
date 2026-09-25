<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\VerifactuBundle\Entity\BillingRecordHashChain;
use Nowo\VerifactuBundle\Model\HashChainState;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Throwable;

/**
 * Doctrine-backed hash chain repository for production environments.
 *
 * The last state is always read from the database (never from a stale identity map), managed
 * entities are detached after each write, and a manager closed by a failed flush is reset so
 * long-running workers (FrankenPHP worker mode) recover on the next call.
 *
 * @extends ServiceEntityRepository<BillingRecordHashChain>
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[AsAlias(id: self::SERVICE_NAME, public: true)]
final class DoctrineHashChainRepository extends ServiceEntityRepository implements HashChainRepositoryInterface
{
    public const SERVICE_NAME = 'nowo_verifactu.repository.doctrine_hash_chain';

    private readonly ManagerRegistry $managerRegistry;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BillingRecordHashChain::class);
        $this->managerRegistry = $registry;
    }

    public function getLastState(string $issuerNif): ?HashChainState
    {
        $entityManager = $this->resolveEntityManager();
        $entity        = $this->findFresh($entityManager, $issuerNif);
        if (!$entity instanceof BillingRecordHashChain) {
            return null;
        }

        $state = new HashChainState(
            $entity->getIssuerNif(),
            $entity->getInvoiceSeriesNumber(),
            $entity->getIssueDate(),
            $entity->getHash(),
        );
        $entityManager->detach($entity);

        return $state;
    }

    public function storeLastState(HashChainState $state): void
    {
        $entityManager = $this->resolveEntityManager();

        try {
            $entity = $this->findFresh($entityManager, $state->issuerNif);
            if (!$entity instanceof BillingRecordHashChain) {
                $entity = new BillingRecordHashChain(
                    $state->issuerNif,
                    $state->invoiceSeriesNumber,
                    $state->issueDate,
                    $state->hash,
                );
                $entityManager->persist($entity);
            } else {
                $entity->update($state->invoiceSeriesNumber, $state->issueDate, $state->hash);
            }

            $entityManager->flush();
            $entityManager->detach($entity);
        } catch (Throwable $exception) {
            $this->resetIfClosed($entityManager);

            throw $exception;
        }
    }

    public function reset(string $issuerNif): void
    {
        $entityManager = $this->resolveEntityManager();

        try {
            $entityManager->createQuery(
                'DELETE FROM ' . BillingRecordHashChain::class . ' h WHERE h.issuerNif = :issuerNif',
            )
                ->setParameter('issuerNif', $this->normalizeKey($issuerNif))
                ->execute();
        } catch (Throwable $exception) {
            $this->resetIfClosed($entityManager);

            throw $exception;
        }
    }

    public function getLastHash(string $issuerNif): ?string
    {
        return $this->getLastState($issuerNif)?->hash;
    }

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

    private function findFresh(EntityManagerInterface $entityManager, string $issuerNif): ?BillingRecordHashChain
    {
        $entity = $entityManager->createQueryBuilder()
            ->select('h')
            ->from(BillingRecordHashChain::class, 'h')
            ->where('h.issuerNif = :issuerNif')
            ->setParameter('issuerNif', $this->normalizeKey($issuerNif))
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $entity instanceof BillingRecordHashChain ? $entity : null;
    }

    private function resolveEntityManager(): EntityManagerInterface
    {
        $entityManager = $this->managerRegistry->getManagerForClass(BillingRecordHashChain::class);
        if ($entityManager instanceof EntityManagerInterface && !$entityManager->isOpen()) {
            $this->resetIfClosed($entityManager);
            $entityManager = $this->managerRegistry->getManagerForClass(BillingRecordHashChain::class);
        }

        if (!$entityManager instanceof EntityManagerInterface) {
            throw new LogicException('No Doctrine ORM entity manager is configured for ' . BillingRecordHashChain::class . '.');
        }

        return $entityManager;
    }

    private function resetIfClosed(EntityManagerInterface $entityManager): void
    {
        if ($entityManager->isOpen()) {
            return;
        }

        foreach ($this->managerRegistry->getManagers() as $name => $manager) {
            if ($manager === $entityManager) {
                $this->managerRegistry->resetManager($name);

                return;
            }
        }
    }

    private function normalizeKey(string $issuerNif): string
    {
        return strtoupper(trim($issuerNif));
    }
}
