<?php

use Programster\PgsqlLib\PgSqlConnection;

/**
 * A table whose IDs are UUIDs generated in code, with a DEFERRABLE foreign key to AuthorTable.
 */
class ReviewTable extends Programster\PgsqlObjects\AbstractUuidTable
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
        return 'ReviewRecord';
    }


    public function getTableName() : string
    {
        return 'review';
    }
}
