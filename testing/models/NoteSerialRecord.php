<?php

use Programster\PgsqlObjects\TableInterface;

class NoteSerialRecord extends AbstractNoteRecord
{
    public function getTableHandler() : TableInterface
    {
        return NoteSerialTable::getInstance();
    }
}
