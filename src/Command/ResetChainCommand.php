<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Command;

use Nowo\VerifactuBundle\Model\HashChainState;
use Nowo\VerifactuBundle\Repository\HashChainRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

/**
 * Removes the chain head of an issuer, so the next record starts a new chain (PrimerRegistro). Needed before the first
 * record of another AEAT environment (e.g. after testing in the sandbox): the processor refuses to chain across
 * environments. Stored records are kept.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[AsCommand(
    name: 'nowo:verifactu:reset-chain',
    description: 'Reset the Veri*Factu chain head of an issuer (the next record starts a new chain, e.g. when moving from the sandbox to production)',
)]
final class ResetChainCommand extends Command
{
    /**
     * Receives the chain head repository.
     */
    public function __construct(
        private readonly HashChainRepositoryInterface $hashChainRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('issuer', null, InputOption::VALUE_REQUIRED, 'Issuer NIF whose chain head is removed')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Do not ask for confirmation (non-interactive runs)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $issuer = strtoupper(trim((string) $input->getOption('issuer')));
        if ($issuer === '') {
            $io->error('The --issuer option is required.');

            return Command::INVALID;
        }

        $head = $this->hashChainRepository->getLastState($issuer);
        if (!$head instanceof HashChainState) {
            $io->success(sprintf('%s has no chain head: the next record already starts a new chain.', $issuer));

            return Command::SUCCESS;
        }

        $environment = $head->environment->value ?? 'configured';
        if (!(bool) $input->getOption('force')
            && (!$input->isInteractive() || !$io->confirm(sprintf('Reset the chain of %s (last record %s, %s environment)? The next record starts a new chain.', $issuer, $head->invoiceSeriesNumber, $environment), false))
        ) {
            $io->error('Not reset: confirm interactively or pass --force.');

            return Command::FAILURE;
        }

        $this->hashChainRepository->reset($issuer);
        $io->success(sprintf('The chain of %s was reset; the next record starts a new chain.', $issuer));

        return Command::SUCCESS;
    }
}
