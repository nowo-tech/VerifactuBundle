<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\VerifactuBundle\Repository\DoctrineHashChainRepository;

/**
 * Persists the latest billing record hash chain state per issuer NIF.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[ORM\Entity(repositoryClass: DoctrineHashChainRepository::class)]
#[ORM\Table(name: 'verifactu_hash_chain')]
#[ORM\UniqueConstraint(name: 'uniq_verifactu_hash_chain_issuer', columns: ['issuer_nif'])]
class BillingRecordHashChain
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    /** @phpstan-ignore property.unusedType (assigned by Doctrine on persist) */
    private ?int $id = null;

    #[ORM\Column(name: 'issuer_nif', type: Types::STRING, length: 20)]
    private string $issuerNif;

    #[ORM\Column(name: 'invoice_series_number', type: Types::STRING, length: 60)]
    private string $invoiceSeriesNumber;

    #[ORM\Column(name: 'issue_date', type: Types::STRING, length: 10)]
    private string $issueDate;

    #[ORM\Column(name: 'hash', type: Types::STRING, length: 64)]
    private string $hash;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        string $issuerNif,
        string $invoiceSeriesNumber,
        string $issueDate,
        string $hash,
    ) {
        $this->issuerNif           = strtoupper(trim($issuerNif));
        $this->invoiceSeriesNumber = trim($invoiceSeriesNumber);
        $this->issueDate           = trim($issueDate);
        $this->hash                = strtoupper(trim($hash));
        $this->updatedAt           = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIssuerNif(): string
    {
        return $this->issuerNif;
    }

    public function getInvoiceSeriesNumber(): string
    {
        return $this->invoiceSeriesNumber;
    }

    public function getIssueDate(): string
    {
        return $this->issueDate;
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function update(string $invoiceSeriesNumber, string $issueDate, string $hash): void
    {
        $this->invoiceSeriesNumber = trim($invoiceSeriesNumber);
        $this->issueDate           = trim($issueDate);
        $this->hash                = strtoupper(trim($hash));
        $this->updatedAt           = new DateTimeImmutable();
    }
}
