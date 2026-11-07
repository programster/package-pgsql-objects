<?php

/*
 * Provides the tests with a single shared connection to the test database.
 */

use Programster\PgsqlLib\PgSqlConnection;

class ConnectionHandler
{
    private static ?PgSqlConnection $s_db = null;


    /**
     * Get the connection to the database and create it if it doesn't already exist.
     * @return PgSqlConnection
     */
    public static function getDb() : PgSqlConnection
    {
        if (self::$s_db === null)
        {
            $port = defined('DB_PORT') ? DB_PORT : 5432;
            self::$s_db = PgSqlConnection::create(DB_HOST, DB_NAME, DB_USER, DB_PASSWORD, true, $port);
        }

        return self::$s_db;
    }
}
