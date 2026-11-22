<?php

use Programster\PgsqlLib\PgSqlConnection;

/**
 * A table with a range of column types, whose IDs are UUIDs generated in code.
 * @extends \Programster\PgsqlObjects\AbstractUuidTable<TypedRowRecord>
 */
class TypedRowTable extends Programster\PgsqlObjects\AbstractUuidTable
{
    public function getDb() : PgSqlConnection
    {
        return ConnectionHandler::getDb();
    }


    public function getFieldsThatAllowNull() : array
    {
        return ['amount', 'flag', 'tags', 'meta', 'status', 'happened_at', 'note', 'code'];
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
        return 'TypedRowRecord';
    }


    public function getTableName() : string
    {
        return 'typed_row';
    }
}
