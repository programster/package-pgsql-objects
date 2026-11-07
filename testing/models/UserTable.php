<?php

use Programster\PgsqlLib\PgSqlConnection;

/**
 * A table whose IDs are UUIDs generated in code.
 */
class UserTable extends Programster\PgsqlObjects\AbstractUuidTable
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
        return 'UserRecord';
    }


    public function getTableName() : string
    {
        return 'user';
    }
}
