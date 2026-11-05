<?php

/**
 * What needs no server.
 */

declare(strict_types=1);

use Nette\Database\Driver;
use Phlox\FirebirdDatabase\FirebirdDriver;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$driver = new FirebirdDriver();

Assert::same('"users"', $driver->delimite('users'));
Assert::same('"a""b"', $driver->delimite('a"b'));

Assert::same("'2024-02-03 04:05:06'", $driver->formatDateTime(new DateTimeImmutable('2024-02-03 04:05:06')));
Assert::same("'2024-02-03'", $driver->formatDateTime(new DateTimeImmutable('2024-02-03')), 'midnight fits DATE too');
Assert::exception(static fn() => $driver->formatDateInterval(new DateInterval('P1D')), Nette\NotSupportedException::class);

Assert::same("'%a%' ESCAPE '\\'", $driver->formatLike('a', 0));
Assert::same("'a%' ESCAPE '\\'", $driver->formatLike('a', 1));
Assert::same("'%a' ESCAPE '\\'", $driver->formatLike('a', -1));
Assert::same("'%5\\%\\_\\\\''x%' ESCAPE '\\'", $driver->formatLike("5%_\\'x", 0));

$limit = static function (string $sql, ?int $limit, ?int $offset) use ($driver): string {
    $driver->applyLimit($sql, $limit, $offset);
    return $sql;
};

Assert::same('SELECT FIRST 5 * FROM t', $limit('SELECT * FROM t', 5, null));
Assert::same('SELECT FIRST 5 SKIP 10 * FROM t', $limit('SELECT * FROM t', 5, 10));
Assert::same('SELECT SKIP 10 * FROM t', $limit('SELECT * FROM t', null, 10));
Assert::same('select FIRST 0 * FROM t', $limit('select * FROM t', 0, 0));
Assert::same('SELECT * FROM t', $limit('SELECT * FROM t', null, null));
Assert::same('DELETE FIRST 1 FROM t', $limit('DELETE FROM t', 1, null));
Assert::exception(static fn() => $limit('SELECT 1', -1, null), Nette\InvalidArgumentException::class);
Assert::exception(static fn() => $limit('SELECT 1', 1, -1), Nette\InvalidArgumentException::class);
Assert::exception(static fn() => $limit('WITH x AS (SELECT 1) SELECT * FROM x', 1, null), Nette\InvalidArgumentException::class);

Assert::false($driver->isSupported(Driver::SupportSequence), 'PDO_Firebird can not tell the last ID');
Assert::true($driver->isSupported(Driver::SupportMultiColumnAsOrCondition));
Assert::false($driver->isSupported(Driver::SupportSelectUngroupedColumns), 'Firebird refuses them');
Assert::false($driver->isSupported(Driver::SupportSchema));
