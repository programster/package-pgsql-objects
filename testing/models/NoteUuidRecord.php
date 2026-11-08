<?php

use Programster\PgsqlObjects\TableInterface;

class NoteUuidRecord extends AbstractNoteRecord
{
    public function getTableHandler() : TableInterface
    {
        return NoteUuidTable::getInstance();
    }
}
