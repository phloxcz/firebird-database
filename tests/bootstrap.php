<?php

declare(strict_types=1);

use Nette\Database\Connection;
use Phlox\FirebirdDatabase\FirebirdDriver;

require __DIR__ . '/../vendor/autoload.php';

Tester\Environment::setup();

/**
 * A connection to the database in TEST_DSN, such as
 * firebird:dbname=127.0.0.1/3055:/var/lib/firebird/data/test.fdb;charset=UTF8
 */
function createConnection(?string $charset = null): Connection
{
    $dsn = getenv('TEST_DSN');
    if (!$dsn) {
        Tester\Environment::skip('TEST_DSN with a Firebird database is not set.');
    }

    if ($charset !== null) {
        $dsn = (string) preg_replace('/charset=\w+/', "charset={$charset}", $dsn);
    }

    return new Connection($dsn, getenv('TEST_USER') ?: 'SYSDBA', getenv('TEST_PASSWORD') ?: 'masterkey', [
        'driverClass' => FirebirdDriver::class,
    ]);
}

function dropTables(Connection $connection, string ...$tables): void
{
    foreach ($tables as $table) {
        foreach (["DROP VIEW {$table}", "DROP TABLE {$table}"] as $sql) {
            try {
                $connection->getPdo()->exec($sql);
                break;
            } catch (PDOException) {
                // wasn't there, or is the other kind
            }
        }
    }
}
