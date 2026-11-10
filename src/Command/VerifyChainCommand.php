<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Command;

use Nowo\VerifactuBundle\Service\ChainVerifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
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
        $this->addOption('issuer', null, InputOption::VALUE_REQUIRED, 'Issuer NIF whose chain is verified');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $issuer = trim((string) $input->getOption('issuer'));
        if ($issuer === '') {
            $io->error('The --issuer option is required.');

            return Command::INVALID;
        }

        $result = $this->chainVerifier->verify($issuer);
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
