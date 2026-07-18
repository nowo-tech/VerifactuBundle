<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Veri*Factu bundle configuration tree.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_verifactu';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);
        $root        = $treeBuilder->getRootNode();

        $root
            ->children()
                ->enumNode('mode')
                    ->values(['verifactu', 'no_verifactu'])
                    ->defaultValue('verifactu')
                ->end()
                ->scalarNode('aeat_spec_version')->defaultValue('1.0.0')->end()
                ->arrayNode('issuer')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('nif')->defaultValue('')->end()
                        ->scalarNode('name')->defaultValue('')->end()
                    ->end()
                ->end()
                ->arrayNode('software')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('id')->defaultValue('01')->end()
                        ->scalarNode('name')->defaultValue('NowoVerifactu')->end()
                        ->scalarNode('version')->defaultValue('1.0.0')->end()
                        ->scalarNode('manufacturer_nif')->defaultValue('')->end()
                        ->scalarNode('manufacturer_name')->defaultValue('Nowo.tech')->end()
                        ->booleanNode('solo_verifactu')->defaultTrue()->end()
                    ->end()
                ->end()
                ->arrayNode('installation')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('number')->defaultValue('001')->end()
                    ->end()
                ->end()
                ->arrayNode('hash_chain')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('storage')
                            ->values(['memory', 'doctrine'])
                            ->defaultValue('memory')
                        ->end()
                        ->scalarNode('repository')->defaultNull()->end()
                        ->scalarNode('table_prefix')->defaultValue('verifactu_')->end()
                    ->end()
                ->end()
                ->arrayNode('aeat')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('environment')
                            ->values(['sandbox', 'production'])
                            ->defaultValue('sandbox')
                        ->end()
                        ->scalarNode('submission_client')->defaultNull()->end()
                        ->scalarNode('certificate_path')->defaultNull()->end()
                        ->scalarNode('certificate_password')->defaultNull()->end()
                        ->enumNode('certificate_type')
                            ->values(['personal', 'seal'])
                            ->defaultValue('personal')
                        ->end()
                        ->integerNode('timeout')->defaultValue(30)->min(5)->max(120)->end()
                        ->booleanNode('validate_xsd')->defaultTrue()->end()
                        ->booleanNode('sign_xades')->defaultFalse()->end()
                    ->end()
                ->end()
                ->arrayNode('qr')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('size_mm')->defaultValue(35)->min(30)->max(40)->end()
                        ->enumNode('legend')
                            ->values(['verifactu', 'aeat_verifiable'])
                            ->defaultValue('verifactu')
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
