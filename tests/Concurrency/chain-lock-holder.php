<?php

declare(strict_types=1);

/*
 * Holds the chain lock of an issuer for a while (ManualFailLockTest). Usage: chain-lock-holder.php <issuer> <dir> <ms>
 * It locks the issuer's chain row, writes <dir>/ready, keeps the lock <ms> milliseconds, writes <dir>/released and
 * commits.
 */

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\VerifactuBundle\Tests\Concurrency\ConcurrencyKernel;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/** @var list<string> $arguments */
$arguments                     = $_SERVER['argv'];
[, $issuer, $directory, $hold] = $arguments;

$kernel = new ConcurrencyKernel('test', false);
$kernel->boot();
/** @var ManagerRegistry $doctrine */
$doctrine = $kernel->getContainer()->get('doctrine');
/** @var Connection $connection */
$connection = $doctrine->getConnection();

$connection->beginTransaction();
$connection->fetchOne('SELECT hash FROM verifactu_hash_chain WHERE issuer_nif = ? FOR UPDATE', [$issuer]);
file_put_contents($directory . '/ready', '1');
usleep(1000 * (int) $hold);
file_put_contents($directory . '/released', (string) microtime(true));
$connection->commit();
