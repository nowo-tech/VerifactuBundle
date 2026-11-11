<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Command;

use Nowo\VerifactuBundle\Model\AeatEnvironment;
use Nowo\VerifactuBundle\Service\ChainVerifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function is_string;
use function sprintf;

/**
 * Verifies the stored hash chain of an issuer (requires nowo_verifactu.record_store).
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[AsCommand(
    name: 'nowo:verifactu:verify-chain',
    description: 'Verify every stored Veri*Factu billing record of an issuer: hashes, chain links, XML amounts and the chain head',
)]
final class VerifyChainCommand extends Command
{
    /**
     * Receives the chain verifier.
     */
    public function __construct(
        private readonly ChainVerifier $chainVerifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('issuer', null, InputOption::VALUE_REQUIRED, 'Issuer NIF whose chain is verified')
            ->addOption('environment', null, InputOption::VALUE_REQUIRED, 'AEAT environment to verify (sandbox or production; default: the environment of the current chain head)')
            ->addOption('anchor', null, InputOption::VALUE_REQUIRED, 'Hash of the chain head before the record store was enabled (the first stored record must continue from it)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $issuer = trim((string) $input->getOption('issuer'));
        if ($issuer === '') {
            $io->error('The --issuer option is required.');

            return Command::INVALID;
        }

        $anchor      = $input->getOption('anchor');
        $environment = $input->getOption('environment');
        if (is_string($environment) && $environment !== '' && AeatEnvironment::tryFrom($environment) === null) {
            $io->error('The --environment option must be "sandbox" or "production".');

            return Command::INVALID;
        }
        $result = $this->chainVerifier->verify($issuer, is_string($anchor) && trim($anchor) !== '' ? $anchor : null, is_string($environment) && $environment !== '' ? $environment : null);
        if ($result->continuesFrom !== null) {
            $io->note(sprintf('The stored log starts after an older record (previous hash %s); records before it are not in the store.', $result->continuesFrom));
        }
        if ($result->isValid()) {
            $io->success(sprintf('The chain of %s is valid (%d records).', $result->issuerNif, $result->checkedRecords));

            return Command::SUCCESS;
        }

        $io->error(sprintf('The chain of %s has %d problem(s) in %d records.', $result->issuerNif, count($result->problems), $result->checkedRecords));
        $io->table(
            ['Position', 'Invoice', 'Problem'],
            array_map(static fn (array $problem): array => [$problem['position'], $problem['invoiceSeriesNumber'], $problem['problem']], $result->problems),
        );

        return Command::FAILURE;
    }
}
