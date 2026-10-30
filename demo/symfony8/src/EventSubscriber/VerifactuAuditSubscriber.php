<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Nowo\VerifactuBundle\Event\AfterBillingRecordGeneratedEvent;
use Nowo\VerifactuBundle\Event\VerifactuEvents;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Example Nowo integration hook: audit trail after each billing record is generated.
 */
final class VerifactuAuditSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            VerifactuEvents::AFTER_BILLING_RECORD_GENERATED => 'onRecordGenerated',
        ];
    }

    public function onRecordGenerated(AfterBillingRecordGeneratedEvent $event): void
    {
        $record = $event->getRecord();
        // The issuer NIF of a self-employed person is personal data: log only its last characters.
        $this->logger->info('Veri*Factu billing record generated', [
            'nif'         => '***' . substr($record->issuerNif, -3),
            'numserie'    => $record->invoiceSeriesNumber,
            'hash'        => $record->hash,
            'record_type' => $record->recordType->value,
        ]);
    }
}
