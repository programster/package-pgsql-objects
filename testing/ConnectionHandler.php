<?php

/*
 *
 */

class ConnectionHandler
{
    /**
     * Get the connection to the mysql database and create it if it doesn't already exit.
     * @staticvar type $db
     * @return \mysqli
     */
    public static function getDb() : Programster\PgsqlObjects\PgSqlConnection
    {
        static $db = null;

        if ($db == null)
        {
            $db = Programster\PgsqlObjects\PgSqlConnection::create(DB_HOST, DB_NAME, DB_USER, DB_PASSWORD);
        }

        return $db;
    }
}

