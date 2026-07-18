<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\Invoice;
use DateTimeImmutable;
use Nowo\VerifactuBundle\Integration\InvoiceDraft;
use Nowo\VerifactuBundle\Integration\InvoiceToBillingRecordMapper;
use Nowo\VerifactuBundle\Model\HashChainState;
use Nowo\VerifactuBundle\Repository\HashChainRepositoryInterface;
use Nowo\VerifactuBundle\Service\BillingRecordProcessor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Bridges demo invoices into the Veri*Factu billing record pipeline (Nowo adoption pattern).
 */
final class InvoiceBillingService
{
    public function __construct(
        private readonly InvoiceToBillingRecordMapper $mapper,
        private readonly BillingRecordProcessor $processor,
        private readonly HashChainRepositoryInterface $hashChainRepository,
        #[Autowire('%nowo_verifactu.issuer%')]
        private readonly array $issuerConfig,
        #[Autowire('%nowo_verifactu.aeat.certificate_path%')]
        private readonly ?string $certificatePath,
    ) {
    }

    /**
     * @return array{record: \Nowo\VerifactuBundle\Model\BillingRecord, errors: list<string>, submission?: array<string, mixed>}
     */
    public function processInvoice(Invoice $invoice, bool $submitToAeat): array
    {
        $draft = new InvoiceDraft(
            (string) $this->issuerConfig['nif'],
            $invoice->number,
            $invoice->issueDate,
            $invoice->totalTaxAmount,
            $invoice->totalAmount,
            (new DateTimeImmutable())->format('c'),
            $invoice->recordType,
            $invoice->invoiceType,
            isset($this->issuerConfig['name']) ? (string) $this->issuerConfig['name'] : null,
            $invoice->description ?? 'Factura emitida',
        );

        return $this->processor->process($this->mapper->map($draft), $submitToAeat);
    }

    public function getHashChainState(): ?HashChainState
    {
        return $this->hashChainRepository->getLastState((string) $this->issuerConfig['nif']);
    }

    public function isAeatConfigured(): bool
    {
        return $this->certificatePath !== null && $this->certificatePath !== '';
    }
}
