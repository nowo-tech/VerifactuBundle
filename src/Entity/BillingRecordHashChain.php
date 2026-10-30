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

    /**
     * Creates the chain head row of an issuer.
     */
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

    /**
     * Returns the database identifier (null before the first flush).
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Returns the issuer NIF (normalised, upper case).
     */
    public function getIssuerNif(): string
    {
        return $this->issuerNif;
    }

    /**
     * Returns the series and number of the last chained invoice.
     */
    public function getInvoiceSeriesNumber(): string
    {
        return $this->invoiceSeriesNumber;
    }

    /**
     * Returns the issue date of the last chained invoice (dd-mm-yyyy).
     */
    public function getIssueDate(): string
    {
        return $this->issueDate;
    }

    /**
     * Returns the hash of the last chained record.
     */
    public function getHash(): string
    {
        return $this->hash;
    }

    /**
     * Returns when the chain head was last written.
     */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Moves the chain head to a new record.
     */
    public function update(string $invoiceSeriesNumber, string $issueDate, string $hash): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->invoiceSeriesNumber = trim($invoiceSeriesNumber);
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->issueDate = trim($issueDate);
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->hash = strtoupper(trim($hash));
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->updatedAt = new DateTimeImmutable();
    }
}
