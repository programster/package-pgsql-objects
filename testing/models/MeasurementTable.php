<?php

use Programster\PgsqlLib\PgSqlConnection;

class MeasurementTable extends Programster\PgsqlObjects\AbstractUuidTable
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
        return 'MeasurementRecord';
    }


    public function getTableName() : string
    {
        return 'measurement';
    }
}
