<?php

/**
 * Values coming out of a real server, in a character set of more bytes per
 * character and of one byte per character. In the latter, CHAR(4) can't be told
 * from FLOAT by its length.
 */

declare(strict_types=1);

use Nette\Database\Connection;
use Nette\Utils\DateTime;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$setUp = createConnection();
$version = (string) $setUp->getPdo()->query("SELECT rdb\$get_context('SYSTEM','ENGINE_VERSION') FROM rdb\$database")->fetchColumn();

dropTables($setUp, 'VALS');
$setUp->getPdo()->exec('CREATE TABLE VALS (
    S SMALLINT, I INTEGER, L BIGINT,
    N2 NUMERIC(4,2), N9 NUMERIC(9,2), N18 NUMERIC(18,2), D3 DECIMAL(9,3), N0 NUMERIC(9,0),
    F FLOAT, DP DOUBLE PRECISION, DPI DOUBLE PRECISION, FI FLOAT,
    DT DATE, TM TIME, TS TIMESTAMP, TSF TIMESTAMP,
    BO BOOLEAN,
    C1 CHAR(1), C2 CHAR(2), C4 CHAR(4), C8 CHAR(8), C4D CHAR(4),
    V1 VARCHAR(1), V2 VARCHAR(2), V4 VARCHAR(4), V8 VARCHAR(8), V20 VARCHAR(20),
    O4 CHAR(4) CHARACTER SET OCTETS, O8 CHAR(8) CHARACTER SET OCTETS,
    W4 VARCHAR(4) CHARACTER SET WIN1250, W8 CHAR(8) CHARACTER SET WIN1250, W4D CHAR(4) CHARACTER SET WIN1250,
    N4 CHAR(4) CHARACTER SET NONE,
    BT BLOB SUB_TYPE TEXT, BB BLOB SUB_TYPE BINARY, BTN BLOB SUB_TYPE TEXT,
    NIL INTEGER, NILS VARCHAR(4), NILF FLOAT, NILD DATE
)');
$setUp->getPdo()->exec("INSERT INTO VALS (
    S, I, L, N2, N9, N18, D3, N0, F, DP, DPI, FI, DT, TM, TS, TSF, BO,
    C1, C2, C4, C8, C4D, V1, V2, V4, V8, V20, O4, O8, W4, W8, W4D, N4, BT, BB, BTN
) VALUES (
    1, 2, 3000000000, 1.5, 12.25, 123456.78, 1.125, 7, 1.5, 2.25, 5, 7, '2024-02-03', '04:05:06', '2024-02-03 04:05:06', '2024-02-03 04:05:06.5', TRUE,
    '5', '12', '0123', '20240203', '1.50', 'y', '34', '0123', '20240203', '2024-02-03', '0123', '20240203', '0123', '20240203', '1.50', '0123', 'long text', 'binary', '20240203'
)");
$setUp->disconnect();

foreach (['UTF8', 'WIN1250'] as $charset) {
    $connection = createConnection($charset);
    $row = $connection->fetch('SELECT * FROM VALS');
    $at = "charset {$charset}: ";

    // Integers
    Assert::same(1, $row->S, $at . 'SMALLINT');
    Assert::same(2, $row->I, $at . 'INTEGER');
    Assert::same(3000000000, $row->L, $at . 'BIGINT');
    Assert::same(7, $row->N0, $at . 'NUMERIC without decimals');

    // Decimals
    Assert::same(1.5, $row->N2, $at . 'NUMERIC(4,2)');
    Assert::same(12.25, $row->N9, $at . 'NUMERIC(9,2)');
    Assert::same(123456.78, $row->N18, $at . 'NUMERIC(18,2)');
    Assert::same(1.125, $row->D3, $at . 'DECIMAL');

    // Floats, also the whole ones, which look like nothing but a number
    Assert::same(1.5, $row->F, $at . 'FLOAT');
    Assert::same(2.25, $row->DP, $at . 'DOUBLE');
    Assert::same(5.0, $row->DPI, $at . 'DOUBLE, whole');
    Assert::same(7.0, $row->FI, $at . 'FLOAT, whole');

    // Dates
    Assert::type(DateTime::class, $row->DT, $at . 'DATE');
    Assert::same('2024-02-03', $row->DT->format('Y-m-d'));
    Assert::type(DateTime::class, $row->TS, $at . 'TIMESTAMP');
    Assert::same('2024-02-03 04:05:06', $row->TS->format('Y-m-d H:i:s'));
    Assert::same('2024-02-03 04:05:06', $row->TSF->format('Y-m-d H:i:s'), $at . 'fractions of a second');
    Assert::same('04:05:06', $row->TM, $at . 'TIME stays a text, it has no date');

    Assert::true($row->BO, $at . 'BOOLEAN');

    // Text stays text, whatever it looks like. This is where it used to go wrong.
    Assert::same('5', $row->C1, $at . 'CHAR(1)');
    Assert::same('12', $row->C2, $at . 'CHAR(2)');
    Assert::same('0123', $row->C4, $at . 'CHAR(4) with a leading zero');
    Assert::same('20240203', $row->C8, $at . 'CHAR(8) with digits');
    Assert::same('1.50', $row->C4D, $at . 'CHAR(4) with a decimal point');
    Assert::same('y', $row->V1, $at . 'VARCHAR(1)');
    Assert::same('34', $row->V2, $at . 'VARCHAR(2)');
    Assert::same('0123', $row->V4, $at . 'VARCHAR(4)');
    Assert::same('20240203', $row->V8, $at . 'VARCHAR(8)');
    Assert::same('2024-02-03', $row->V20, $at . 'a date in a long VARCHAR');
    Assert::same('0123', $row->O4, $at . 'OCTETS(4)');
    Assert::same('20240203', $row->O8, $at . 'OCTETS(8)');
    Assert::same('0123', $row->W4, $at . 'WIN1250(4)');
    Assert::same('20240203', $row->W8, $at . 'WIN1250(8)');
    Assert::same('1.50', $row->W4D, $at . 'WIN1250(4) with a decimal point');
    Assert::same('0123', $row->N4, $at . 'NONE(4)');
    Assert::same('long text', $row->BT, $at . 'BLOB text');
    Assert::same('binary', $row->BB, $at . 'BLOB binary');
    Assert::same('20240203', $row->BTN, $at . 'BLOB with digits');

    Assert::null($row->NIL);
    Assert::null($row->NILS);
    Assert::null($row->NILF);
    Assert::null($row->NILD);

    // Under another name, the column isn't found, and it's judged by its form
    $aliased = $connection->fetch('SELECT F AS X, DP AS Y, C4 AS Z, C4D AS W, N9 AS V, TS AS U FROM VALS');
    Assert::same(1.5, $aliased->X, $at . 'FLOAT under another name');
    Assert::same(2.25, $aliased->Y, $at . 'DOUBLE under another name');
    Assert::same('0123', $aliased->Z, $at . 'a code keeps its zero');
    Assert::same(12.25, $aliased->V);
    Assert::type(DateTime::class, $aliased->U);

    // Expressions
    $expressions = $connection->fetch("SELECT COUNT(*) AS C, 1 + 1 AS P, 'lit' AS L, CAST(1.5 AS NUMERIC(9,2)) + 1 AS N, CURRENT_DATE AS D FROM VALS");
    Assert::same(1, $expressions->C);
    Assert::same(2, $expressions->P);
    Assert::same('lit', $expressions->L);
    Assert::same(2.5, $expressions->N);
    Assert::type(DateTime::class, $expressions->D);

    // Non-ASCII text survives in its character set
    $connection->getPdo()->exec('DELETE FROM VALS WHERE I = 9');
    $connection->query('INSERT INTO VALS (I, V20) VALUES (9, ?)', iconv('UTF-8', $charset === 'UTF8' ? 'UTF-8' : 'CP1250', 'Žluťoučký'));
    Assert::same(
        iconv('UTF-8', $charset === 'UTF8' ? 'UTF-8' : 'CP1250', 'Žluťoučký'),
        $connection->fetchField('SELECT V20 FROM VALS WHERE I = 9'),
        $at . 'non-ASCII',
    );
    $connection->getPdo()->exec('DELETE FROM VALS WHERE I = 9');

    $connection->disconnect();
}

// --- Numbers going in. PDO_Firebird would store an integer in NUMERIC(9,2) as a hundredth of it.

$connection = createConnection();
dropTables($connection, 'NUMS');
$connection->getPdo()->exec('CREATE TABLE NUMS (ID INTEGER NOT NULL PRIMARY KEY, N NUMERIC(9,2), N18 NUMERIC(18,4), D DECIMAL(9,3), S SMALLINT, L BIGINT, DP DOUBLE PRECISION, F FLOAT, V VARCHAR(10), B BOOLEAN)');

$connection->query('INSERT INTO NUMS ?', ['ID' => 1, 'N' => 6, 'N18' => 7, 'D' => 8, 'S' => 9, 'L' => 3000000000, 'DP' => 10, 'F' => 11, 'V' => 12, 'B' => true]);
$row = $connection->fetch('SELECT * FROM NUMS WHERE ID = ?', 1);
Assert::same(6.0, $row->N, 'an integer to NUMERIC');
Assert::same(7.0, $row->N18, 'an integer to NUMERIC(18,4)');
Assert::same(8.0, $row->D, 'an integer to DECIMAL');
Assert::same(9, $row->S);
Assert::same(3000000000, $row->L, 'a large integer');
Assert::same(10.0, $row->DP);
Assert::same(11.0, $row->F);
Assert::same('12', $row->V);
Assert::true($row->B);

$connection->query('INSERT INTO NUMS ?', ['ID' => 2, 'N' => 6.5, 'N18' => 7.25, 'D' => -8.125, 'DP' => 0.1, 'B' => false]);
$row = $connection->fetch('SELECT * FROM NUMS WHERE ID = 2');
Assert::same(6.5, $row->N, 'a float');
Assert::same(7.25, $row->N18);
Assert::same(-8.125, $row->D);
Assert::same(0.1, $row->DP);
Assert::false($row->B);

$connection->query('UPDATE NUMS SET N = ? WHERE ID = ?', 100, 2);
Assert::same(100.0, $connection->fetchField('SELECT N FROM NUMS WHERE ID = 2'), 'an update');

Assert::same(1, $connection->fetchField('SELECT ID FROM NUMS WHERE N < ?', 7), 'a comparison');
Assert::same(1, $connection->fetchField('SELECT COUNT(*) FROM NUMS WHERE N = ?', 6));
Assert::same(2, $connection->fetchField('SELECT COUNT(*) FROM NUMS WHERE N IN (?, ?)', 6, 100));
Assert::same(1, $connection->fetchField('SELECT COUNT(*) FROM NUMS WHERE ID = ?', 1), 'an integer to an integer');
Assert::same(1, $connection->fetchField('SELECT COUNT(*) FROM NUMS WHERE L = ?', 3000000000));
Assert::same(1, $connection->fetchField('SELECT COUNT(*) FROM NUMS WHERE B = ?', true));

// Through PDO as well, for code using it directly
$statement = $connection->getPdo()->prepare('INSERT INTO NUMS (ID, N) VALUES (?, ?)');
$statement->bindValue(1, 3, PDO::PARAM_INT);
$statement->bindValue(2, 6, PDO::PARAM_INT);
$statement->execute();
$id = 4;
$number = 6;
$statement = $connection->getPdo()->prepare('INSERT INTO NUMS (ID, N) VALUES (?, ?)');
$statement->bindParam(1, $id, PDO::PARAM_INT);
$statement->bindParam(2, $number, PDO::PARAM_INT);
$statement->execute();
$connection->getPdo()->prepare('INSERT INTO NUMS (ID, N) VALUES (?, ?)')->execute([5, 6]);
foreach ([3, 4, 5] as $id) {
    Assert::same(6.0, $connection->fetchField('SELECT N FROM NUMS WHERE ID = ?', $id), "PDO, row {$id}");
}

dropTables($connection, 'NUMS');
dropTables($connection, 'VALS');
