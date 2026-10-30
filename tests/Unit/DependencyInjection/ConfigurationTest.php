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
