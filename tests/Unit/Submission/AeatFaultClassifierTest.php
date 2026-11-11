<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Submission;

use Nowo\VerifactuBundle\Model\AeatBatchResponse;
use Nowo\VerifactuBundle\Submission\AeatFaultClassifier;
use Nowo\VerifactuBundle\Tests\Support\ScriptedBatchClient;
use PHPUnit\Framework\TestCase;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class AeatFaultClassifierTest extends TestCase
{
    public function testDefaultCodes(): void
    {
        $classifier = new AeatFaultClassifier();

        foreach ([4104, 4105, 4107, 4108, 4112, 4116, 4120, 4134, 4139, 4141] as $code) {
            self::assertSame(AeatFaultClassifier::ISSUER, $classifier->classify(ScriptedBatchClient::fault($code)), (string) $code);
        }
        foreach ([4102, 4106, 4119, 4136, 4137] as $code) {
            self::assertSame(AeatFaultClassifier::RECORD, $classifier->classify(ScriptedBatchClient::fault($code)), (string) $code);
        }
        foreach ([4103, 4109, 4113, 4138, 4199, 9999] as $code) {
            self::assertSame(AeatFaultClassifier::UNKNOWN, $classifier->classify(ScriptedBatchClient::fault($code)), (string) $code);
        }
    }

    public function testOnlyAClientFaultWithACodeIsClassified(): void
    {
        $classifier = new AeatFaultClassifier();

        self::assertSame(AeatFaultClassifier::UNKNOWN, $classifier->classify(ScriptedBatchClient::fault(4104, 'env:Server')), 'Server faults are never record refusals');
        self::assertSame(AeatFaultClassifier::UNKNOWN, $classifier->classify(AeatBatchResponse::failed('AEAT SOAP fault: sin código', 500, '', '', false, AeatBatchResponse::FAULT_CLIENT)));
        self::assertSame(AeatFaultClassifier::UNKNOWN, $classifier->classify(AeatBatchResponse::failed('timeout', 0, '', '', true)));
    }

    public function testTheListsAreConfigurable(): void
    {
        $classifier = new AeatFaultClassifier([4199], [4104]);

        self::assertSame(AeatFaultClassifier::ISSUER, $classifier->classify(ScriptedBatchClient::fault(4199)));
        self::assertSame(AeatFaultClassifier::RECORD, $classifier->classify(ScriptedBatchClient::fault(4104)));
        self::assertSame(AeatFaultClassifier::UNKNOWN, $classifier->classify(ScriptedBatchClient::fault(4102)));
    }
}
