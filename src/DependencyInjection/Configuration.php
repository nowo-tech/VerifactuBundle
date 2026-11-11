<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\DependencyInjection;

use Nowo\VerifactuBundle\Submission\AeatFaultClassifier;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

use function array_intersect;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function implode;
use function is_string;
use function sort;
use function sprintf;

/**
 * Veri*Factu bundle configuration tree.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_verifactu';

    /**
     * Builds the `nowo_verifactu` configuration tree.
     */
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
                ->scalarNode('aeat_spec_version')
                    ->info('Reserved: published as the nowo_verifactu.aeat_spec_version parameter only; it does not change XML, hash or SOAP output.')
                    ->defaultValue('1.0.0')
                ->end()
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
                ->arrayNode('providers')
                    ->addDefaultsIfNotSet()
                    ->info('Runtime providers, resolved on every record (e.g. values the host keeps in its database). Null = the static issuer / software / installation configuration.')
                    ->children()
                        ->scalarNode('issuer')
                            ->info('Service id implementing Nowo\\VerifactuBundle\\Provider\\IssuerProviderInterface')
                            ->defaultNull()
                        ->end()
                        ->booleanNode('issuer_enforced')
                            ->info('With providers.issuer set, refuse records whose issuer NIF differs from the provider (never signed nor submitted with that certificate). Opt out (false) only for multi-NIF hosts whose provider returns a single issuer; a per-tenant issuer provider is preferred.')
                            ->defaultTrue()
                        ->end()
                        ->scalarNode('software_identity')
                            ->info('Service id implementing Nowo\\VerifactuBundle\\Provider\\SoftwareIdentityProviderInterface')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('aeat_environment')
                            ->info('Service id implementing Nowo\\VerifactuBundle\\Provider\\AeatEnvironmentProviderInterface (null = aeat.environment)')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('certificate')
                            ->info('Service id implementing Nowo\\VerifactuBundle\\Certificate\\CertificateProviderInterface, per issuer NIF (null = aeat.certificate_path / certificate_password)')
                            ->defaultNull()
                        ->end()
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
                        ->scalarNode('table_prefix')
                            ->info('Deprecated: never read. Use doctrine.table_prefix to prefix the verifactu_hash_chain table.')
                            ->defaultValue('verifactu_')
                            ->setDeprecated('nowo-tech/verifactu-bundle', '1.3', 'The "%path%.%node%" option is deprecated and has no effect: use "nowo_verifactu.doctrine.table_prefix" (prepended to the verifactu_hash_chain table name) instead.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('validation')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('strict')
                            ->info('Also reject records the AEAT rejects for a field 1.5 could not send: recipients on F1, F3, R1 – R4 and TipoRectificativa on R1 – R5 (validations v1.2.2, §3.1.3 items 3 and 13). Recommended; false keeps 1.5 behaviour.')
                            ->defaultFalse()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('xml')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('default_regime_key')
                            ->info('ClaveRegimen written on tax lines that bring none (e.g. "01", general regime). Null omits it (1.5 output); the AEAT rejects IVA records without it from 2027-01-01 (validations v1.2.2, §15.6).')
                            ->values([null, '01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '14', '15', '17', '18', '19', '20', '21'])
                            ->defaultNull()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('record_store')
                    ->addDefaultsIfNotSet()
                    ->info('Optional log of every chained record (hash, XML, submission state) and the nowo:verifactu:verify-chain command')
                    ->children()
                        ->enumNode('storage')
                            ->values(['none', 'memory', 'doctrine'])
                            ->defaultValue('none')
                        ->end()
                        ->scalarNode('service')
                            ->info('Service id implementing Nowo\\VerifactuBundle\\Repository\\BillingRecordStoreInterface (overrides storage)')
                            ->defaultNull()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('submission')
                    ->addDefaultsIfNotSet()
                    ->info('Batch submission of the stored records (BillingRecordBatchSubmitter, nowo:verifactu:submit-pending, SubmitPendingBillingRecords message); needs a record store')
                    ->children()
                        ->integerNode('batch_size')
                            ->info('Records per AEAT request; the AEAT limit is 1000 (Servicios web v1.0.3 p. 10)')
                            ->min(1)->max(1000)
                            ->defaultValue(1000)
                        ->end()
                        ->integerNode('claim_timeout')
                            ->info('Seconds after which a batch claimed by a process that died is claimed again (longer than aeat.timeout)')
                            ->min(30)
                            ->defaultValue(300)
                        ->end()
                        ->integerNode('default_wait')
                            ->info('Seconds to wait between submissions when the AEAT returned no TiempoEsperaEnvio (initially 60, Orden HAC/1177/2024 art. 16.2)')
                            ->min(0)
                            ->defaultValue(60)
                        ->end()
                        ->integerNode('max_attempts')
                            ->info('Times the AEAT may refuse a record sent on its own (a SOAP Client fault) before it is held; it is marked failed when the next record, sent alone, gets an answer, otherwise the issuer is paused (pause_seconds)')
                            ->min(1)
                            ->defaultValue(5)
                        ->end()
                        ->arrayNode('issuer_fault_codes')
                            ->info('AEAT Codigo values of a SOAP Client fault that are about the issuer (header, certificate, authorisation): the issuer is paused at once and no record is failed')
                            ->integerPrototype()->min(1000)->max(9999)->end()
                            ->defaultValue(AeatFaultClassifier::DEFAULT_ISSUER_CODES)
                        ->end()
                        ->arrayNode('record_fault_codes')
                            ->info('AEAT Codigo values of a SOAP Client fault that are about the content of a record: the record at fault is failed without probing; any other code is probed')
                            ->integerPrototype()->min(1000)->max(9999)->end()
                            ->defaultValue(AeatFaultClassifier::DEFAULT_RECORD_CODES)
                        ->end()
                        ->integerNode('pause_seconds')
                            ->info('Circuit breaker: how long an issuer is paused when the AEAT refuses every request of it (kept in the flow control store)')
                            ->min(60)
                            ->defaultValue(3600)
                        ->end()
                        ->enumNode('flow_control_scope')
                            ->info('issuer: one AEAT wait per issuer NIF, for issuers that are separate SIF installations (own NumeroInstalacion, AEAT FAQ v1.3 §4); global: one wait shared by every issuer of one SIF installation')
                            ->values(['issuer', 'global'])
                            ->defaultValue('issuer')
                        ->end()
                        ->scalarNode('flow_control')
                            ->info('Service id implementing Nowo\\VerifactuBundle\\Submission\\FlowControlInterface (null = CacheFlowControl over flow_control_cache_pool)')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('flow_control_cache_pool')
                            ->info('PSR-6 cache pool of the default flow control; with several nodes it must be shared by all of them (Redis, database), never a per-node filesystem pool')
                            ->cannotBeEmpty()
                            ->defaultValue('cache.app')
                        ->end()
                        ->scalarNode('duplicate_confirmation')
                            ->info('Service id implementing Nowo\\VerifactuBundle\\Submission\\DuplicateConfirmationInterface (e.g. an AEAT query comparing the Huella); null = an AEAT duplicate for a record sent before is stored as unconfirmed, never accepted')
                            ->defaultNull()
                        ->end()
                        ->scalarNode('client')
                            ->info('Service id implementing Nowo\\VerifactuBundle\\Client\\BatchAeatSubmissionClientInterface (null = the AEAT submission client)')
                            ->defaultNull()
                        ->end()
                    ->end()
                    ->validate()
                        // LJD3-I1: a code in both lists would be classified as ISSUER (the issuer list wins), and an issuer
                        // code moved to the record list fails every record on a header error: both are refused.
                        ->ifTrue(static fn (array $submission): bool => self::faultCodeConflicts($submission) !== [])
                        ->then(static function (array $submission): never {
                            throw new InvalidConfigurationException(sprintf('nowo_verifactu.submission.record_fault_codes must not contain a code listed in issuer_fault_codes or a default issuer code (an error about the issuer would fail every record): %s.', implode(', ', self::faultCodeConflicts($submission))));
                        })
                    ->end()
                ->end()
                ->arrayNode('doctrine')
                    ->addDefaultsIfNotSet()
                    ->info('Doctrine storage options (REQ-ORM-002)')
                    ->children()
                        ->enumNode('mapping')
                            ->info('Mapping of the bundle entities: attribute (default) or xml (Resources/config/doctrine/*.orm.xml, for hosts that only load XML mappings)')
                            ->values(['attribute', 'xml'])
                            ->defaultValue('attribute')
                        ->end()
                        ->scalarNode('table_prefix')
                            ->info('Prefix prepended to every bundle table, index and unique constraint name (e.g. "app_" yields app_verifactu_hash_chain). Empty = historical verifactu_hash_chain name.')
                            ->defaultValue('')
                            ->example('app_')
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => !is_string($v) || preg_match('/^[A-Za-z0-9_]*$/D', $v) !== 1)
                                ->thenInvalid('nowo_verifactu.doctrine.table_prefix may only contain letters, digits and underscores, got %s.')
                            ->end()
                        ->end()
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

    /**
     * Record codes that are issuer codes too (configured or default).
     *
     * @param array<mixed> $submission the processed `submission` node
     *
     * @return list<int>
     */
    private static function faultCodeConflicts(array $submission): array
    {
        $record    = array_map('intval', (array) ($submission['record_fault_codes'] ?? []));
        $issuer    = array_merge(array_map('intval', (array) ($submission['issuer_fault_codes'] ?? [])), AeatFaultClassifier::DEFAULT_ISSUER_CODES);
        $conflicts = array_values(array_unique(array_intersect($record, $issuer)));
        sort($conflicts);

        return $conflicts;
    }
}
