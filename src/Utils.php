<?php

/*
 * A library to help with using UUIDs.
 */

declare(strict_types = 1);

namespace Programster\PgsqlObjects;


class Utils
{


    /**
     * Generates a v4 UUID that is in sequential form for database performance.
     * @return string - the generated UUID string.
     */
    public static function generateUuid() : string
    {
        static $factory = null;

        if ($factory == null)
        {
            $factory = new \Ramsey\Uuid\UuidFactory();

            $generator = new \Ramsey\Uuid\Generator\CombGenerator(
                $factory->getRandomGenerator(),
                $factory->getNumberConverter()
            );

            $codec = new \Ramsey\Uuid\Codec\TimestampFirstCombCodec($factory->getUuidBuilder());

            $factory->setRandomGenerator($generator);
            $factory->setCodec($codec);
        }

        \Ramsey\Uuid\Uuid::setFactory($factory);
        $uuidString = \Ramsey\Uuid\Uuid::uuid4()->toString();
        return $uuidString;
    }


    /**
     * Generates the SET part of a mysql query with the provided name/value
     * pairs provided
     * @param pairs - assoc array of name/value pairs to go in mysql
     * @param bool $escapeValues - (optional) set to false to disable escaping of values if you have already taken
     * care of this. Please note that this will always escape the identifiers (column names).
     * @return query - the generated query string that can be appended.
     */
    public static function generateQueryPairs(
        array $pairs,
        PgSqlConnection $connection,
        bool $escapeValues = true
    ) : string
    {
        $query = '';

        foreach ($pairs as $name => $value)
        {
            $escapedIdentifier = pg_escape_identifier($connection->getResource(), $name);
            $valueToInsert = ($escapeValues) ? Utils::escapeValue($connection, $value) : $value;

            if ($value === null)
            {
                $query .= "{$escapedIdentifier} = NULL, ";
            }
            else
            {
                $query .= "{$escapedIdentifier} = {$valueToInsert}, ";
            }
        }

        $query = substr($query, 0, -2); # remove the last comma.
        return $query;
    }


    public static function escapeIdentifier(PgSqlConnection $conn, string $nameOfTableOrColumn)
    {
        return pg_escape_identifier($conn->getResource(), $nameOfTableOrColumn);
    }


    /**
     * Escape the array of string identifiers provided.
     * @param PgSqlConnection $conn - the connection to escape for
     * @param array $namesOfTablesOrColumns - the list of table/column names to escape.
     * @return array - the escaped identifiers.
     */
    public static function escapeIdentifiers(PgSqlConnection $conn, array $namesOfTablesOrColumns) : array
    {
        $escapedIdentifiers = [];

        foreach ($namesOfTablesOrColumns as $identifier)
        {
            $escapedIdentifiers[] = pg_escape_identifier($conn->getResource(), $identifier);
        }

        return $escapedIdentifiers;
    }


    /**
     * Escape the provided values, using the provided connection.
     * @param PgSqlConnection $conn - the postgresql connection that we are using to escape the values for
     * @param array $inputs - the input values to escape.
     * @return array - the escaped values.
     */
    public static function escapeValues(PgSqlConnection $conn, array $inputs) : array
    {
        $escapedValues = array();

        foreach ($inputs as $key => $value)
        {
            $escapedValues[$key] = utils::escapeValue($conn, $value);
        }

        return $escapedValues;
    }


    /**
     * Escape a single value,
     * @param PgSqlConnection $conn
     * @param type $value
     * @throws ExceptionUnexpectedValueType
     * @return mixed - the escaped value.
     */
    public static function escapeValue(PgSqlConnection $conn, $value) : mixed
    {
        if (is_string($value))
        {
            $escapedValue = pg_escape_literal($value);
        }
        elseif (is_numeric($value))
        {
            $escapedValue = $value;
        }
        elseif (is_bool($value))
        {
            $escapedValue = ($value) ? "TRUE" : "FALSE";
        }
        elseif ($value === null)
        {
            // dont need to do anything for a null value
            $escapedValue = null;
        }
        else
        {
            $msg =
                "Unexpected type when escaping values. Please consider reporting this  " .
                "issue if appropriate. In the meantime, try escaping values manually and set " .
                "escapeValues to false.";

            throw new Exceptions\ExceptionUnexpectedValueType($value, $msg);
        }

        return $escapedValue;
    }


    /**
     * Generate the "where" part of a query based on name/value pairs and the provided conjunction
     * @param array $wherePairs - column/value pairs for where clause. Value may or may not be an
     *                            array list of values for WHERE IN().
     * @param Conjunction $conjunction - the conjunction to use in the where clause.
     * @return string - the where clause of a query such as "WHERE `id`='3'"
     */
    public static function generateWhereClause(
        PgSqlConnection $conn,
        array $wherePairs,
        Conjunction $conjunction
    ) : string
    {
        $whereClause = "";
        $whereStrings = array();

        foreach ($wherePairs as $attribute => $searchValue)
        {
            $whereString = $conn->escapeIdentifier($attribute);

            if (is_array($searchValue))
            {
                if (count($searchValue) === 0)
                {
                    $whereString = " FALSE";
                }
                else
                {
                    $escapedSearchValues = Utils::escapeValues($conn, $searchValue);
                    $whereString .= " IN(" . implode(",", $escapedSearchValues)  . ")";
                }
            }
            else
            {
                $whereString .= " = " . Utils::escapeValue($conn, $searchValue);
            }

            $whereStrings[] = $whereString;
        }

        if (count($whereStrings) > 0)
        {
            $whereClause = "WHERE " . implode(" {$conjunction} ", $whereStrings);
        }

        return $whereClause;
    }


    /**
     * Helper function that generates the raw SQL string to send to the database in order to
     * load objects that have any/all (depending on $conjunction) of the specified attributes.
     * @param array $wherePairs - column-name/value pairs of attributes the objects must have to
     *                           be loaded.
     * @param Conjunction $conjunction - 'AND' or 'OR' which changes whether the object needs all or
     *                                   any of the specified attributes in order to be loaded.
     * @return string - the raw sql string to send to the database.
     * @throws \Exception - invalid $conjunction specified that was not 'OR' or 'AND'
     */
    public static function generateSelectWhereQuery(
        PgSqlConnection $conn,
        string $tableName,
        array $wherePairs,
        Conjunction $conjunction
    ) : string
    {
        $escapedTableName = $conn->escapeIdentifier($tableName);
        $query = "SELECT * FROM {$escapedTableName} " . Utils::generateWhereClause($conn, $wherePairs, $conjunction);
        return $query;
    }


    /**
     * Helper function that generates the raw SQL string to send to the database in order to
     * delete objects that have any/all (depending on $conjunction) of the specified attributes.
     * @param array $wherePairs - column-name/value pairs of attributes the objects must have to
     *                           be deleted.
     * @param Conjunction $conjunction - 'AND' or 'OR' which changes whether the object needs all or
     *                              any of the specified attributes in order to be loaded.
     * @return string - the raw sql string to send to the database.
     * @throws \Exception - invalid $conjunction specified that was not 'OR' or 'AND'
     */
    public static function generateDeleteWhereQuery(
        PgSqlConnection $connection,
        string $tableName,
        array $wherePairs,
        Conjunction $conjunction
    ) : string
    {
        $query =
            "DELETE FROM {$tableName} " .
            Utils::generateWhereClause($connection, $tableName, $wherePairs, $conjunction);

        return $query;
    }


    /**
     * Creates a batch insert query for inserting lots of data in one go.
     * @param PgSqlConnection $db
     * @param string $tableName - the name of the table to insert into.
     * @param array $rows - the rows of data to insert in name/value pairs. Every row must contain the same set of keys,
     * but those keys don't need to be in the same order.
     * @return string - the query to execute to batch insert the data.
     * @throws \Exception
     */
    public static function generateBatchInsertQuery(PgSqlConnection $db, string $tableName, array $rows) : string
    {
        $valueSets = array();
        $firstRow = true;
        $escapedColumnNames = array();

        if (count($rows) === 0)
        {
            throw new \Exception("No data to insert.");
        }

        foreach ($rows as $row)
        {
            ksort($row); // always sort by key so all rows are consisten on insert.

            if ($firstRow)
            {
                $columnNames = array_keys($row);
                $escapedColumnNames = Utils::escapeidentifiers($db, $columnNames);
                $firstRow = false;
            }

            $rowValues = array_values($row);
            $escapedRowValues = Utils::escapeValues($db, $rowValues);
            $rowValuesString = "";

            foreach ($escapedRowValues as $escapedValue)
            {
                if ($escapedValue === null)
                {
                    $rowValuesString .= "NULL, ";
                }
                else
                {
                    $rowValuesString .= $escapedValue . ", ";
                }
            }

            $valueSets[] = "(" . \Safe\substr($rowValuesString, 0, strlen($rowValuesString) - 2) . ")";
        }

        $query =
            "INSERT INTO {$tableName}"
            . " (" . implode(",", $escapedColumnNames) . ")"
            . " VALUES " . implode(",", $valueSets)
            . ";";

        return $query;
    }
}