<?php

use Programster\PgsqlLib\PgSqlConnection;

/**
 * A UUID table whose database can also generate the ID. It inherits canCodeGenerateId() returning true from
 * AbstractUuidTable, so the ID should still be generated in code.
 */
class EventTable extends Programster\PgsqlObjects\AbstractUuidTable
{
    public function getDb() : PgSqlConnection
    {
        return ConnectionHandler::getDb();
    }


    public function getFieldsThatAllowNull() : array
    {
        return array();
    }


    public function getFieldsThatHaveDefaults() : array
    {
        return array();
    }


    public function getObjectClassName() : string
    {
        return 'EventRecord';
    }


    public function getTableName() : string
    {
        return 'event';
    }


    public function canDatabaseGenerateId() : bool
    {
        return true;
    }
}
