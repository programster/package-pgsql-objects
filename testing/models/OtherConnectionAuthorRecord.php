<?php

use Programster\PgsqlObjects\TableInterface;

/**
 * An author whose table uses a second connection to the test database (see OtherConnectionAuthorTable).
 */
class OtherConnectionAuthorRecord extends AuthorRecord
{
    public function getTableHandler() : TableInterface
    {
        return OtherConnectionAuthorTable::getInstance();
    }
}
