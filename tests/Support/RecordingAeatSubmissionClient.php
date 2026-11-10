<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Support;

use ArrayObject;
use Nowo\VerifactuBundle\Client\AeatSubmissionClientInterface;
use Nowo\VerifactuBundle\Model\BillingRecord;

/**
 * Records what would be sent to the AEAT, as a snapshot taken at submit time.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class RecordingAeatSubmissionClient implements AeatSubmissionClientInterface
{
    /** @var list<array{hash: ?string, xml: ?string, signedXml: ?string}> */
    public array $sent = [];

    /**
     * @param ArrayObject<int, string> $log shared call log (e.g. with event listeners)
     * @param array{success: bool, reference?: string, errors?: list<string>} $result result returned by submit()
     */
    public function __construct(
        private readonly ArrayObject $log,
        private readonly array $result = ['success' => true, 'reference' => 'CSV-1'],
    ) {
    }

    public function submit(BillingRecord $record): array
    {
        $this->log->append('submit');
        $this->sent[] = ['hash' => $record->hash, 'xml' => $record->xml, 'signedXml' => $record->signedXml];

        return $this->result;
    }
}
