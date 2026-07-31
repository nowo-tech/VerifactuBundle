<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Command;

use Nowo\VerifactuBundle\Integration\InvoiceDraft;
use Nowo\VerifactuBundle\Integration\InvoiceToBillingRecordMapper;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Service\BillingRecordProcessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function is_array;
use function sprintf;

/**
 * Smoke-test command for AEAT sandbox submission with a real certificate.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[AsCommand(
    name: 'nowo:verifactu:submit-sandbox',
    description: 'Generate a billing record and optionally submit it to the AEAT sandbox',
)]
final class SubmitToAeatSandboxCommand extends Command
{
    /**
     * @param array{nif: string, name?: string} $issuerConfig
     */
    public function __construct(
        private readonly InvoiceToBillingRecordMapper $mapper,
        private readonly BillingRecordProcessor $processor,
        #[Autowire('%nowo_verifactu.aeat.certificate_path%')]
        private readonly ?string $certificatePath,
        #[Autowire('%nowo_verifactu.aeat.environment%')]
        private readonly string $environment,
        #[Autowire('%nowo_verifactu.issuer%')]
        private readonly array $issuerConfig,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('nif', null, InputOption::VALUE_REQUIRED, 'Issuer NIF', '89890001K')
            ->addOption('numserie', null, InputOption::VALUE_REQUIRED, 'Invoice series + number', 'SANDBOX-001')
            ->addOption('fecha', null, InputOption::VALUE_REQUIRED, 'Issue date (dd-mm-yyyy)')
            ->addOption('tipo', null, InputOption::VALUE_REQUIRED, 'Invoice type', 'F1')
            ->addOption('cuota', null, InputOption::VALUE_REQUIRED, 'Total tax amount', '21.00')
            ->addOption('importe', null, InputOption::VALUE_REQUIRED, 'Total amount', '121.00')
            ->addOption('generated-at', null, InputOption::VALUE_REQUIRED, 'Generation timestamp (ISO 8601)')
            ->addOption('record-type', null, InputOption::VALUE_REQUIRED, 'Alta or Anulacion', 'Alta')
            ->addOption('submit', null, InputOption::VALUE_NONE, 'Submit to AEAT (requires certificate)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only validate and generate XML/hash without AEAT call');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $recordType = RecordType::tryFrom((string) $input->getOption('record-type'));
        if ($recordType === null) {
            $io->error('Invalid record type. Use Alta or Anulacion.');

            return Command::INVALID;
        }

        $draft = new InvoiceDraft(
            (string) $input->getOption('nif'),
            (string) $input->getOption('numserie'),
            (string) ($input->getOption('fecha') ?: date('d-m-Y')),
            (string) $input->getOption('cuota'),
            (string) $input->getOption('importe'),
            (string) ($input->getOption('generated-at') ?: date('c')),
            $recordType,
            (string) $input->getOption('tipo'),
            isset($this->issuerConfig['name']) ? $this->issuerConfig['name'] : null,
            operationDescription: 'Sandbox smoke test',
        );

        $submit = (bool) $input->getOption('submit') && !(bool) $input->getOption('dry-run');

        if ($submit && ($this->certificatePath === null || $this->certificatePath === '')) {
            $io->error('AEAT certificate is not configured. Set nowo_verifactu.aeat.certificate_path or use --dry-run.');

            return Command::FAILURE;
        }

        if ($submit && $this->environment !== 'sandbox') {
            $io->warning(sprintf('Environment is "%s". Sandbox command is intended for sandbox testing.', $this->environment));
        }

        $result = $this->processor->process($this->mapper->map($draft), submitToAeat: $submit);

        if ($result['errors'] !== []) {
            $io->error('Validation failed:');
            $io->listing($result['errors']);

            return Command::FAILURE;
        }

        $record = $result['record'];
        $io->success('Billing record generated successfully.');
        $io->writeln(sprintf('Hash: %s', $record->hash));
        $io->writeln(sprintf('Previous hash: %s', $record->previousHash ?? '(first record)'));

        if ($record->signedXml !== null) {
            $io->writeln('XAdES signature: present');
        }

        if (isset($result['submission'])) {
            $submission = $result['submission'];
            if (($submission['success'] ?? false) === true) {
                $io->success(sprintf(
                    'AEAT submission OK — reference: %s',
                    $submission['reference'] ?? '(none)',
                ));
            } else {
                $io->error('AEAT submission failed.');
                if (isset($submission['errors']) && is_array($submission['errors'])) {
                    $io->listing($submission['errors']);
                }

                return Command::FAILURE;
            }
        // @codeCoverageIgnoreStart
        } elseif ($submit) {
            $io->note('Submission was requested but no response was returned.');
        // @codeCoverageIgnoreEnd
        } else {
            $io->note('Dry run — use --submit to call AEAT sandbox with your certificate.');
        }

        return Command::SUCCESS;
    }
}
