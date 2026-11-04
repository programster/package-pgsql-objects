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
        return array();
    }


    public function getFieldsThatHaveDefaults() : array
    {
        return array();
    }


    public function getFieldsToKeepAsString() : array
    {
        return array('exact_amount');
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
