<?php

use Programster\PgsqlLib\PgSqlConnection;

/**
 * A table in the reporting schema, which has a decoy table with the same name in the public schema.
 */
class SampleTable extends Programster\PgsqlObjects\AbstractUuidTable
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
        return 'SampleRecord';
    }


    public function getTableName() : string
    {
        return 'sample';
    }


    public function getSchemaName() : string
    {
        return 'reporting';
    }
}
