<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Client;

use Nowo\VerifactuBundle\Model\BillingRecord;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * No-op AEAT client for development; replace in production with SOAP/REST implementation.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[AsAlias(id: self::SERVICE_NAME, public: true)]
final class NullAeatSubmissionClient implements AeatSubmissionClientInterface
{
    public const SERVICE_NAME = 'nowo_verifactu.client.null_aeat_submission';

    public function submit(BillingRecord $record): array
    {
        return [
            'success'   => true,
            'reference' => 'SANDBOX-' . ($record->hash ?? 'NO-HASH'),
        ];
    }
}
