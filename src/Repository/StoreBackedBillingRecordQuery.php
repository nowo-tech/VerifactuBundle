<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Repository;

use Nowo\VerifactuBundle\Model\BillingRecordCriteria;
use Nowo\VerifactuBundle\Model\BillingRecordSort;
use Nowo\VerifactuBundle\Model\BillingRecordView;

use function array_slice;
use function count;
use function in_array;
use function usort;

/**
 * Read port over any BillingRecordStoreInterface (1.10): the default for the in-memory store and for a custom
 * record_store.service. It walks the issuer's chain (findByIssuer()) on every call and filters, sorts and pages in
 * PHP, so it reads the whole chain each time: for large logs in your own persistence, implement
 * BillingRecordQueryInterface there and set record_store.query.
 *
 * Keeps no state between calls (FrankenPHP worker mode without kernel reset). Views from the store carry no
 * `recordedAt` / `submittedAt` (StoredBillingRecord has neither).
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class StoreBackedBillingRecordQuery implements BillingRecordQueryInterface
{
    /**
     * Receives the record store to read.
     */
    public function __construct(
        private readonly BillingRecordStoreInterface $store,
    ) {
    }

    public function page(BillingRecordCriteria $criteria, int $offset = 0, int $limit = 50): array
    {
        BillingRecordQueryPaging::assertPage($offset, $limit);

        $views = $this->matching($criteria);
        $sort  = $criteria->sort;
        usort($views, static function (BillingRecordView $a, BillingRecordView $b) use ($sort): int {
            $order = match ($sort) {
                BillingRecordSort::IssueDateAsc, BillingRecordSort::IssueDateDesc => [BillingRecordCriteria::issueDateKey($a->issueDate), $a->position] <=> [BillingRecordCriteria::issueDateKey($b->issueDate), $b->position],
                default                                                           => $a->position <=> $b->position,
            };

            return $sort === BillingRecordSort::PositionAsc || $sort === BillingRecordSort::IssueDateAsc ? $order : -$order;
        });

        return array_slice($views, $offset, $limit);
    }

    public function count(BillingRecordCriteria $criteria): int
    {
        return count($this->matching($criteria));
    }

    public function findByPosition(string $issuerNif, int $position): ?BillingRecordView
    {
        foreach ($this->chain(BillingRecordCriteria::normalizeIssuer($issuerNif)) as $view) {
            if ($view->position === $position) {
                return $view;
            }
        }

        return null;
    }

    public function findByInvoice(string $issuerNif, string $invoiceSeriesNumber): ?BillingRecordView
    {
        return $this->findLatestByInvoices($issuerNif, [$invoiceSeriesNumber])[0] ?? null;
    }

    public function findLatestByInvoices(string $issuerNif, array $invoiceSeriesNumbers): array
    {
        $numbers = BillingRecordQueryPaging::distinctInvoices($invoiceSeriesNumbers);
        $wanted  = array_flip($numbers);
        $latest  = [];
        foreach ($this->chain(BillingRecordCriteria::normalizeIssuer($issuerNif)) as $view) {
            if (isset($wanted[$view->invoiceSeriesNumber]) && $view->position > ($latest[$view->invoiceSeriesNumber]->position ?? 0)) {
                $latest[$view->invoiceSeriesNumber] = $view;
            }
        }

        $found = [];
        foreach ($numbers as $number) {
            if (isset($latest[$number])) {
                $found[] = $latest[$number];
            }
        }

        return $found;
    }

    /**
     * The issuer's records matching every filter, in chain order.
     *
     * @return list<BillingRecordView>
     */
    private function matching(BillingRecordCriteria $criteria): array
    {
        $matching = [];
        foreach ($this->chain($criteria->issuerNif) as $view) {
            if ($this->matches($view, $criteria)) {
                $matching[] = $view;
            }
        }

        return $matching;
    }

    private function matches(BillingRecordView $view, BillingRecordCriteria $criteria): bool
    {
        $issued = BillingRecordCriteria::issueDateKey($view->issueDate);

        return ($criteria->statuses === [] || in_array($view->submissionStatus, $criteria->statuses, true))
            && ($criteria->recordTypes === [] || in_array($view->recordType, $criteria->recordTypes, true))
            && ($criteria->invoiceTypes === [] || in_array(strtoupper($view->invoiceType), $criteria->invoiceTypes, true))
            && ($criteria->invoiceSeriesNumber === null || $view->invoiceSeriesNumber === $criteria->invoiceSeriesNumber)
            && ($criteria->issuedFrom === null || $issued >= $criteria->issuedFrom)
            && ($criteria->issuedTo === null || $issued <= $criteria->issuedTo);
    }

    /**
     * The views of the issuer's chain; nothing for an empty scope or another issuer's records.
     *
     * @return iterable<BillingRecordView>
     */
    private function chain(string $key): iterable
    {
        if ($key === '') {
            return;
        }

        foreach ($this->store->findByIssuer($key) as $stored) {
            $view = BillingRecordView::fromStored($stored);
            if ($view->issuerNif === $key) {
                yield $view;
            }
        }
    }
}
