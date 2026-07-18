<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DemoControllerTest extends WebTestCase
{
    public function testIndexPageLoads(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('VerifactuBundle Demo', $client->getResponse()->getContent());
        self::assertStringContainsString('Hash chain (Doctrine)', $client->getResponse()->getContent());
    }

    public function testPostGeneratesBillingRecord(): void
    {
        $client = static::createClient();
        $client->request('POST', '/', [
            'numserie'    => 'FAC-TEST-001',
            'fecha'       => '09-07-2026',
            'cuota'       => '21.00',
            'importe'     => '121.00',
            'tipo'        => 'F1',
            'record_type' => 'Alta',
            'description' => 'Test invoice',
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Registro generado', $client->getResponse()->getContent());
        self::assertStringContainsString('Hash:', $client->getResponse()->getContent());
    }
}
