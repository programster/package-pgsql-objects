<?php

use Programster\PgsqlLib\PgSqlConnection;

/**
 * A table with a text column that can hold a large value.
 * @extends \Programster\PgsqlObjects\AbstractUuidTable<DocumentRecord>
 */
class DocumentTable extends Programster\PgsqlObjects\AbstractUuidTable
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
        return 'DocumentRecord';
    }


    public function getTableName() : string
    {
        return 'document';
    }
}
