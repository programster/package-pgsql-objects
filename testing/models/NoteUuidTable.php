<?php

use Programster\PgsqlLib\PgSqlConnection;

/**
 * The note table whose IDs are UUIDs generated in code (see AbstractNoteRecord).
 */
class NoteUuidTable extends Programster\PgsqlObjects\AbstractUuidTable
{
    public function getDb() : PgSqlConnection
    {
        return ConnectionHandler::getDb();
    }


    public function getFieldsThatAllowNull() : array
    {
        return ['category'];
    }


    public function getFieldsThatHaveDefaults() : array
    {
        return ['status', 'category'];
    }


    public function getVirtualFields() : array
    {
        return [];
    }


    public function getObjectClassName() : string
    {
        return 'NoteUuidRecord';
    }


    public function getTableName() : string
    {
        return 'note_uuid';
    }
}
