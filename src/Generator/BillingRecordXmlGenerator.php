<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Generator;

use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\HashChainState;
use Nowo\VerifactuBundle\Model\RecordType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

use function htmlspecialchars;
use function is_numeric;

use const ENT_QUOTES;
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

    /**
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
        $taxBase     = $this->calculateTaxBase($record->totalAmount, $record->totalTaxAmount);
        $taxRate     = $this->resolveTaxRate($record, $taxBase);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<RegistroAlta xmlns="' . $ns . '">' . "\n";
        $xml .= '  <IDVersion>1.0</IDVersion>' . "\n";
        $xml .= $this->renderIdFactura($record);
        $xml .= '  <NombreRazonEmisor>' . $this->escape($issuerName) . '</NombreRazonEmisor>' . "\n";
        $xml .= '  <TipoFactura>' . $this->escape($record->invoiceType) . '</TipoFactura>' . "\n";
        $xml .= '  <DescripcionOperacion>' . $this->escape($description) . '</DescripcionOperacion>' . "\n";
        $xml .= '  <Desglose>' . "\n";
        $xml .= '    <DetalleDesglose>' . "\n";
        $xml .= '      <CalificacionOperacion>S1</CalificacionOperacion>' . "\n";
        $xml .= '      <TipoImpositivo>' . $this->escape($taxRate) . '</TipoImpositivo>' . "\n";
        $xml .= '      <BaseImponibleOimporteNoSujeto>' . $this->escape($taxBase) . '</BaseImponibleOimporteNoSujeto>' . "\n";
        $xml .= '      <CuotaRepercutida>' . $this->escape($record->totalTaxAmount) . '</CuotaRepercutida>' . "\n";
        $xml .= '    </DetalleDesglose>' . "\n";
        $xml .= '  </Desglose>' . "\n";
        $xml .= '  <CuotaTotal>' . $this->escape($record->totalTaxAmount) . '</CuotaTotal>' . "\n";
        $xml .= '  <ImporteTotal>' . $this->escape($record->totalAmount) . '</ImporteTotal>' . "\n";
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
        if ($record->taxBreakdown !== []) {
            return $record->taxBreakdown[0]->taxRate;
        }

        if (is_numeric($taxBase) && (float) $taxBase > 0 && is_numeric($record->totalTaxAmount)) {
            $rate = ((float) $record->totalTaxAmount / (float) $taxBase) * 100;

            return number_format($rate, 2, '.', '');
        }

        return '21.00';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars(trim($value), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
