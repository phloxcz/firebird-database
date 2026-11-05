<?php
/**
 * This file is part of phloxcz/firebird-database.
 *
 * (c) Phlox <https://github.com/phloxcz>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Phlox\FirebirdDatabase;

use Nette;
use Nette\Database\Connection;
use Nette\Database\ResultSet;

/**
 * Firebird driver for Nette Database, on top of PDO_Firebird.
 *
 * PDO_Firebird doesn't tell the type of a column in a result, only its length in
 * bytes and its precision. Those are enough for most of them:
 *
 *   NUMERIC, DECIMAL (precision > 0)            float
 *   TIMESTAMP (8 B), DATE (4 B)                 Nette\Utils\DateTime, no text that long fits there
 *   SMALLINT, INTEGER, BIGINT, BOOLEAN          come typed from PDO
 *
 * FLOAT and DOUBLE PRECISION are the exception. They're 4 and 8 bytes long, just as
 * CHAR(4), CHAR(8) and BLOB are in a single-byte character set, so '0123' could be
 * either, and PDO doesn't tell the table either. When a value looks like a number,
 * the column is looked up in the system tables by the tables its query names. A
 * column which is text in any of them stays text, so a value can't get damaged, and
 * only an expression, which has no column, is judged by its form.
 */
final class FirebirdDriver implements Nette\Database\Driver
{
    /** SQLCODE, which is what PDO_Firebird reports for a failed statement */
    private const ErrorUnique = -803;
    private const ErrorForeignKey = -530;
    private const ErrorNotNull = -625;
    private const ErrorConnection = -902;

    /** RDB$FIELDS.RDB$FIELD_TYPE */
    private const TypeSmallint = 7;
    private const TypeInteger = 8;
    private const TypeInt64 = 16;
    private const TypeInt128 = 26;
    private const TypeFloat = 10;
    private const TypeDouble = 27;

    private const NativeTypes = [
        7 => 'SMALLINT',
        8 => 'INTEGER',
        10 => 'FLOAT',
        12 => 'DATE',
        13 => 'TIME',
        14 => 'CHAR',
        16 => 'BIGINT',
        23 => 'BOOLEAN',
        24 => 'DECFLOAT',
        25 => 'DECFLOAT',
        26 => 'INT128',
        27 => 'DOUBLE PRECISION',
        28 => 'TIME WITH TIME ZONE',
        29 => 'TIMESTAMP WITH TIME ZONE',
        35 => 'TIMESTAMP',
        37 => 'VARCHAR',
        40 => 'CSTRING',
        261 => 'BLOB',
    ];

    private Connection $connection;

    /** @var \WeakMap<ResultSet, array<string, array{int, int}>> Length and precision of columns */
    private \WeakMap $resultMeta;

    /** @var array<string, array<string, int>> Table => column => type */
    private array $columnTypes = [];

    private ?bool $hasIdentity = null;

    public function initialize(Connection $connection, array $options): void
    {
        $this->connection = $connection;
        $this->resultMeta = new \WeakMap();
        $connection->getPdo()->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [FirebirdStatement::class]);
        $connection->setRowNormalizer($this->normalizeRow(...));
    }

    public function isSupported(string $feature): bool
    {
        return $feature === self::SupportMultiColumnAsOrCondition
            || $feature === self::SupportSubselect;
    }

    public function convertException(\PDOException $e): Nette\Database\DriverException
    {
        return match ($e->errorInfo[1] ?? null) {
            self::ErrorUnique => Nette\Database\UniqueConstraintViolationException::from($e),
            self::ErrorForeignKey => Nette\Database\ForeignKeyConstraintViolationException::from($e),
            self::ErrorNotNull => Nette\Database\NotNullConstraintViolationException::from($e),
            self::ErrorConnection => Nette\Database\ConnectionException::from($e),
            default => Nette\Database\DriverException::from($e),
        };
    }

    // SQL

    public function delimite(string $name): string
    {
        return '"' . str_replace('"', '""', $name) . '"';
    }

    public function formatDateTime(\DateTimeInterface $value): string
    {
        // A DATE column refuses a value with a time, even if it's midnight, and TIMESTAMP takes both
        return $value->format($value->format('H:i:s') === '00:00:00' ? "'Y-m-d'" : "'Y-m-d H:i:s'");
    }

    public function formatDateInterval(\DateInterval $value): string
    {
        throw new Nette\NotSupportedException('Firebird has no type for an interval.');
    }

    public function formatLike(string $value, int $pos): string
    {
        // Firebird has no escape character of its own in LIKE, and doubles quotes
        $escaped = str_replace("'", "''", addcslashes($value, '\\%_'));

        return ($pos <= 0 ? "'%" : "'") . $escaped . ($pos >= 0 ? "%'" : "'") . " ESCAPE '\\'";
    }

    public function applyLimit(string &$sql, ?int $limit, ?int $offset): void
    {
        if ($limit < 0 || $offset < 0) {
            throw new Nette\InvalidArgumentException('Negative offset or limit.');
        }

        if ($limit === null && !$offset) {
            return;
        }

        $sql = preg_replace(
            '#^\s*(SELECT|UPDATE|DELETE)#i',
            '$0' . ($limit !== null ? ' FIRST ' . $limit : '') . ($offset ? ' SKIP ' . $offset : ''),
            $sql,
            1,
            $count,
        ) ?? $sql;

        if ($count === 0) {
            throw new Nette\InvalidArgumentException('SQL query must begin with SELECT, UPDATE or DELETE command.');
        }
    }

    // Normalization of rows

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function normalizeRow(array $row, ResultSet $resultSet): array
    {
        $meta = $this->resultMeta[$resultSet] ??= $this->readResultMeta($resultSet);

        foreach ($row as $key => $value) {
            if (!is_string($value) || !isset($meta[$key])) {
                continue;
            }

            [$length, $precision] = $meta[$key];

            if ($precision > 0 && is_numeric($value)) {
                $row[$key] = (float) $value;
            } elseif ($length === 8 && preg_match('#^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d(\.\d+)?$#D', $value) === 1) {
                $row[$key] = new Nette\Utils\DateTime($value);
            } elseif ($length === 4 && preg_match('#^\d{4}-\d\d-\d\d$#D', $value) === 1) {
                $row[$key] = new Nette\Utils\DateTime($value);
            } elseif (
                ($length === 4 || $length === 8)
                && $precision === 0
                && preg_match('#^-?\d+(\.\d+)?(E[+-]?\d+)?$#Di', $value) === 1
                && $this->isFloatColumn($resultSet->getQueryString(), (string) $key, $value)
            ) {
                $row[$key] = (float) $value;
            }
        }

        return $row;
    }

    /**
     * @return array<string, array{int, int}>
     */
    private function readResultMeta(ResultSet $resultSet): array
    {
        $meta = [];
        $statement = $resultSet->getPdoStatement();

        if ($statement === null) {
            return [];
        }

        for ($column = 0, $count = $statement->columnCount(); $column < $count; $column++) {
            $info = $statement->getColumnMeta($column);
            if (is_array($info)) {
                $meta[$info['name']] = [$info['len'], $info['precision']];
            }
        }

        return $meta;
    }

    private function isFloatColumn(string $query, string $column, string $value): bool
    {
        $found = false;

        foreach (self::findTables($query) as $table) {
            $type = $this->readColumnTypes($table)[$column] ?? null;
            if ($type === null) {
                continue;
            }
            if ($type !== self::TypeFloat && $type !== self::TypeDouble) {
                return false;
            }
            $found = true;
        }

        // An expression, or a column under another name. A float PDO writes with a
        // decimal point or an exponent, so a code like '0123' stays what it is.
        return $found || strpbrk($value, '.eE') !== false;
    }

    /**
     * @return list<string> Tables named by a query, as the system tables have them
     */
    private static function findTables(string $query): array
    {
        preg_match_all('/\b(?:FROM|JOIN)\s+(?:"((?:[^"]|"")+)"|([A-Za-z_][\w$]*))/i', $query, $matches, PREG_SET_ORDER);

        $tables = [];
        foreach ($matches as $match) {
            $tables[] = ($match[2] ?? '') !== '' ? strtoupper($match[2]) : str_replace('""', '"', $match[1] ?? '');
        }

        return array_values(array_unique($tables));
    }

    /**
     * @return array<string, int> Column => type
     */
    private function readColumnTypes(string $table): array
    {
        if (isset($this->columnTypes[$table])) {
            return $this->columnTypes[$table];
        }

        // Set up front, because this query's own rows come through normalizeRow() too
        $this->columnTypes[$table] = [];

        $types = [];
        foreach ($this->connection->getPdo()->query(
            'SELECT rf.RDB$FIELD_NAME, f.RDB$FIELD_TYPE
            FROM RDB$RELATION_FIELDS rf
            JOIN RDB$FIELDS f ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
            WHERE rf.RDB$RELATION_NAME = ' . $this->connection->getPdo()->quote($table),
            \PDO::FETCH_NUM,
        ) ?: [] as $row) {
            $types[trim((string) $row[0])] = (int) $row[1];
        }

        return $this->columnTypes[$table] = $types;
    }

    // Reflection

    public function getTables(): array
    {
        $tables = [];

        foreach ($this->connection->query(
            'SELECT RDB$RELATION_NAME AS NAME, RDB$VIEW_BLR AS VIEW_BLR, RDB$DESCRIPTION AS DESCR
            FROM RDB$RELATIONS
            WHERE COALESCE(RDB$SYSTEM_FLAG, 0) = 0
            ORDER BY RDB$RELATION_NAME',
        ) as $row) {
            $tables[] = [
                'name' => trim((string) $row['NAME']),
                'view' => $row['VIEW_BLR'] !== null,
                'comment' => self::toComment($row['DESCR']),
            ];
        }

        return $tables;
    }

    public function getColumns(string $table): array
    {
        $identity = $this->hasIdentityColumns() ? 'rf.RDB$IDENTITY_TYPE' : 'NULL';
        $columns = [];

        $rows = $this->connection->query(
            "SELECT
                rf.RDB\$FIELD_NAME AS FIELD_NAME,
                f.RDB\$FIELD_TYPE AS FIELD_TYPE,
                f.RDB\$FIELD_SUB_TYPE AS FIELD_SUB_TYPE,
                f.RDB\$FIELD_SCALE AS FIELD_SCALE,
                f.RDB\$FIELD_LENGTH AS FIELD_LENGTH,
                f.RDB\$CHARACTER_LENGTH AS CHAR_LENGTH_,
                COALESCE(rf.RDB\$DEFAULT_SOURCE, f.RDB\$DEFAULT_SOURCE) AS DEFAULT_SOURCE,
                COALESCE(rf.RDB\$NULL_FLAG, f.RDB\$NULL_FLAG) AS NULL_FLAG,
                rf.RDB\$DESCRIPTION AS DESCR,
                {$identity} AS IDENTITY_TYPE,
                (SELECT COUNT(*)
                    FROM RDB\$RELATION_CONSTRAINTS rc
                    JOIN RDB\$INDEX_SEGMENTS ins ON rc.RDB\$INDEX_NAME = ins.RDB\$INDEX_NAME
                    WHERE rc.RDB\$CONSTRAINT_TYPE = 'PRIMARY KEY'
                        AND rc.RDB\$RELATION_NAME = rf.RDB\$RELATION_NAME
                        AND ins.RDB\$FIELD_NAME = rf.RDB\$FIELD_NAME
                ) AS IS_PRIMARY
            FROM RDB\$RELATION_FIELDS rf
            JOIN RDB\$FIELDS f ON f.RDB\$FIELD_NAME = rf.RDB\$FIELD_SOURCE
            WHERE rf.RDB\$RELATION_NAME = ?
            ORDER BY rf.RDB\$FIELD_POSITION",
            $table,
        );

        foreach ($rows as $row) {
            $columns[] = [
                'name' => trim((string) $row['FIELD_NAME']),
                'table' => $table,
                'nativetype' => self::resolveNativeType((int) $row['FIELD_TYPE'], (int) $row['FIELD_SUB_TYPE'], (int) $row['FIELD_SCALE']),
                'size' => (int) ($row['CHAR_LENGTH_'] ?? $row['FIELD_LENGTH']),
                'nullable' => empty($row['NULL_FLAG']),
                'default' => self::parseDefault($row['DEFAULT_SOURCE']),
                // Nette would ask PDO for the last ID after every insert, and PDO_Firebird
                // has no way to tell. An identity column can be told from `vendor`.
                'autoincrement' => false,
                'primary' => (bool) $row['IS_PRIMARY'],
                'comment' => self::toComment($row['DESCR']),
                'vendor' => (array) $row,
            ];
        }

        return $columns;
    }

    public function getIndexes(string $table): array
    {
        $indexes = [];

        foreach ($this->connection->query(
            "SELECT
                ix.RDB\$INDEX_NAME AS INDEX_NAME,
                ix.RDB\$UNIQUE_FLAG AS UNIQUE_FLAG,
                ixs.RDB\$FIELD_POSITION AS FIELD_POSITION,
                ixs.RDB\$FIELD_NAME AS FIELD_NAME,
                CASE COALESCE(rc.RDB\$CONSTRAINT_TYPE, '') WHEN 'PRIMARY KEY' THEN 1 ELSE 0 END AS IS_PRIMARY
            FROM RDB\$INDICES ix
            JOIN RDB\$INDEX_SEGMENTS ixs ON ix.RDB\$INDEX_NAME = ixs.RDB\$INDEX_NAME
            LEFT JOIN RDB\$RELATION_CONSTRAINTS rc ON ix.RDB\$RELATION_NAME = rc.RDB\$RELATION_NAME AND ix.RDB\$INDEX_NAME = rc.RDB\$INDEX_NAME
            WHERE ix.RDB\$RELATION_NAME = ?
            ORDER BY ix.RDB\$INDEX_NAME, ixs.RDB\$FIELD_POSITION",
            $table,
        ) as $row) {
            $name = trim((string) $row['INDEX_NAME']);
            $indexes[$name]['name'] = $name;
            $indexes[$name]['unique'] = (bool) $row['UNIQUE_FLAG'];
            $indexes[$name]['primary'] = (bool) $row['IS_PRIMARY'];
            $indexes[$name]['columns'][] = trim((string) $row['FIELD_NAME']);
        }

        return array_values($indexes);
    }

    public function getForeignKeys(string $table): array
    {
        $keys = [];

        foreach ($this->connection->query(
            "SELECT
                rc.RDB\$CONSTRAINT_NAME AS CONSTRAINT_NAME,
                s.RDB\$FIELD_NAME AS FIELD_NAME,
                i2.RDB\$RELATION_NAME AS REFERENCES_TABLE,
                s2.RDB\$FIELD_NAME AS REFERENCES_FIELD
            FROM RDB\$RELATION_CONSTRAINTS rc
            JOIN RDB\$INDEX_SEGMENTS s ON s.RDB\$INDEX_NAME = rc.RDB\$INDEX_NAME
            JOIN RDB\$REF_CONSTRAINTS refc ON rc.RDB\$CONSTRAINT_NAME = refc.RDB\$CONSTRAINT_NAME
            JOIN RDB\$RELATION_CONSTRAINTS rc2 ON rc2.RDB\$CONSTRAINT_NAME = refc.RDB\$CONST_NAME_UQ
            JOIN RDB\$INDICES i2 ON i2.RDB\$INDEX_NAME = rc2.RDB\$INDEX_NAME
            JOIN RDB\$INDEX_SEGMENTS s2 ON s2.RDB\$INDEX_NAME = i2.RDB\$INDEX_NAME AND s2.RDB\$FIELD_POSITION = s.RDB\$FIELD_POSITION
            WHERE rc.RDB\$CONSTRAINT_TYPE = 'FOREIGN KEY' AND rc.RDB\$RELATION_NAME = ?
            ORDER BY rc.RDB\$CONSTRAINT_NAME, s.RDB\$FIELD_POSITION",
            $table,
        ) as $row) {
            $keys[] = [
                'name' => trim((string) $row['CONSTRAINT_NAME']),
                'local' => trim((string) $row['FIELD_NAME']),
                'table' => trim((string) $row['REFERENCES_TABLE']),
                'foreign' => trim((string) $row['REFERENCES_FIELD']),
            ];
        }

        return $keys;
    }

    public function getColumnTypes(\PDOStatement $statement): array
    {
        return []; // PDO_Firebird doesn't tell, see normalizeRow()
    }

    /**
     * Identity columns came with Firebird 3.
     */
    private function hasIdentityColumns(): bool
    {
        if ($this->hasIdentity === null) {
            try {
                $this->connection->getPdo()->query('SELECT FIRST 1 RDB$IDENTITY_TYPE FROM RDB$RELATION_FIELDS');
                $this->hasIdentity = true;
            } catch (\PDOException) {
                $this->hasIdentity = false;
            }
        }

        return $this->hasIdentity;
    }

    /**
     * The name a column was declared with, as other databases report it. Firebird
     * keeps NUMERIC and DECIMAL as integers with a scale.
     */
    private static function resolveNativeType(int $type, int $subType, int $scale): string
    {
        if (in_array($type, [self::TypeSmallint, self::TypeInteger, self::TypeInt64, self::TypeInt128, self::TypeDouble], true)) {
            if ($subType === 1 || ($scale < 0 && $subType !== 2)) {
                return 'NUMERIC';
            }
            if ($subType === 2) {
                return 'DECIMAL';
            }
        }

        return self::NativeTypes[$type] ?? 'UNKNOWN';
    }

    private static function parseDefault(mixed $source): mixed
    {
        if (!is_string($source)) {
            return null;
        }

        $value = trim((string) preg_replace('/^\s*DEFAULT\s+/i', '', $source));

        return preg_match("/^'(.*)'$/Ds", $value, $matches) === 1 ? str_replace("''", "'", $matches[1]) : $value;
    }

    private static function toComment(mixed $description): ?string
    {
        return is_string($description) && trim($description) !== '' ? trim($description) : null;
    }
}
