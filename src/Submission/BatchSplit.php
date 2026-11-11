<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Submission;

use function intdiv;
use function max;
use function min;

/**
 * How a batch the AEAT refuses as a whole is split (1.7): the claim is halved once per submission failure of the first
 * waiting record, never below 1, so the record at fault ends up alone (SubmissionQueueInterface::claimNext()).
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class BatchSplit
{
    /**
     * The claim size after $failures refusals of the first waiting record.
     */
    public static function claimLimit(int $limit, int $failures): int
    {
        return max(1, intdiv(max(1, $limit), 2 ** min(20, max(0, $failures))));
    }

    /**
     * Refusals needed to split a batch of $batchSize down to one record (one halving per refusal).
     */
    public static function halvings(int $batchSize): int
    {
        $halvings = 0;
        while (self::claimLimit($batchSize, $halvings) > 1) {
            ++$halvings;
        }

        return $halvings;
    }
}
