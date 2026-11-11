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

use function array_filter;
use function array_key_first;
use function count;
use function ctype_digit;
use function in_array;
use function is_string;
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
        $this->addOption('retry-failed', null, InputOption::VALUE_NONE, 'Put the records the submitter failed for --issuer back to pending first (after fixing what the AEAT refused)');
        $this->addOption('list', null, InputOption::VALUE_NONE, 'List the unconfirmed, held and failed records of --issuer, and send nothing');
        $this->addOption('resolve', null, InputOption::VALUE_REQUIRED, 'Position of an unconfirmed record of --issuer to resolve after checking it at the AEAT (with --as), and send nothing');
        $this->addOption('as', null, InputOption::VALUE_REQUIRED, 'With --resolve: accepted, accepted_errors or rejected');
        $this->addOption('csv', null, InputOption::VALUE_REQUIRED, 'With --resolve --as=accepted|accepted_errors: the CSV the AEAT gave for the record');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io          = new SymfonyStyle($input, $output);
        $issuer      = strtoupper(trim((string) $input->getOption('issuer')));
        $failed      = false;
        $needsIssuer = array_filter(['--retry-failed' => $input->getOption('retry-failed') === true, '--list' => $input->getOption('list') === true, '--resolve' => $input->getOption('resolve') !== null]);
        if ($needsIssuer !== [] && $issuer === '') {
            $io->error(sprintf('%s needs --issuer.', array_key_first($needsIssuer)));

            return Command::INVALID;
        }

        if ($input->getOption('list') === true) {
            return $this->list($io, $issuer);
        }
        if ($input->getOption('resolve') !== null) {
            return $this->resolve($io, $issuer, (string) $input->getOption('resolve'), (string) $input->getOption('as'), $input->getOption('csv'));
        }
        if ($input->getOption('retry-failed') === true) {
            $io->writeln(sprintf('%s: %d failed records back to pending.', $issuer, $this->submitter->requeueFailed($issuer)));
        }

        $issuers = $issuer !== '' ? [$issuer] : $this->submitter->issuersWithPendingRecords();
        foreach ($issuers as $nif) {
            $result = $this->submitter->submitPending($nif);
            $failed = $failed || in_array($result->state, [BatchSubmissionResult::STATE_FAILED, BatchSubmissionResult::STATE_PAUSED], true) || $result->failed !== [];
            $io->writeln(match ($result->state) {
                BatchSubmissionResult::STATE_SENT => sprintf(
                    '%s: %d sent (%d accepted, %d accepted with errors, %d rejected, %d unconfirmed duplicates, %d without answer), %d waiting; next submission at %s.',
                    $nif,
                    count($result->outcomes),
                    $result->count(RecordSubmissionStatus::Accepted),
                    $result->count(RecordSubmissionStatus::AcceptedWithErrors),
                    $result->count(RecordSubmissionStatus::Rejected),
                    $result->count(RecordSubmissionStatus::Unconfirmed),
                    $result->count(null),
                    $result->remaining,
                    $result->nextSubmissionAt?->format(DATE_ATOM) ?? '-',
                ),
                BatchSubmissionResult::STATE_THROTTLED => sprintf('%s: %d waiting; the AEAT wait time ends at %s.', $nif, $result->remaining, $result->nextSubmissionAt?->format(DATE_ATOM) ?? '-'),
                BatchSubmissionResult::STATE_IN_FLIGHT => sprintf('%s: another process is sending a batch.', $nif),
                BatchSubmissionResult::STATE_PAUSED    => sprintf('%s: paused until %s: the AEAT refuses every request of this issuer (%s); %d records stay pending.', $nif, $result->nextSubmissionAt?->format(DATE_ATOM) ?? '-', $result->response->failure ?? 'paused earlier', $result->remaining),
                BatchSubmissionResult::STATE_FAILED    => sprintf('%s: no AEAT answer (%s); %d records stay pending.', $nif, $result->response->failure ?? '-', $result->remaining),
                default                                => sprintf('%s: nothing to send.', $nif),
            });
            foreach ($result->failed as $stored) {
                $io->writeln(sprintf('%s: the AEAT kept refusing record %d (%s) while the next record was answered; it is marked failed (--retry-failed puts it back).', $nif, $stored->position, $stored->submissionError ?? '-'));
            }
        }
        if ($issuers === []) {
            $io->writeln('Nothing to send.');
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    private function list(SymfonyStyle $io, string $issuer): int
    {
        $rows = [];
        foreach ($this->submitter->recordsToReview($issuer) as $stored) {
            $rows[] = [$stored->position, $stored->record->recordType->value, $stored->record->invoiceSeriesNumber, $stored->record->issueDate, $stored->submissionStatus, $stored->submissionAttempts, (string) $stored->submissionError];
        }
        if ($rows === []) {
            $io->writeln(sprintf('%s: no unconfirmed, held or failed records.', $issuer));

            return Command::SUCCESS;
        }
        $io->table(['Position', 'Operation', 'Invoice', 'Issue date', 'Status', 'Attempts', 'Last error'], $rows);

        return Command::SUCCESS;
    }

    private function resolve(SymfonyStyle $io, string $issuer, string $position, string $as, mixed $csv): int
    {
        $status = RecordSubmissionStatus::tryFrom($as);
        if (!ctype_digit($position) || !in_array($status, [RecordSubmissionStatus::Accepted, RecordSubmissionStatus::AcceptedWithErrors, RecordSubmissionStatus::Rejected], true)) {
            $io->error('--resolve needs a record position and --as=accepted|accepted_errors|rejected.');

            return Command::INVALID;
        }
        if (!$this->submitter->resolveUnconfirmed($issuer, (int) $position, $status, is_string($csv) && trim($csv) !== '' ? trim($csv) : null)) {
            $io->error(sprintf('%s: record %s is not unconfirmed.', $issuer, $position));

            return Command::FAILURE;
        }
        $io->writeln(sprintf('%s: record %s resolved as %s.', $issuer, $position, $status->value));

        return Command::SUCCESS;
    }
}
