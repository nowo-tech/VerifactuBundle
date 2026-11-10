<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Generator;

use Nowo\VerifactuBundle\Model\Amount;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\HashChainState;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Model\RectificationAmount;
use Nowo\VerifactuBundle\Model\RectifiedInvoice;
use Nowo\VerifactuBundle\Model\TaxBreakdown;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use function htmlspecialchars;
use function in_array;
use function is_numeric;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use const ENT_XML1;

/**
 * Generates AEAT-compliant RegistroAlta / RegistroAnulacion XML fragments.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[AsAlias(id: self::SERVICE_NAME, public: true)]
final class BillingRecordXmlGenerator
{
    public const SERVICE_NAME = 'nowo_verifactu.generator.billing_record_xml_generator';

    public const NS_SUMINISTRO = 'https://www2.agenciatributaria.gob.es/static_files/common/internet/dep/aplicaciones/es/aeat/tike/cont/ws/SuministroInformacion.xsd';

    /** CalificacionOperacion / OperacionExenta values that carry no TipoImpositivo nor CuotaRepercutida. */
    private const WITHOUT_RATE = ['N1', 'N2'];

    /**
     * Receives the ClaveRegimen written on breakdown lines that bring none (null = omitted, the output of 1.5).
     *
     * @param string|null $defaultRegimeKey nowo_verifactu.xml.default_regime_key
     */
    public function __construct(
        private readonly ?string $defaultRegimeKey = null,
    ) {
    }

    /**
     * Renders the billing record XML (RegistroAlta or RegistroAnulacion).
     *
     * @param array<string, mixed> $issuerConfig
     * @param array<string, mixed> $softwareConfig
     * @param array<string, mixed> $installationConfig
     */
    public function generate(
        BillingRecord $record,
        array $issuerConfig,
        array $softwareConfig,
        array $installationConfig,
        ?HashChainState $previousState = null,
    ): string {
        if ($record->recordType === RecordType::Anulacion) {
            return $this->generateAnulacion($record, $softwareConfig, $installationConfig, $previousState);
        }

        return $this->generateAlta($record, $issuerConfig, $softwareConfig, $installationConfig, $previousState);
    }

    /**
     * @param array<string, mixed> $issuerConfig
     * @param array<string, mixed> $softwareConfig
     * @param array<string, mixed> $installationConfig
     */
    private function generateAlta(
        BillingRecord $record,
        array $issuerConfig,
        array $softwareConfig,
        array $installationConfig,
        ?HashChainState $previousState,
    ): string {
        $hash        = $record->hash ?? '';
        $ns          = self::NS_SUMINISTRO;
        $issuerName  = (string) ($record->issuerName ?? $issuerConfig['name'] ?? '');
        $description = $record->operationDescription ?? 'Factura emitida';

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<RegistroAlta xmlns="' . $ns . '">' . "\n";
        $xml .= '  <IDVersion>1.0</IDVersion>' . "\n";
        $xml .= $this->renderIdFactura($record);
        $xml .= '  <NombreRazonEmisor>' . $this->escape($issuerName) . '</NombreRazonEmisor>' . "\n";
        $xml .= '  <TipoFactura>' . $this->escape($record->invoiceType) . '</TipoFactura>' . "\n";
        $xml .= $this->renderRectification($record);
        if ($record->operationDate !== null && trim($record->operationDate) !== '') {
            $xml .= '  <FechaOperacion>' . $this->escape($record->operationDate) . '</FechaOperacion>' . "\n";
        }
        $xml .= '  <DescripcionOperacion>' . $this->escape($description) . '</DescripcionOperacion>' . "\n";
        $xml .= $this->renderDestinatarios($record);
        $xml .= $this->renderDesglose($record);
        // The same canonical strings as the fingerprint (HashChainGenerator), so the XML and the Huella always agree.
        $xml .= '  <CuotaTotal>' . $this->escape(Amount::canonical($record->totalTaxAmount)) . '</CuotaTotal>' . "\n";
        $xml .= '  <ImporteTotal>' . $this->escape(Amount::canonical($record->totalAmount)) . '</ImporteTotal>' . "\n";
        $xml .= $this->renderEncadenamiento($previousState);
        $xml .= $this->renderSistemaInformatico($softwareConfig, $installationConfig);
        $xml .= '  <FechaHoraHusoGenRegistro>' . $this->escape($record->generatedAt) . '</FechaHoraHusoGenRegistro>' . "\n";
        $xml .= '  <TipoHuella>01</TipoHuella>' . "\n";
        $xml .= '  <Huella>' . $this->escape($hash) . '</Huella>' . "\n";

        return $xml . '</RegistroAlta>';
    }

    /**
     * @param array<string, mixed> $softwareConfig
     * @param array<string, mixed> $installationConfig
     */
    private function generateAnulacion(
        BillingRecord $record,
        array $softwareConfig,
        array $installationConfig,
        ?HashChainState $previousState,
    ): string {
        $hash = $record->hash ?? '';

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<RegistroAnulacion xmlns="' . self::NS_SUMINISTRO . '">' . "\n";
        $xml .= '  <IDVersion>1.0</IDVersion>' . "\n";
        $xml .= $this->renderIdFacturaAnulada($record);
        $xml .= $this->renderEncadenamiento($previousState);
        $xml .= $this->renderSistemaInformatico($softwareConfig, $installationConfig);
        $xml .= '  <FechaHoraHusoGenRegistro>' . $this->escape($record->generatedAt) . '</FechaHoraHusoGenRegistro>' . "\n";
        $xml .= '  <TipoHuella>01</TipoHuella>' . "\n";
        $xml .= '  <Huella>' . $this->escape($hash) . '</Huella>' . "\n";

        return $xml . '</RegistroAnulacion>';
    }

    private function renderIdFacturaAnulada(BillingRecord $record): string
    {
        $xml = '  <IDFactura>' . "\n";
        $xml .= '    <IDEmisorFacturaAnulada>' . $this->escape($record->issuerNif) . '</IDEmisorFacturaAnulada>' . "\n";
        $xml .= '    <NumSerieFacturaAnulada>' . $this->escape($record->invoiceSeriesNumber) . '</NumSerieFacturaAnulada>' . "\n";
        $xml .= '    <FechaExpedicionFacturaAnulada>' . $this->escape($record->issueDate) . '</FechaExpedicionFacturaAnulada>' . "\n";

        return $xml . ('  </IDFactura>' . "\n");
    }

    private function renderIdFactura(BillingRecord $record): string
    {
        $xml = '  <IDFactura>' . "\n";
        $xml .= '    <IDEmisorFactura>' . $this->escape($record->issuerNif) . '</IDEmisorFactura>' . "\n";
        $xml .= '    <NumSerieFactura>' . $this->escape($record->invoiceSeriesNumber) . '</NumSerieFactura>' . "\n";
        $xml .= '    <FechaExpedicionFactura>' . $this->escape($record->issueDate) . '</FechaExpedicionFactura>' . "\n";

        return $xml . ('  </IDFactura>' . "\n");
    }

    private function renderEncadenamiento(?HashChainState $previousState): string
    {
        $xml = '  <Encadenamiento>' . "\n";

        if ($previousState instanceof HashChainState && $previousState->hash !== '') {
            $xml .= '    <RegistroAnterior>' . "\n";
            $xml .= '      <IDEmisorFactura>' . $this->escape($previousState->issuerNif) . '</IDEmisorFactura>' . "\n";
            $xml .= '      <NumSerieFactura>' . $this->escape($previousState->invoiceSeriesNumber) . '</NumSerieFactura>' . "\n";
            $xml .= '      <FechaExpedicionFactura>' . $this->escape($previousState->issueDate) . '</FechaExpedicionFactura>' . "\n";
            $xml .= '      <Huella>' . $this->escape($previousState->hash) . '</Huella>' . "\n";
            $xml .= '    </RegistroAnterior>' . "\n";
        } else {
            $xml .= '    <PrimerRegistro>S</PrimerRegistro>' . "\n";
        }

        return $xml . ('  </Encadenamiento>' . "\n");
    }

    /**
     * @param array<string, mixed> $softwareConfig
     * @param array<string, mixed> $installationConfig
     */
    private function renderSistemaInformatico(array $softwareConfig, array $installationConfig): string
    {
        $soloVerifactu = ($softwareConfig['solo_verifactu'] ?? true) ? 'S' : 'N';

        $xml = '  <SistemaInformatico>' . "\n";
        $xml .= '    <NombreRazon>' . $this->escape((string) ($softwareConfig['manufacturer_name'] ?? '')) . '</NombreRazon>' . "\n";
        $xml .= '    <NIF>' . $this->escape((string) ($softwareConfig['manufacturer_nif'] ?? '')) . '</NIF>' . "\n";
        $xml .= '    <NombreSistemaInformatico>' . $this->escape((string) ($softwareConfig['name'] ?? '')) . '</NombreSistemaInformatico>' . "\n";
        $xml .= '    <IdSistemaInformatico>' . $this->escape((string) ($softwareConfig['id'] ?? '')) . '</IdSistemaInformatico>' . "\n";
        $xml .= '    <Version>' . $this->escape((string) ($softwareConfig['version'] ?? '')) . '</Version>' . "\n";
        $xml .= '    <NumeroInstalacion>' . $this->escape((string) ($installationConfig['number'] ?? '')) . '</NumeroInstalacion>' . "\n";
        $xml .= '    <TipoUsoPosibleSoloVerifactu>' . $soloVerifactu . '</TipoUsoPosibleSoloVerifactu>' . "\n";
        $xml .= '    <TipoUsoPosibleMultiOT>N</TipoUsoPosibleMultiOT>' . "\n";
        $xml .= '    <IndicadorMultiplesOT>N</IndicadorMultiplesOT>' . "\n";

        return $xml . ('  </SistemaInformatico>' . "\n");
    }

    /**
     * TipoRectificativa, FacturasRectificadas, FacturasSustituidas and ImporteRectificacion, in the XSD order; nothing
     * when the record has none of them.
     */
    private function renderRectification(BillingRecord $record): string
    {
        $xml = '';
        if ($record->rectificationType !== null && trim($record->rectificationType) !== '') {
            $xml .= '  <TipoRectificativa>' . $this->escape($record->rectificationType) . '</TipoRectificativa>' . "\n";
        }
        $xml .= $this->renderInvoiceReferences('FacturasRectificadas', 'IDFacturaRectificada', $record->rectifiedInvoices);
        $xml .= $this->renderInvoiceReferences('FacturasSustituidas', 'IDFacturaSustituida', $record->substitutedInvoices);

        if ($record->rectificationAmount instanceof RectificationAmount) {
            $amount = $record->rectificationAmount;
            $xml .= '  <ImporteRectificacion>' . "\n";
            $xml .= '    <BaseRectificada>' . $this->escape(Amount::canonical($amount->base)) . '</BaseRectificada>' . "\n";
            $xml .= '    <CuotaRectificada>' . $this->escape(Amount::canonical($amount->tax)) . '</CuotaRectificada>' . "\n";
            if ($amount->surcharge !== null) {
                $xml .= '    <CuotaRecargoRectificado>' . $this->escape(Amount::canonical($amount->surcharge)) . '</CuotaRecargoRectificado>' . "\n";
            }
            $xml .= '  </ImporteRectificacion>' . "\n";
        }

        return $xml;
    }

    /**
     * @param list<RectifiedInvoice> $invoices
     */
    private function renderInvoiceReferences(string $group, string $element, array $invoices): string
    {
        if ($invoices === []) {
            return '';
        }

        $xml = '  <' . $group . '>' . "\n";
        foreach ($invoices as $invoice) {
            $xml .= '    <' . $element . '>' . "\n";
            $xml .= '      <IDEmisorFactura>' . $this->escape($invoice->issuerNif) . '</IDEmisorFactura>' . "\n";
            $xml .= '      <NumSerieFactura>' . $this->escape($invoice->invoiceSeriesNumber) . '</NumSerieFactura>' . "\n";
            $xml .= '      <FechaExpedicionFactura>' . $this->escape($invoice->issueDate) . '</FechaExpedicionFactura>' . "\n";
            $xml .= '    </' . $element . '>' . "\n";
        }

        return $xml . '  </' . $group . '>' . "\n";
    }

    private function renderDestinatarios(BillingRecord $record): string
    {
        if ($record->recipients === []) {
            return '';
        }

        $xml = '  <Destinatarios>' . "\n";
        foreach ($record->recipients as $recipient) {
            $xml .= '    <IDDestinatario>' . "\n";
            $xml .= '      <NombreRazon>' . $this->escape($recipient->name) . '</NombreRazon>' . "\n";
            if ($recipient->nif !== null) {
                $xml .= '      <NIF>' . $this->escape($recipient->nif) . '</NIF>' . "\n";
            } else {
                $xml .= '      <IDOtro>' . "\n";
                if ($recipient->countryCode !== null && trim($recipient->countryCode) !== '') {
                    $xml .= '        <CodigoPais>' . $this->escape(strtoupper($recipient->countryCode)) . '</CodigoPais>' . "\n";
                }
                $xml .= '        <IDType>' . $this->escape((string) $recipient->foreignIdType) . '</IDType>' . "\n";
                $xml .= '        <ID>' . $this->escape((string) $recipient->foreignId) . '</ID>' . "\n";
                $xml .= '      </IDOtro>' . "\n";
            }
            $xml .= '    </IDDestinatario>' . "\n";
        }

        return $xml . '  </Destinatarios>' . "\n";
    }

    /**
     * Renders one DetalleDesglose per TaxBreakdown entry; without breakdown, a single S1 line derived from the totals.
     */
    private function renderDesglose(BillingRecord $record): string
    {
        $xml = '  <Desglose>' . "\n";

        if ($record->taxBreakdown !== []) {
            foreach ($record->taxBreakdown as $breakdown) {
                $xml .= $this->renderDetalleDesglose($breakdown);
            }
        } else {
            $taxBase = $this->calculateTaxBase($record->totalAmount, $record->totalTaxAmount);
            $xml .= $this->renderDetalleDesglose(new TaxBreakdown('', $this->resolveTaxRate($record, $taxBase), $taxBase, $record->totalTaxAmount));
        }

        return $xml . ('  </Desglose>' . "\n");
    }

    /**
     * DetalleDesglose in the XSD order: Impuesto?, ClaveRegimen?, CalificacionOperacion | OperacionExenta,
     * TipoImpositivo?, BaseImponibleOimporteNoSujeto, CuotaRepercutida?. Impuesto (AEAT ImpuestoType: 01 IVA, 02 IPSI,
     * 03 IGIC, 05 Otros) is optional (AEAT assumes 01). N1 / N2 and exempt lines carry no rate nor tax (validations
     * v1.2.2, §15.4 and §15.5).
     */
    private function renderDetalleDesglose(TaxBreakdown $breakdown): string
    {
        $regimeKey     = $breakdown->regimeKey ?? $this->defaultRegimeKey;
        $exemption     = $breakdown->exemption !== null && trim($breakdown->exemption) !== '' ? trim($breakdown->exemption) : null;
        $qualification = $exemption === null ? strtoupper(trim($breakdown->qualification ?? '')) : '';
        $qualification = $exemption === null && $qualification === '' ? 'S1' : $qualification;
        $withRate      = $exemption === null && !in_array($qualification, self::WITHOUT_RATE, true);

        $xml = '    <DetalleDesglose>' . "\n";
        if (trim($breakdown->taxType) !== '') {
            $xml .= '      <Impuesto>' . $this->escape($breakdown->taxType) . '</Impuesto>' . "\n";
        }
        if ($regimeKey !== null && trim($regimeKey) !== '') {
            $xml .= '      <ClaveRegimen>' . $this->escape($regimeKey) . '</ClaveRegimen>' . "\n";
        }
        $xml .= $exemption !== null
            ? '      <OperacionExenta>' . $this->escape($exemption) . '</OperacionExenta>' . "\n"
            : '      <CalificacionOperacion>' . $this->escape($qualification) . '</CalificacionOperacion>' . "\n";
        if ($withRate) {
            $xml .= '      <TipoImpositivo>' . $this->escape($breakdown->taxRate) . '</TipoImpositivo>' . "\n";
        }
        $xml .= '      <BaseImponibleOimporteNoSujeto>' . $this->escape($breakdown->taxBase) . '</BaseImponibleOimporteNoSujeto>' . "\n";
        if ($withRate) {
            $xml .= '      <CuotaRepercutida>' . $this->escape($breakdown->taxAmount) . '</CuotaRepercutida>' . "\n";
        }

        return $xml . ('    </DetalleDesglose>' . "\n");
    }

    private function calculateTaxBase(string $totalAmount, string $totalTaxAmount): string
    {
        if (!is_numeric($totalAmount) || !is_numeric($totalTaxAmount)) {
            return '0.00';
        }

        $base = (float) $totalAmount - (float) $totalTaxAmount;

        return number_format(max(0, $base), 2, '.', '');
    }

    private function resolveTaxRate(BillingRecord $record, string $taxBase): string
    {
        if (is_numeric($taxBase) && (float) $taxBase > 0 && is_numeric($record->totalTaxAmount)) {
            $rate = ((float) $record->totalTaxAmount / (float) $taxBase) * 100;

            return number_format($rate, 2, '.', '');
        }

        return '21.00';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars(trim($value), ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
