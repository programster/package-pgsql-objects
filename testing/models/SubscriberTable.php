<?php

use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlObjects\AbstractTable;
use Programster\PgsqlObjects\Exceptions\ExceptionIdGeneratedInDatabase;
use Programster\PgsqlObjects\Exceptions\ExceptionInvalidId;

/**
 * A table whose identifier is a natural key: the subscriber's email address, which must always be given, as neither the
 * code nor the database can make one up.
 */
class SubscriberTable extends AbstractTable
{
    public function getDb() : PgSqlConnection
    {
        return ConnectionHandler::getDb();
    }


    public function getIdColumnName() : string
    {
        return 'email';
    }


    /**
     * Only accept email addresses as IDs, and lower case them, so that Ada@Example.com finds the same row as
     * ada@example.com.
     *
     * @param int|string $id
     *
     * @return string
     *
     * @throws ExceptionInvalidId - if it isn't an email address.
     */
    public function normalizeId(int|string $id) : string
    {
        if (!is_string($id) || filter_var($id, FILTER_VALIDATE_EMAIL) === false)
        {
            throw new ExceptionInvalidId(var_export($id, true) . " isn't an email address.");
        }

        return strtolower($id);
    }


    public function generateId() : never
    {
        throw new ExceptionIdGeneratedInDatabase("A subscriber's ID is their email address, so it must be given.");
    }


    public function canCodeGenerateId() : bool
    {
        return false;
    }


    public function canDatabaseGenerateId() : bool
    {
        return false;
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
        return 'SubscriberRecord';
    }


    public function getTableName() : string
    {
        return 'subscriber';
    }
}
