<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Command;

use Nowo\VerifactuBundle\Generator\HashChainGenerator;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Validator\AeatBusinessRulesValidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Validates a billing record DTO from CLI arguments.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[AsCommand(
    name: 'nowo:verifactu:validate-record',
    description: 'Validate Veri*Factu billing record fields and compute hash preview',
)]
final class ValidateBillingRecordCommand extends Command
{
    public function __construct(
        private readonly AeatBusinessRulesValidator $validator,
        private readonly HashChainGenerator $hashChainGenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('nif', null, InputOption::VALUE_REQUIRED, 'Issuer NIF')
            ->addOption('numserie', null, InputOption::VALUE_REQUIRED, 'Invoice series+number')
            ->addOption('fecha', null, InputOption::VALUE_REQUIRED, 'Issue date (dd-mm-yyyy)')
            ->addOption('tipo', null, InputOption::VALUE_REQUIRED, 'Invoice type code', 'F1')
            ->addOption('cuota', null, InputOption::VALUE_REQUIRED, 'Total tax amount', '0.00')
            ->addOption('importe', null, InputOption::VALUE_REQUIRED, 'Total amount', '0.00')
            ->addOption('generated-at', null, InputOption::VALUE_REQUIRED, 'Generation timestamp (ISO 8601)')
            ->addOption('previous-hash', null, InputOption::VALUE_OPTIONAL, 'Previous record hash', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $record = new BillingRecord(
            RecordType::Alta,
            (string) $input->getOption('nif'),
            (string) $input->getOption('numserie'),
            (string) $input->getOption('fecha'),
            (string) $input->getOption('tipo'),
            (string) $input->getOption('cuota'),
            (string) $input->getOption('importe'),
            (string) ($input->getOption('generated-at') ?: date('c')),
            previousHash: (string) $input->getOption('previous-hash'),
        );

        $errors = $this->validator->validate($record);
        if ($errors !== []) {
            $io->error($errors);

            return Command::FAILURE;
        }

        $io->success('Billing record is valid.');
        $io->writeln('Input string: ' . $this->hashChainGenerator->buildInputString($record));
        $io->writeln('Hash: ' . $this->hashChainGenerator->computeHash($record));

        return Command::SUCCESS;
    }
}
