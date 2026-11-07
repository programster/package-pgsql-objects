<?php

use Programster\PgsqlLib\PgSqlConnection;

class LedgerTable extends Programster\PgsqlObjects\AbstractUuidTable
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


    public function getFieldsToKeepAsString() : array
    {
        return ['exact_amount'];
    }


    public function getObjectClassName() : string
    {
        return 'LedgerRecord';
    }


    public function getTableName() : string
    {
        return 'ledger';
    }
}
