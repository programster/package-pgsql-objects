<?php

/*
 * Test that insert() sends its values as parameters, rather than escaping them into the query: a large value with
 * characters that would need escaping (quotes, backslashes and a "$1") is stored exactly as it was, and inserting it
 * doesn't make escaped copies of it, which would add about twice its size to the memory used.
 */

use Programster\PgsqlObjects\CacheMode;

class TestInsertLargeValue
{
    public function __construct()
    {
        $db = ConnectionHandler::getDb();
        $db->query("TRUNCATE {$db->escapeIdentifier("document")}");
        DocumentTable::getInstance()->emptyCache();
    }


    public function run() : void
    {
        $chunk = "It's a \\ backslash, a \"quote\" and \$1. ";
        $value = str_repeat($chunk, intdiv(8 * 1024 * 1024, strlen($chunk)));
        $table = DocumentTable::getInstance();

        memory_reset_peak_usage();
        $before = memory_get_usage();
        $created = $table->insert(['body' => $value]);
        $used = memory_get_peak_usage() - $before;
        $ratio = round($used / strlen($value), 2);

        // Measured at about 1 (the row the database returns), and at about 3 when the value was escaped into the
        // query (the escaped literal, and the query holding it, as well as the returned row).
        if ($ratio > 2)
        {
            throw new \Exception("Inserting the value used {$ratio} times its size in memory, so it was copied.");
        }

        $loaded = $table->load(RecordIds::get($created), CacheMode::UPDATE_CACHE);

        if ($loaded->getBody() !== $value)
        {
            throw new \Exception("The inserted value wasn't stored exactly as it was.");
        }
    }
}
