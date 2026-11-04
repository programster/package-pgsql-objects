<?php

use Programster\PgsqlLib\PgSqlConnection;

class ProductTable extends Programster\PgsqlObjects\AbstractUuidTable
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
        return 'ProductRecord';
    }


    public function getTableName() : string
    {
        return 'product';
    }


    public function getIdColumnName() : string
    {
        return 'uuid';
    }
}
