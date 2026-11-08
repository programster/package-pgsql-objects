<?php

use Programster\PgsqlLib\PgSqlConnection;

/**
 * A table whose IDs are UUIDs generated in code, which BookTable's rows have a foreign key to.
 */
class AuthorTable extends Programster\PgsqlObjects\AbstractUuidTable
{
    public function getDb() : PgSqlConnection
    {
        return ConnectionHandler::getDb();
    }


    public function getFieldsThatAllowNull() : array
    {
        return [];
    }


    public function getFieldsThatHaveDefaults() : array
    {
        return [];
    }


    public function getVirtualFields() : array
    {
        return [];
    }


    public function getObjectClassName() : string
    {
        return 'AuthorRecord';
    }


    public function getTableName() : string
    {
        return 'author';
    }
}
