<?php

use Programster\PgsqlLib\PgSqlConnection;

class InvoiceTable extends Programster\PgsqlObjects\AbstractUuidTable
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


    public function getVirtualFields() : array
    {
        return array('total_pence', 'total_with_vat_pence', 'sequence_number');
    }


    public function getObjectClassName() : string
    {
        return 'InvoiceRecord';
    }


    public function getTableName() : string
    {
        return 'invoice';
    }
}
