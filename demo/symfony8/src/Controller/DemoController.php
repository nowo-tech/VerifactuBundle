<?php

declare(strict_types=1);

namespace App\Controller;

use App\Model\Invoice;
use App\Service\InvoiceBillingService;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Qr\QrCodeGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Veri*Factu bundle interactive demo with Nowo invoice flow.
 */
final class DemoController extends AbstractController
{
    public function __construct(
        private readonly InvoiceBillingService $invoiceBillingService,
        private readonly QrCodeGenerator $qrCodeGenerator,
    ) {
    }

    #[Route('/', name: 'demo_index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $result = null;

        if ($request->isMethod('POST')) {
            $recordType = RecordType::tryFrom((string) $request->request->get('record_type', 'Alta'))
                ?? RecordType::Alta;

            $invoice = new Invoice(
                (string) $request->request->get('numserie', 'FAC-DEMO-001'),
                (string) $request->request->get('fecha', date('d-m-Y')),
                (string) $request->request->get('cuota', '21.00'),
                (string) $request->request->get('importe', '121.00'),
                $recordType,
                (string) $request->request->get('tipo', 'F1'),
                (string) $request->request->get('description', 'Demo invoice'),
            );

            $submitToAeat = $request->request->getBoolean('submit_aeat')
                && $this->invoiceBillingService->isAeatConfigured();

            $result = $this->invoiceBillingService->processInvoice($invoice, $submitToAeat);
        }

        $qrDataUri = null;
        if ($result !== null && $result['errors'] === [] && $result['record']->hash !== null) {
            $qrDataUri = $this->qrCodeGenerator->generateDataUri($result['record'], 'sandbox');
        }

        return $this->render('demo/index.html.twig', [
            'result'         => $result,
            'qrDataUri'      => $qrDataUri,
            'hashChainState' => $this->invoiceBillingService->getHashChainState(),
            'aeatConfigured' => $this->invoiceBillingService->isAeatConfigured(),
        ]);
    }
}
