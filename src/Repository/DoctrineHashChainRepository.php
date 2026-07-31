<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\VerifactuBundle\Entity\BillingRecordHashChain;
use Nowo\VerifactuBundle\Model\HashChainState;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Doctrine-backed hash chain repository for production environments.
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

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BillingRecordHashChain::class);
    }

    public function getLastState(string $issuerNif): ?HashChainState
    {
        $entity = $this->findOneBy(['issuerNif' => strtoupper(trim($issuerNif))]);
        if ($entity === null) {
            return null;
        }

        return new HashChainState(
            $entity->getIssuerNif(),
            $entity->getInvoiceSeriesNumber(),
            $entity->getIssueDate(),
            $entity->getHash(),
        );
    }

    public function storeLastState(HashChainState $state): void
    {
        $entity = $this->findOneBy(['issuerNif' => strtoupper(trim($state->issuerNif))]);
        if ($entity === null) {
            $entity = new BillingRecordHashChain(
                $state->issuerNif,
                $state->invoiceSeriesNumber,
                $state->issueDate,
                $state->hash,
            );
            $this->getEntityManager()->persist($entity);
        } else {
            $entity->update($state->invoiceSeriesNumber, $state->issueDate, $state->hash);
        }

        $this->getEntityManager()->flush();
    }

    public function reset(string $issuerNif): void
    {
        $entity = $this->findOneBy(['issuerNif' => strtoupper(trim($issuerNif))]);
        if ($entity === null) {
            return;
        }

        $this->getEntityManager()->remove($entity);
        $this->getEntityManager()->flush();
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
}
