<?php

declare(strict_types=1);

/*
 * Second transaction of DeadlockRecoveryTest. Usage: lock-holder.php <issuer it locks first> <issuer it locks next> <dir>
 * It writes 50 host rows (so InnoDB picks the other, smaller transaction as the deadlock victim), locks the first
 * issuer's chain row, writes <dir>/ready, waits for <dir>/go, then locks the second issuer's row (held by the test).
 */

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\VerifactuBundle\Tests\Concurrency\ConcurrencyKernel;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/** @var list<string> $arguments */
$arguments                      = $_SERVER['argv'];
[, $first, $second, $directory] = $arguments;

$kernel = new ConcurrencyKernel('test', false);
$kernel->boot();
/** @var ManagerRegistry $doctrine */
$doctrine = $kernel->getContainer()->get('doctrine');
/** @var Connection $connection */
$connection = $doctrine->getConnection();

$connection->beginTransaction();
for ($i = 1; $i <= 50; ++$i) {
    $connection->insert('host_invoice', ['number' => 'HOLDER-' . $i]);
}
$connection->fetchOne('SELECT hash FROM verifactu_hash_chain WHERE issuer_nif = ? FOR UPDATE', [$first]);
file_put_contents($directory . '/ready', '1');

$deadline = microtime(true) + 30;
while (!is_file($directory . '/go') && microtime(true) < $deadline) {
    usleep(10_000);
}
usleep(300_000);

try {
    $connection->fetchOne('SELECT hash FROM verifactu_hash_chain WHERE issuer_nif = ? FOR UPDATE', [$second]);
    $connection->rollBack();
} catch (Throwable $exception) {
    file_put_contents($directory . '/holder-error', $exception::class);
    if ($connection->isTransactionActive()) {
        $connection->rollBack();
    }
}
