<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Validator;

use DOMDocument;
use InvalidArgumentException;
use LibXMLError;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Validates billing record XML against official AEAT XSD schemas.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[AsAlias(id: self::SERVICE_NAME, public: true)]
class XsdValidator
{
    public const SERVICE_NAME = 'nowo_verifactu.validator.xsd_validator';

    public const SCHEMA_REGISTRO_ALTA      = 'registro_alta';
    public const SCHEMA_REGISTRO_ANULACION = 'registro_anulacion';
    public const SCHEMA_SOAP_SUBMISSION    = 'soap_submission';

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly bool $enabled = true,
    ) {
    }

    /**
     * @return list<string> Validation errors (empty when valid)
     */
    public function validate(string $xml, string $schemaType = self::SCHEMA_REGISTRO_ALTA): array
    {
        if (!$this->enabled) {
            return [];
        }

        $xsdPath = $this->resolveSchemaPath($schemaType);
        if ($xsdPath === null) {
            return [];
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $loaded      = @$dom->loadXML($xml);
        $parseErrors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        if (!$loaded) {
            return [$this->formatLibxmlErrors('validation.xml.parse_failed', $parseErrors)];
        }

        libxml_use_internal_errors(true);
        $valid        = @$dom->schemaValidate($xsdPath);
        $schemaErrors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors(false);

        if ($valid) {
            return [];
        }

        return [$this->formatLibxmlErrors('validation.xml.xsd.failed', $schemaErrors)];
    }

    public function assertValid(string $xml, string $schemaType = self::SCHEMA_REGISTRO_ALTA): void
    {
        $errors = $this->validate($xml, $schemaType);
        if ($errors !== []) {
            throw new InvalidArgumentException(implode('; ', $errors));
        }
    }

    private function resolveSchemaPath(string $schemaType): ?string
    {
        $basePath = __DIR__ . '/../Resources/schemas/aeat/';
        $schemas  = [
            self::SCHEMA_REGISTRO_ALTA      => $basePath . 'SuministroInformacion.xsd',
            self::SCHEMA_REGISTRO_ANULACION => $basePath . 'SuministroInformacion.xsd',
            self::SCHEMA_SOAP_SUBMISSION    => $basePath . 'SuministroLR.xsd',
        ];

        $path = $schemas[$schemaType] ?? null;

        return ($path !== null && file_exists($path)) ? $path : null;
    }

    /**
     * @param list<LibXMLError> $errors
     */
    private function formatLibxmlErrors(string $key, array $errors): string
    {
        $messages = array_map(static fn (LibXMLError $error): string => trim($error->message), $errors);

        return $this->translator->trans($key, ['%errors%' => implode('; ', $messages)], 'NowoVerifactuBundle');
    }
}
