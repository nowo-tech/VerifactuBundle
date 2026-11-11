<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Command;

use Nowo\VerifactuBundle\Model\BatchSubmissionResult;
use Nowo\VerifactuBundle\Model\RecordSubmissionStatus;
use Nowo\VerifactuBundle\Service\BillingRecordBatchSubmitter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function sprintf;
use function strtoupper;
use function trim;

use const DATE_ATOM;

/**
 * Sends the next batch of waiting records of one issuer (or of every issuer with waiting records) to the AEAT, once:
 * run it from a scheduler (cron) or use the SubmitPendingBillingRecords Messenger message instead. Honours the AEAT
 * wait time: a throttled issuer is reported with the time of its next allowed submission.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[AsCommand(
    name: 'nowo:verifactu:submit-pending',
    description: 'Send the waiting Veri*Factu records to the AEAT in batches (up to 1000 per request), honouring the AEAT wait time',
)]
final class SubmitPendingCommand extends Command
{
    public function __construct(
        private readonly BillingRecordBatchSubmitter $submitter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('issuer', null, InputOption::VALUE_REQUIRED, 'Issuer NIF (default: every issuer with waiting records)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $issuer  = strtoupper(trim((string) $input->getOption('issuer')));
        $issuers = $issuer !== '' ? [$issuer] : $this->submitter->issuersWithPendingRecords();
        $failed  = false;

        foreach ($issuers as $nif) {
            $result = $this->submitter->submitPending($nif);
            $failed = $failed || $result->isRetryable();
            $io->writeln(match ($result->state) {
                BatchSubmissionResult::STATE_SENT => sprintf(
                    '%s: %d sent (%d accepted, %d accepted with errors, %d rejected, %d without answer), %d waiting; next submission at %s.',
                    $nif,
                    count($result->outcomes),
                    $result->count(RecordSubmissionStatus::Accepted),
                    $result->count(RecordSubmissionStatus::AcceptedWithErrors),
                    $result->count(RecordSubmissionStatus::Rejected),
                    $result->count(null),
                    $result->remaining,
                    $result->nextSubmissionAt?->format(DATE_ATOM) ?? '-',
                ),
                BatchSubmissionResult::STATE_THROTTLED => sprintf('%s: %d waiting; the AEAT wait time ends at %s.', $nif, $result->remaining, $result->nextSubmissionAt?->format(DATE_ATOM) ?? '-'),
                BatchSubmissionResult::STATE_IN_FLIGHT => sprintf('%s: another process is sending a batch.', $nif),
                BatchSubmissionResult::STATE_FAILED    => sprintf('%s: no AEAT answer (%s); %d records stay pending.', $nif, $result->response->failure ?? '-', $result->remaining),
                default                                => sprintf('%s: nothing to send.', $nif),
            });
        }
        if ($issuers === []) {
            $io->writeln('Nothing to send.');
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
