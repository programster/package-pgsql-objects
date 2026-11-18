<?php

/*
 * Test that updateById() sends its values as parameters, rather than escaping them into the query: a large value with
 * characters that would need escaping (quotes, backslashes and a "$1") is stored exactly as it was, updating to it
 * doesn't make escaped copies of it, and a value that can't be sent is refused, naming its column, without changing
 * the row.
 */

use Programster\PgsqlLib\Exceptions\ExceptionUnexpectedValueType;
use Programster\PgsqlObjects\CacheMode;

class TestUpdateLargeValue
{
    public function __construct()
    {
        $db = ConnectionHandler::getDb();
        $db->query("TRUNCATE {$db->escapeIdentifier("document")}");
        DocumentTable::getInstance()->emptyCache();
    }


    public function run() : void
    {
        $table = DocumentTable::getInstance();
        $id = RecordIds::get($table->insert(['body' => 'short']));
        $chunk = "It's a \\ backslash, a \"quote\" and \$1. ";
        $value = str_repeat($chunk, intdiv(8 * 1024 * 1024, strlen($chunk)));

        memory_reset_peak_usage();
        $before = memory_get_usage();
        $table->updateById($id, ['body' => $value]);
        $ratio = round((memory_get_peak_usage() - $before) / strlen($value), 2);

        // About 1 (the row the database returns), and about 3 when the value was escaped into the query.
        if ($ratio > 2)
        {
            throw new \Exception("Updating to the value used {$ratio} times its size in memory, so it was copied.");
        }

        if ($table->load($id, CacheMode::UPDATE_CACHE)->getBody() !== $value)
        {
            throw new \Exception("The updated value wasn't stored exactly as it was.");
        }

        $isRefused = false;

        try
        {
            $table->updateById($id, ['body' => new \stdClass()]);
        }
        catch (ExceptionUnexpectedValueType $e)
        {
            $isRefused = str_contains($e->getMessage(), 'body');
        }

        if ($isRefused === false)
        {
            throw new \Exception("An object wasn't refused with an error naming its column.");
        }

        if ($table->load($id, CacheMode::UPDATE_CACHE)->getBody() !== $value)
        {
            throw new \Exception("The row was changed, although its value was refused.");
        }
    }
}
