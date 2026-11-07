<?php

use Programster\PgsqlLib\PgSqlConnection;

/**
 * A table with a dot in its name, in a schema with a dot in its name.
 */
class DottedNameTable extends Programster\PgsqlObjects\AbstractUuidTable
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
        return 'DottedNameRecord';
    }


    public function getTableName() : string
    {
        return 'odd.table';
    }


    public function getSchemaName() : string
    {
        return 'odd.schema';
    }
}
