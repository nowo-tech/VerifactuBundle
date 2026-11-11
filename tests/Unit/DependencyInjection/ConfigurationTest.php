<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\DependencyInjection;

use Nowo\VerifactuBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\BaseNode;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class ConfigurationTest extends TestCase
{
    public function testDefaultConfiguration(): void
    {
        $processor = new Processor();
        $config    = $processor->processConfiguration(new Configuration(), [[]]);

        self::assertSame('verifactu', $config['mode']);
        self::assertSame('', $config['issuer']['nif']);
        self::assertSame('sandbox', $config['aeat']['environment']);
        self::assertSame(35, $config['qr']['size_mm']);
    }

    /**
     * LJD3-I1: fault codes are 4-digit AEAT codes; a record code that is an issuer code (configured or default) is
     * refused, since it would fail every record on an error about the issuer.
     */
    public function testFaultCodeListsAreValidated(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [['submission' => ['issuer_fault_codes' => [4104, 4999], 'record_fault_codes' => [4102, 4103]]]]);
        self::assertSame([[4104, 4999], [4102, 4103]], [$config['submission']['issuer_fault_codes'], $config['submission']['record_fault_codes']]);

        foreach ([
            'not 4 digits'          => [['issuer_fault_codes' => [104]], 'Should be greater than or equal to 1000'],
            'too long'              => [['record_fault_codes' => [41020]], 'Should be less than or equal to 9999'],
            'in both lists'         => [['issuer_fault_codes' => [4104, 4999], 'record_fault_codes' => [4999, 4102]], 'issuer_fault_codes or a default issuer code (an error about the issuer would fail every record): 4999.'],
            'default issuer, moved' => [['issuer_fault_codes' => [4105], 'record_fault_codes' => [4104, 4102]], ': 4104.'],
        ] as $name => [$submission, $message]) {
            try {
                (new Processor())->processConfiguration(new Configuration(), [['submission' => $submission]]);
                self::fail($name . ': accepted');
            } catch (InvalidConfigurationException $exception) {
                self::assertStringContainsString($message, $exception->getMessage(), $name);
            }
        }
    }

    public function testDoctrineTablePrefixDefaultsToEmpty(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);

        self::assertSame('', $config['doctrine']['table_prefix']);
    }

    public function testDoctrineTablePrefixAcceptsIdentifierCharacters(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [['doctrine' => ['table_prefix' => 'App_2_']]]);

        self::assertSame('App_2_', $config['doctrine']['table_prefix']);
    }

    public function testDoctrineTablePrefixRejectsUnsafeCharacters(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('may only contain letters, digits and underscores');

        (new Processor())->processConfiguration(new Configuration(), [['doctrine' => ['table_prefix' => 'app-; DROP']]]);
    }

    public function testDoctrineTablePrefixRejectsTrailingNewline(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [['doctrine' => ['table_prefix' => "app_\n"]]]);
    }

    public function testLegacyHashChainTablePrefixIsDeprecated(): void
    {
        $hashChain = (new Configuration())->getConfigTreeBuilder()->buildTree();
        self::assertInstanceOf(ArrayNode::class, $hashChain);
        $hashChainNode = $hashChain->getChildren()['hash_chain'];
        self::assertInstanceOf(ArrayNode::class, $hashChainNode);
        $legacy = $hashChainNode->getChildren()['table_prefix'];
        self::assertInstanceOf(BaseNode::class, $legacy);

        self::assertTrue($legacy->isDeprecated());
        self::assertStringContainsString('use "nowo_verifactu.doctrine.table_prefix"', $legacy->getDeprecation('table_prefix', 'nowo_verifactu.hash_chain')['message']);
    }
}
