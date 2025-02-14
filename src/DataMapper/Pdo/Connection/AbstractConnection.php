<?php

/**
 * This file is part of the Phalcon Framework.
 *
 * (c) Phalcon Team <team@phalcon.io>
 *
 * For the full copyright and license information, please view the LICENSE.txt
 * file that was distributed with this source code.
 *
 * Implementation of this file has been influenced by AtlasPHP
 *
 * @link    https://github.com/atlasphp/Atlas.Pdo
 * @license https://github.com/atlasphp/Atlas.Pdo/blob/1.x/LICENSE.md
 */

declare(strict_types=1);

namespace Phalcon\DataMapper\Pdo\Connection;

use BadMethodCallException;
use Generator;
use PDO;
use PDOStatement;
use Phalcon\DataMapper\Pdo\Exception\Exception;
use Phalcon\DataMapper\Pdo\Profiler\ProfilerInterface;

use function array_merge;
use function call_user_func_array;
use function current;
use function func_get_args;
use function get_class;
use function implode;
use function is_array;
use function is_bool;
use function is_int;
use function method_exists;

/**
 * Provides array quoting, profiling, a new `perform()` method, new `fetch*()`
 * methods
 */
abstract class AbstractConnection extends PDO implements ConnectionInterface
{
    /**
     * @var PDO|null
     */
    protected ?PDO $pdo = null;

    /**
     * @var ProfilerInterface
     */
    protected ProfilerInterface $profiler;

    /**
     * Proxies to PDO methods created for specific drivers; in particular,
     * `sqlite` and `pgsql`.
     *
     * @param string $method
     * @param array  $arguments
     *
     * @return mixed
     * @throws BadMethodCallException
     */
    public function __call(string $method, array $arguments)
    {
        $this->connect();

        if (true !== method_exists($this->pdo, $method)) {
            $className = get_class($this);
            $message   = "Class '" . $className
                . "' does not have a method '" . $method . "'";

            throw new BadMethodCallException($message);
        }

        return $this->pdo->{$method}(...$arguments);
    }

    /**
     * Begins a transaction. If the profiler is enabled, the operation will
     * be recorded.
     *
     * @return bool
     */
    public function beginTransaction(): bool
    {
        $this->connect();
        $this->profiler->start(__FUNCTION__);

        $result = $this->pdo->beginTransaction();

        $this->profiler->finish();

        return $result;
    }

    /**
     * Commits the existing transaction. If the profiler is enabled, the
     * operation will be recorded.
     *
     * @return bool
     */
    public function commit(): bool
    {
        $this->connect();
        $this->profiler->start(__FUNCTION__);

        $result = $this->pdo->commit();

        $this->profiler->finish();

        return $result;
    }

    /**
     * Connects to the database.
     */
    abstract public function connect(): void;

    /**
     * Disconnects from the database.
     */
    abstract public function disconnect(): void;

    /**
     * Gets the most recent error code.
     *
     * @return string|null
     */
    public function errorCode(): string|null
    {
        $this->connect();

        return $this->pdo->errorCode();
    }

    /**
     * Gets the most recent error info.
     *
     * @return array
     */
    public function errorInfo(): array
    {
        $this->connect();

        return $this->pdo->errorInfo();
    }

    /**
     * Executes an SQL statement and returns the number of affected rows. If
     * the profiler is enabled, the operation will be recorded.
     *
     * @param string $statement
     *
     * @return int
     */
    public function exec(string $statement): int
    {
        $this->connect();
        $this->profiler->start(__FUNCTION__);

        $affectedRows = $this->pdo->exec($statement);

        $this->profiler->finish($statement);

        return $affectedRows;
    }

    /**
     * Performs a statement and returns the number of affected rows.
     *
     * @param string $statement
     * @param array  $values
     *
     * @return int
     */
    public function fetchAffected(string $statement, array $values = []): int
    {
        $sth = $this->perform($statement, $values);

        return $sth->rowCount();
    }

    /**
     * Fetches a sequential array of rows from the database; the rows are
     * returned as associative arrays.
     *
     * @param string $statement
     * @param array  $values
     *
     * @return array
     */
    public function fetchAll(string $statement, array $values = []): array
    {
        return $this->fetchData(
            "fetchAll",
            [PDO::FETCH_ASSOC],
            $statement,
            $values
        );
    }

    /**
     * Fetches an associative array of rows from the database; the rows are
     * returned as associative arrays, and the array of rows is keyed on the
     * first column of each row.
     *
     * If multiple rows have the same first column value, the last row with
     * that value will overwrite earlier rows. This method is more resource
     * intensive and should be avoided if possible.
     *
     * @param string $statement
     * @param array  $values
     *
     * @return array
     */
    public function fetchAssoc(string $statement, array $values = []): array
    {
        $data = [];
        $sth  = $this->perform($statement, $values);

        $row = $sth->fetch(PDO::FETCH_ASSOC);
        while ($row) {
            $data[current($row)] = $row;

            $row = $sth->fetch(PDO::FETCH_ASSOC);
        }

        return $data;
    }

    /**
     * Fetches a column of rows as a sequential array (default first one).
     *
     * @param string $statement
     * @param array  $values
     * @param int    $column
     *
     * @return array
     */
    public function fetchColumn(
        string $statement,
        array $values = [],
        int $column = 0
    ): array {
        return $this->fetchData(
            "fetchAll",
            [PDO::FETCH_COLUMN, $column],
            $statement,
            $values
        );
    }

    /**
     * Fetches multiple from the database as an associative array. The first
     * column will be the index key. The default flags are
     * PDO::FETCH_ASSOC | PDO::FETCH_GROUP
     *
     * @param string $statement
     * @param array  $values
     * @param int    $flags
     *
     * @return array
     */
    public function fetchGroup(
        string $statement,
        array $values = [],
        int $flags = PDO::FETCH_ASSOC
    ): array {
        return $this->fetchData(
            "fetchAll",
            [PDO::FETCH_GROUP | $flags],
            $statement,
            $values
        );
    }

    /**
     * Fetches one row from the database as an object where the column values
     * are mapped to object properties.
     *
     * Since PDO injects property values before invoking the constructor, any
     * initializations for defaults that you potentially have in your object's
     * constructor, will override the values that have been injected by
     * `fetchObject`. The default object returned is `\stdClass`
     *
     * @param string $statement
     * @param array  $values
     * @param string $className
     * @param array  $arguments
     *
     * @return object
     */
    public function fetchObject(
        string $statement,
        array $values = [],
        string $className = "stdClass",
        array $arguments = []
    ): object {
        $sth = $this->perform($statement, $values);

        return $sth->fetchObject($className, $arguments);
    }

    /**
     * Fetches a sequential array of rows from the database; the rows are
     * returned as objects where the column values are mapped to object
     * properties.
     *
     * Since PDO injects property values before invoking the constructor, any
     * initializations for defaults that you potentially have in your object's
     * constructor, will override the values that have been injected by
     * `fetchObject`. The default object returned is `\stdClass`
     *
     * @param string $statement
     * @param array  $values
     * @param string $className
     * @param array  $arguments
     *
     * @return array
     */
    public function fetchObjects(
        string $statement,
        array $values = [],
        string $className = "stdClass",
        array $arguments = []
    ): array {
        $sth = $this->perform($statement, $values);

        return $sth->fetchAll(PDO::FETCH_CLASS, $className, $arguments);
    }

    /**
     * Fetches one row from the database as an associative array.
     *
     * @param string $statement
     * @param array  $values
     *
     * @return array
     */
    public function fetchOne(string $statement, array $values = []): array
    {
        return $this->fetchData(
            "fetch",
            [PDO::FETCH_ASSOC],
            $statement,
            $values
        );
    }

    /**
     * Fetches an associative array of rows as key-value pairs (first column is
     * the key, second column is the value).
     *
     * @param string $statement
     * @param array  $values
     *
     * @return array
     */
    public function fetchPairs(string $statement, array $values = []): array
    {
        return $this->fetchData(
            "fetchAll",
            [PDO::FETCH_KEY_PAIR],
            $statement,
            $values
        );
    }

    /**
     * Fetches an associative array of rows uniquely. The rows are returned as
     * associative arrays.
     *
     * @param string $statement
     * @param array  $values
     *
     * @return array
     */
    public function fetchUnique(string $statement, array $values = []): array
    {
        return $this->fetchData(
            "fetchAll",
            [PDO::FETCH_UNIQUE],
            $statement,
            $values
        );
    }

    /**
     * Fetches the very first value (i.e., first column of the first row).
     *
     * @param string $statement
     * @param array  $values
     *
     * @return mixed
     */
    public function fetchValue(string $statement, array $values = []): mixed
    {
        $sth = $this->perform($statement, $values);

        return $sth->fetchColumn();
    }

    /**
     * Return the inner PDO (if any)
     *
     * @return PDO
     */
    public function getAdapter(): PDO
    {
        $this->connect();

        return $this->pdo;
    }

    /**
     * Retrieve a database connection attribute
     *
     * @param int $attribute
     *
     * @return bool|int|string|array|null
     */
    public function getAttribute(int $attribute): bool|int|string|array|null
    {
        $this->connect();

        return $this->pdo->getAttribute($attribute);
    }

    /**
     * Return an array of available PDO drivers (empty array if none available)
     *
     * @return array
     */
    public static function getAvailableDrivers(): array
    {
        return PDO::getAvailableDrivers();
    }

    /**
     * Return the driver name
     *
     * @return string
     */
    public function getDriverName(): string
    {
        $this->connect();

        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /**
     * Returns the Profiler instance.
     *
     * @return ProfilerInterface
     */
    public function getProfiler(): ProfilerInterface
    {
        return $this->profiler;
    }

    /**
     * Gets the quote parameters based on the driver
     *
     * @param string $driver
     *
     * @return array
     */
    public function getQuoteNames(string $driver = ""): array
    {
        if (true === empty($driver)) {
            $driver = $this->getDriverName();
        }

        return match ($driver) {
            "mysql" => [
                "prefix"  => "`",
                "suffix"  => "`",
                "find"    => "`",
                "replace" => "``",
            ],
            "sqlsrv" => [
                "prefix"  => "[",
                "suffix"  => "]",
                "find"    => "]",
                "replace" => "][",
            ],
            default => [
                "prefix"  => "\"",
                "suffix"  => "\"",
                "find"    => "\"",
                "replace" => "\"\"",
            ],
        };
    }

    /**
     * Is a transaction currently active? If the profiler is enabled, the
     * operation will be recorded. If the profiler is enabled, the operation
     * will be recorded.
     *
     * @return bool
     */
    public function inTransaction(): bool
    {
        $this->connect();
        $this->profiler->start(__FUNCTION__);

        $result = $this->pdo->inTransaction();

        $this->profiler->finish();

        return $result;
    }

    /**
     * Is the PDO connection active?
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        return (bool) $this->pdo;
    }

    /**
     * Returns the last inserted autoincrement sequence value. If the profiler
     * is enabled, the operation will be recorded.
     *
     * @param string $name
     *
     * @return string
     */
    public function lastInsertId(string $name = null): string
    {
        $this->connect();
        $this->profiler->start(__FUNCTION__);

        $result = $this->pdo->lastInsertId($name);

        $this->profiler->finish();

        return $result;
    }

    /**
     * Performs a query with bound values and returns the resulting
     * PDOStatement; array values will be passed through `quote()` and their
     * respective placeholders will be replaced in the query string. If the
     * profiler is enabled, the operation will be recorded.
     *
     * @param string $statement
     * @param array  $values
     *
     * @return PDOStatement
     */
    public function perform(
        string $statement,
        array $values = []
    ): PDOStatement {
        $this->connect();

        $this->profiler->start(__FUNCTION__);

        $sth = $this->prepare($statement);
        foreach ($values as $name => $value) {
            $this->performBind($sth, $name, $value);
        }

        $sth->execute();

        $this->profiler->finish($statement, $values);

        return $sth;
    }

    /**
     * Prepares an SQL statement for execution.
     *
     * @param string $statement
     * @param array  $options
     *
     * @return PDOStatement
     */
    public function prepare(
        string $statement,
        array $options = []
    ): PDOStatement {
        $this->connect();

        $this->profiler->start(__FUNCTION__);

        $sth = $this->pdo->prepare($statement, $options);

        $this->profiler->finish($sth->queryString);

        if (false === $sth) {
            throw new Exception(
                "Cannot prepare statement"
            );
        }

        return $sth;
    }

    /**
     * Queries the database and returns a PDOStatement. If the profiler is
     * enabled, the operation will be recorded.
     *
     * @param string   $statement
     * @param int|null $mode
     * @param mixed    ...$arguments
     *
     * @return PDOStatement|false
     */
    public function query(
        string $statement,
        ?int $mode = null,
        ...$arguments
    ): PDOStatement|false {
        $this->connect();
        $this->profiler->start(__FUNCTION__);

        $sth = call_user_func_array(
            [
                $this->pdo,
                "query"
            ],
            func_get_args()
        );

        $this->profiler->finish($sth->queryString);

        return $sth;
    }

    /**
     * Quotes a value for use in an SQL statement. This differs from
     * `PDO::quote()` in that it will convert an array into a string of
     * comma-separated quoted values. The default type is `PDO::PARAM_STR`
     *
     * @param mixed $value
     * @param int   $type
     *
     * @return string The quoted value.
     */
    public function quote(mixed $value, int $type = PDO::PARAM_STR): string
    {
        $elements = [];

        $this->connect();

        $element = $value;
        $quotes  = $this->getQuoteNames();

        if (true !== is_array($element)) {
            $element = (string) $element;

            return $quotes["prefix"] . $element . $quotes["suffix"];
        }

        // quote array values, not keys, then combine with commas
        foreach ($value as $key => $element) {
            $element        = (string) $element;
            $elements[$key] = $quotes["prefix"] . $element . $quotes["suffix"];
        }

        return implode(", ", $elements);
    }

    /**
     * Rolls back the current transaction, and restores autocommit mode. If the
     * profiler is enabled, the operation will be recorded.
     *
     * @return bool
     */
    public function rollBack(): bool
    {
        $this->connect();
        $this->profiler->start(__FUNCTION__);

        $result = $this->pdo->rollBack();

        $this->profiler->finish();

        return $result;
    }

    /**
     * Set a database connection attribute
     *
     * @param int   $attribute
     * @param mixed $value
     *
     * @return bool
     */
    public function setAttribute(int $attribute, mixed $value): bool
    {
        $this->connect();

        return $this->pdo->setAttribute($attribute, $value);
    }

    /**
     * Sets the Profiler instance.
     *
     * @param ProfilerInterface $profiler
     */
    public function setProfiler(ProfilerInterface $profiler)
    {
        $this->profiler = $profiler;
    }


    /**
     * Yield results using fetchAll
     *
     * @param string $statement
     * @param array  $values
     *
     * @return Generator
     */
    public function yieldAll(string $statement, array $values = []): Generator
    {
        $sth = $this->perform($statement, $values);
        while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
            yield $row;
        }
    }

    /**
     * Yield results using fetchAssoc
     *
     * @param string $statement
     * @param array  $values
     *
     * @return Generator
     */
    public function yieldAssoc(
        string $statement,
        array $values = []
    ): Generator {
        $sth = $this->perform($statement, $values);
        while ($row = $sth->fetch(PDO::FETCH_ASSOC)) {
            $key = current($row);
            yield $key => $row;
        }
    }

    /**
     * Yield results using fetchColumns
     *
     * @param string $statement
     * @param array  $values
     *
     * @return Generator
     */
    public function yieldColumns(
        string $statement,
        array $values = []
    ): Generator {
        $sth = $this->perform($statement, $values);
        while ($row = $sth->fetch(PDO::FETCH_NUM)) {
            yield $row[0];
        }
    }

    /**
     * Yield objects where the column values are mapped to object properties.
     *
     * Warning: PDO "injects property-values BEFORE invoking the constructor -
     * in other words, if your class initializes property-values to defaults
     * in the constructor, you will be overwriting the values injected by
     * fetchObject() !"
     * <http://www.php.net/manual/en/pdostatement.fetchobject.php#111744>
     *
     * @param string $statement
     * @param array  $values
     * @param string $class
     * @param array  $arguments
     *
     * @return Generator
     */
    public function yieldObjects(
        string $statement,
        array $values = [],
        string $class = 'stdClass',
        array $arguments = []
    ): Generator {
        $sth = $this->perform($statement, $values);

        if (empty($arguments)) {
            while ($instance = $sth->fetchObject($class)) {
                yield $instance;
            }
        } else {
            while ($instance = $sth->fetchObject($class, $arguments)) {
                yield $instance;
            }
        }
    }

    /**
     * Yield key-value pairs (key => value)
     *
     * @param string $statement
     * @param array  $values
     *
     * @return Generator
     */
    public function yieldPairs(
        string $statement,
        array $values = []
    ): Generator {
        $sth = $this->perform($statement, $values);
        while ($row = $sth->fetch(PDO::FETCH_NUM)) {
            yield $row[0] => $row[1];
        }
    }

    /**
     * Yield results using `fetchAll` and `FETCH_UNIQUE`
     *
     * @param string $statement
     * @param array  $values
     *
     * @return Generator
     */
    public function yieldUnique(
        string $statement,
        array $values = []
    ): Generator {
        $sth = $this->perform($statement, $values);
        while ($row = $sth->fetch(PDO::FETCH_UNIQUE)) {
            yield $row;
        }
    }

    /**
     * Bind a value using the proper PDO::PARAM_* type.
     *
     * @param PDOStatement $statement
     * @param mixed        $name
     * @param mixed        $arguments
     */
    protected function performBind(
        PDOStatement $statement,
        mixed $name,
        mixed $arguments
    ): void {
        $key = $name;
        if (is_int($key)) {
            $key = $key + 1;
        }

        $parameters = [$key, $arguments];
        if (is_array($arguments)) {
            $type = $arguments[1] ?? PDO::PARAM_STR;

            if (PDO::PARAM_BOOL === $type && is_bool($arguments[0])) {
                $arguments[0] = $arguments[0] ? "1" : "0";
            }

            $parameters = array_merge([$key], $arguments);
        }

        call_user_func_array(
            [
                $statement,
                "bindValue"
            ],
            $parameters
        );
    }

    /**
     * Helper method to get data from PDO based on the method passed
     *
     * @param string $method
     * @param array  $arguments
     * @param string $statement
     * @param array  $values
     *
     * @return array
     */
    protected function fetchData(
        string $method,
        array $arguments,
        string $statement,
        array $values = []
    ): array {
        $sth    = $this->perform($statement, $values);
        $result = call_user_func_array(
            [
                $sth,
                $method
            ],
            $arguments
        );

        /**
         * If this returns boolean or anything other than an array, return
         * an empty array back
         */
        if (true !== is_array($result)) {
            $result = [];
        }

        return $result;
    }
}
