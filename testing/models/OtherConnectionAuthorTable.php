<?php

use Programster\PgsqlLib\PgSqlConnection;

/**
 * The author table, through a second connection to the test database, to test that a Transaction rejects objects whose
 * tables use different connections.
 */
class OtherConnectionAuthorTable extends AuthorTable
{
    private static ?PgSqlConnection $s_db = null;


    public function getDb() : PgSqlConnection
    {
        if (self::$s_db === null)
        {
            self::$s_db = ConnectionHandler::createNewConnection();
        }

        return self::$s_db;
    }


    public function getObjectClassName() : string
    {
        return 'OtherConnectionAuthorRecord';
    }
}
