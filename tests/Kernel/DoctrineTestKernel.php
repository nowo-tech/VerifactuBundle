<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Kernel;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel;

use function dirname;

/**
 * Kernel with Doctrine ORM for hash chain persistence integration tests.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class DoctrineTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function getProjectDir(): string
    {
        return dirname(__DIR__) . '/Fixtures/doctrine-app';
    }
}
