<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\InvoiceType;
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
        $result         = null;
        $aeatConfigured = $this->invoiceBillingService->isAeatConfigured();
        $form           = $this->createForm(InvoiceType::class, [
            'numserie'    => 'FAC-DEMO-001',
            'fecha'       => date('d-m-Y'),
            'record_type' => RecordType::Alta->value,
            'tipo'        => 'F1',
            'cuota'       => '21.00',
            'importe'     => '121.00',
            'description' => 'Servicios de consultoría demo',
            'submit_aeat' => true,
        ], ['aeat_configured' => $aeatConfigured]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array<string, mixed> $data */
            $data       = $form->getData();
            $recordType = RecordType::tryFrom((string) ($data['record_type'] ?? 'Alta')) ?? RecordType::Alta;

            $invoice = new Invoice(
                (string) ($data['numserie'] ?? 'FAC-DEMO-001'),
                (string) ($data['fecha'] ?? date('d-m-Y')),
                (string) ($data['cuota'] ?? '21.00'),
                (string) ($data['importe'] ?? '121.00'),
                $recordType,
                (string) ($data['tipo'] ?? 'F1'),
                (string) ($data['description'] ?? 'Demo invoice'),
            );

            $submitToAeat = true === ($data['submit_aeat'] ?? false) && $aeatConfigured;

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
            'aeatConfigured' => $aeatConfigured,
            'form'           => $form->createView(),
        ]);
    }
}
