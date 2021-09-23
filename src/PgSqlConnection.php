<?php

/*
 * A class to wrap around a postgresql connection, primarily because we can't type-hint the postgresql resource like
 * we can do with a \mysqli connection
 */

declare(strict_types = 1);

namespace Programster\PgsqlObjects;


class PgSqlConnection
{
    private $m_resource; # the underlying pgsql connection resource.


    public function __construct($pgsqlResource)
    {
        $status = pg_connection_status($pgsqlResource);

        if ($status !== PGSQL_CONNECTION_OK)
        {
            throw new Exceptions\ExceptionConnectionError("Resource provided is not connected to the PostgreSql database.");
        }

        $this->m_resource = $pgsqlResource;
    }


    /**
     * Create a new PostgreSQL database connection
     * @param string $host - the IP or FQDN of where the postgreSQL database is hosted.
     * @param string $dbName - the name of the database.
     * @param string $user - the user to connect with
     * @param string $password - the password for that user.
     * @param int $port - the port to connect on (defaults to postgresql default port)
     * @param bool $use_utf8 - whether to use UTF8 encoding (defaults to true)
     * @param bool $forceNew - whether to set connection type to PGSQL_CONNECT_FORCE_NEW, which if passed, then a new
     * connection is created, even if the connection_string is identical to an existing connection.
     * @param bool $useAsync - Whether to set PGSQL_CONNECT_ASYNC. If set then the connection is established
     * asynchronously. The state of the connection can then be checked via pg_connect_poll() or pg_connection_status().
     * @return PgSqlConnection - the connection to the PostgreSQL database
     * @throws ConnectionError - if there was an issue connecting to the database.
     */
    public static function create(
        string $host,
        string $dbName,
        string $user,
        string $password,
        int $port = 5432,
        bool $use_utf8 = true,
        bool $forceNew = false,
        bool $useAsync = false
    ) : PgSqlConnection
    {
        $connString =
            "host=" . $host
            . " dbname=" . $dbName
            . " user=" . $user
            . " password=" . $password
            . " port=" . $port;

        if ($use_utf8)
        {
            $connString .= " options='--client_encoding=UTF8'";
        }

        $connectionOptions = 0;

        if ($forceNew)
        {
            $connectionOptions = $connectionOptions | PGSQL_CONNECT_FORCE_NEW;
        }

        if ($useAsync)
        {
            $connectionOptions = $connectionOptions | PGSQL_CONNECT_ASYNC;
        }

        $connection = pg_connect($connString, $connectionOptions);

        if ($connection == false)
        {
            throw new Exceptions\ExceptionConnectionError("Failed to initialize database connection.");
        }

        return new PgSqlConnection($connection);
    }


    /**
     * An alias for Utils::generateQueryPairs
     * @param array $pairs
     * @param PgSqlConnection $conn
     * @param bool $escapeValues
     * @return string
     */
    public function generateQueryPairs(array $pairs, bool $escapeValues = true) : string
    {
        return Utils::generateQueryPairs($pairs, $this, $escapeValues);
    }


    /**
     * Executes a pg_query call on the underlying connection resource.
     * @param string $query - the query to execute.
     */
    public function query(string $query)
    {
        $result = \Safe\pg_query($this->getResource(), $query);
        return $result;
    }


    public function escapeIdentifier(string $nameOfTableOrColumn)
    {
        return Utils::escapeidentifier($this, $nameOfTableOrColumn);
    }


    public function escapeIdentifiers(array $identifiers)
    {
        return Utils::escapeidentifiers($this, $identifiers);
    }


    public function escapeValues(array $inputs) : array
    {
        return Utils::escapeValues($this, $inputs);
    }


    public function escapeValue($input)
    {
        return Utils::escapeValue($this, $input);
    }


    /**
     * Creates a batch insert query for inserting lots of data in one go.
     * @param string $tableName - the name of the table to insert into.
     * @param array $rows - the rows of data to insert in name/value pairs. Every row must contain the same set of keys,
     * but those keys don't need to be in the same order.
     * @return string - the query to execute to batch insert the data.
     */
    public function generateBatchInsertQuery(string $tableName, array $rows) : string
    {
        return Utils::generateBatchInsertQuery($this, $tableName, $rows);
    }


    # Accessors
    public function getResource() { return $this->m_resource; }
}

