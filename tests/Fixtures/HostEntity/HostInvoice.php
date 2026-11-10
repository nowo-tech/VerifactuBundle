<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Fixtures\HostEntity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A host entity of a standard Symfony project (attribute mapping), used to prove the bundle never flushes it.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[ORM\Entity]
#[ORM\Table(name: 'host_invoice')]
class HostInvoice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column(type: Types::STRING, length: 60)]
        public string $number,
    ) {
    }
}
