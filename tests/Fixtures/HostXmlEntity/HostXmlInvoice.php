<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Fixtures\HostXmlEntity;

/**
 * A plain-PHP host entity of a DDD host, mapped only in XML (mapping/HostXmlInvoice.orm.xml).
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
class HostXmlInvoice
{
    public ?int $id = null;

    public function __construct(
        public string $number,
        public string $issuerNif,
    ) {
    }
}
