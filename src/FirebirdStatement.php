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

/**
 * Works around PDO_Firebird storing an integer in a column with a scale without
 * scaling it: 6 bound as an integer to NUMERIC(9,2) becomes 0.06, silently, and
 * `price < 6` compares with 0.06. A string gets converted correctly for every
 * numeric column, so integers are bound as strings.
 */
final class FirebirdStatement extends \PDOStatement
{
    public function bindValue(string|int $param, mixed $value, int $type = \PDO::PARAM_STR): bool
    {
        if ($type === \PDO::PARAM_INT && is_int($value)) {
            return parent::bindValue($param, (string) $value, \PDO::PARAM_STR);
        }

        return parent::bindValue($param, $value, $type);
    }

    /**
     * @param mixed $var
     */
    public function bindParam(string|int $param, mixed &$var, int $type = \PDO::PARAM_STR, int $maxLength = 0, mixed $driverOptions = null): bool
    {
        if ($type === \PDO::PARAM_INT && is_int($var)) {
            $var = (string) $var;
            $type = \PDO::PARAM_STR;
        }

        return parent::bindParam($param, $var, $type, $maxLength, $driverOptions);
    }
}
