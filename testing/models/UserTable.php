<?php


use Programster\PgsqlLib\PgSqlConnection;

class UserTable extends Programster\PgsqlObjects\AbstractTable
{
    public function getDb() : PgSqlConnection
    {
        return ConnectionHandler::getDb();
    }

    public function getFieldsThatAllowNull(): array
    {
        return array();
    }


    public function getFieldsThatHaveDefaults(): array
    {
        return array();
    }


    public function getObjectClassName() : string
    {
        return 'UserRecord';
    }

    public function getTableName() : string
    {
        return 'user';
    }

    public function validateInputs(array $data): array
    {
        return $data;
    }
}

