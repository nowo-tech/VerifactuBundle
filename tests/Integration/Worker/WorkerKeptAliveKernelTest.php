<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Integration\Worker;

use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Model\StoredBillingRecord;
use Nowo\VerifactuBundle\Repository\HashChainRepositoryInterface;
use Nowo\VerifactuBundle\Repository\InMemoryBillingRecordStore;
use Nowo\VerifactuBundle\Service\BillingRecordProcessor;
use Nowo\VerifactuBundle\Tests\Kernel\MemoryStoreTestKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function array_map;
use function gc_collect_cycles;
use function memory_get_usage;
use function sprintf;

/**
 * REQ-RUNTIME-002 with the in-memory storages (`hash_chain` default, `record_store.storage: memory`): ONE kernel, `http_kernel` handle() + terminate() N times
 * alternating two issuers (tenants), `services_resetter` / `kernel.reset` never called.
 *
 * The in-memory hash chain and record store are cleared by `WorkerStateResetSubscriber` at the start of every main
 * request: each request must start an empty chain, see only its own records, and memory must stay flat.
 */
final class WorkerKeptAliveKernelTest extends KernelTestCase
{
    private const ITERATIONS = 1000;

    private const WARM_UP = 50;

    private const MEMORY_MARGIN_BYTES = 4 * 1024 * 1024;

    private const ISSUERS = ['89890001K', 'B12345674'];

    protected static function getKernelClass(): string
    {
        return MemoryStoreTestKernel::class;
    }

    public function testInMemoryStorageInOneKernelWithoutResetterNeverCarriesStateBetweenRequests(): void
    {
        self::bootKernel(['debug' => false]);
        $container = self::getContainer();
        $kernel    = $container->get('http_kernel');
        self::assertInstanceOf(HttpKernel::class, $kernel);

        // Fetched once and kept, as a kept-alive worker does.
        /** @var BillingRecordProcessor $processor */
        $processor = $container->get(BillingRecordProcessor::class);
        /** @var HashChainRepositoryInterface $chain */
        $chain = $container->get(HashChainRepositoryInterface::class);
        /** @var InMemoryBillingRecordStore $store */
        $store = $container->get(InMemoryBillingRecordStore::SERVICE_NAME);

        $baseline = 0;

        for ($i = 0; $i < self::ITERATIONS; ++$i) {
            if ($i === self::WARM_UP) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }

            $issuer = self::ISSUERS[$i % 2];
            $other  = self::ISSUERS[($i + 1) % 2];
            $at     = sprintf('iteration %d (%s)', $i, $issuer);

            $request = Request::create('/');
            $request->attributes->set('_controller', function () use ($processor, $chain, $store, $issuer, $other, $i, $at): Response {
                // A new main request: nothing of the previous request (either issuer) is left.
                self::assertNull($chain->getLastState($issuer), $at);
                self::assertNull($chain->getLastState($other), $at);
                self::assertSame([], [...$store->findByIssuer($issuer)], $at);
                self::assertSame([], [...$store->findByIssuer($other)], $at);

                $first  = $processor->process($this->record($issuer, sprintf('FAC-%05d-1', $i)));
                $second = $processor->process($this->record($issuer, sprintf('FAC-%05d-2', $i)));
                self::assertSame([], $first['errors'], $at);
                self::assertSame([], $second['errors'], $at);
                self::assertNull($first['record']->previousHash, $at);
                self::assertSame($first['record']->hash, $second['record']->previousHash, $at);

                $stored = [...$store->findByIssuer($issuer)];
                self::assertSame([$issuer, $issuer], array_map(static fn (StoredBillingRecord $s): string => $s->record->issuerNif, $stored), $at);
                self::assertSame([], [...$store->findByIssuer($other)], $at);

                return new Response('ok');
            });

            $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
            self::assertSame('ok', $response->getContent(), $at);
            $kernel->terminate($request, $response);
        }

        gc_collect_cycles();
        $growth = memory_get_usage() - $baseline;
        self::assertLessThan(self::MEMORY_MARGIN_BYTES, $growth, sprintf('memory grew by %d bytes over %d requests', $growth, self::ITERATIONS - self::WARM_UP));
    }

    private function record(string $issuerNif, string $invoiceSeriesNumber): BillingRecord
    {
        return new BillingRecord(
            RecordType::Alta,
            $issuerNif,
            $invoiceSeriesNumber,
            '09-07-2026',
            'F1',
            '21.00',
            '121.00',
            '2026-07-09T16:00:00+02:00',
            issuerName: 'Tenant ' . $issuerNif,
            operationDescription: 'Kept-alive worker invoice',
        );
    }
}
