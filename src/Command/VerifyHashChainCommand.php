<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Command;

use Nowo\VerifactuBundle\Generator\HashChainGenerator;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Verifies that a stored hash matches billing record data.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[AsCommand(
    name: 'nowo:verifactu:verify-hash',
    description: 'Verify a Veri*Factu billing record hash against record fields',
)]
final class VerifyHashChainCommand extends Command
{
    public function __construct(
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
            ->addOption('cuota', null, InputOption::VALUE_REQUIRED, 'Total tax amount')
            ->addOption('importe', null, InputOption::VALUE_REQUIRED, 'Total amount')
            ->addOption('generated-at', null, InputOption::VALUE_REQUIRED, 'Generation timestamp')
            ->addOption('previous-hash', null, InputOption::VALUE_OPTIONAL, 'Previous record hash', '')
            ->addOption('hash', null, InputOption::VALUE_REQUIRED, 'Expected hash to verify');
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
            (string) $input->getOption('generated-at'),
            previousHash: (string) $input->getOption('previous-hash'),
        );

        $expected = (string) $input->getOption('hash');
        $valid    = $this->hashChainGenerator->verifyHash($record, $expected);

        if ($valid) {
            $io->success('Hash verification passed.');

            return Command::SUCCESS;
        }

        $io->error([
            'Hash verification failed.',
            'Computed: ' . $this->hashChainGenerator->computeHash($record),
            'Expected: ' . strtoupper($expected),
        ]);

        return Command::FAILURE;
    }
}
