<?php

/*
 * Test that insert() refuses a value it can't send (e.g. an object), before anything is sent, with an error that names
 * the column, so the mistake is easy to find.
 */

use Programster\PgsqlLib\Exceptions\ExceptionUnexpectedValueType;

class TestInsertUnsupportedValue
{
    public function __construct()
    {
        $db = ConnectionHandler::getDb();
        $db->query("TRUNCATE {$db->escapeIdentifier("document")}");
        DocumentTable::getInstance()->emptyCache();
    }


    public function run() : void
    {
        $isRefused = false;

        try
        {
            DocumentTable::getInstance()->insert(['body' => new \stdClass()]);
        }
        catch (ExceptionUnexpectedValueType $e)
        {
            $isRefused = str_contains($e->getMessage(), 'body');
        }

        if ($isRefused === false)
        {
            throw new \Exception("An object wasn't refused with an error naming its column.");
        }

        $count = pg_fetch_result(ConnectionHandler::getDb()->query('SELECT count(*) FROM "document"'), 0, 0);

        if ($count !== '0')
        {
            throw new \Exception("A row was inserted, although its value was refused.");
        }
    }
}
