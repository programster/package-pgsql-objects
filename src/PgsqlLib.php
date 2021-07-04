<?php

/*
 * A library to help with Pgsql
 */

declare(strict_types = 1);

namespace Programster\PgsqlObjects;

class PgsqlLib
{
    /**
     * Generates the SET part of a mysql query with the provided name/value
     * pairs provided
     * @param pairs - assoc array of name/value pairs to go in mysql
     * @param bool $wrapWithQuotes - (optional) set to false to disable quote
     *                               wrapping if you have already taken care of
     *                               this.
     * @return query - the generated query string that can be appended.
     */
    public static function generateQueryPairs(array $pairs, $connection)
    {
        $escapedPairs = PgsqlLib::escapeValues($pairs);
        $query = '';

        foreach ($escapedPairs as $name => $value)
        {
            $escapedName = pg_escape_identifier($name);

            if ($value === null)
            {
                $insertionValue = "NULL";
            }
            elseif (is_string($value))
            {
                $insertionValue = pg_escape_literal($value);
            }
            else
            {
                $insertionValue = $value;
            }

            $query .= "{$escapedName} = {$value}, ";
        }

        $query = substr($query, 0, -2); # remove the last comma.
        return $query;
    }


    /**
     * Escape an array of data for the database.
     * @param array $data - the data to be escaped, either as list or name/value pairs
     * @param \mysqli $mysqli - the mysqli connection we are going to use for escaping.
     * @return array - the escaped input array.
     */
    public static function escapeValues(array $data, $connection)
    {
        foreach ($data as $index => $value)
        {
            if ($value !== null)
            {
                $data[$index] = pg_escape_literal($connection, $value);
            }
        }

        return $data;
    }
}