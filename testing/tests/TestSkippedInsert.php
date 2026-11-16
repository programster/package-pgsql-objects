<?php

/*
 * Test that an insert the database skips, e.g. as a BEFORE INSERT trigger returns NULL, throws ExceptionRowNotInserted,
 * rather than failing later with a TypeError, from both the table's insert() and a record's save().
 */

use Programster\PgsqlObjects\Exceptions\ExceptionRowNotInserted;

class TestSkippedInsert
{
    public function __construct()
    {
        $db = ConnectionHandler::getDb();
        $db->query("TRUNCATE {$db->escapeIdentifier("tag")}");
        TagTable::getInstance()->emptyCache();
    }


    public function run() : void
    {
        $db = ConnectionHandler::getDb();

        $db->query(
            'CREATE FUNCTION "skip_tag_insert"() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RETURN NULL; END; $$;' .
            ' CREATE TRIGGER "skip_tag_insert" BEFORE INSERT ON "tag" FOR EACH ROW EXECUTE FUNCTION "skip_tag_insert"()'
        );

        try
        {
            $insert = function () : void { TagTable::getInstance()->insert(['id' => 'one', 'name' => 'one']); };
            $this->assertSkipped("The table's insert()", $insert);

            $save = function () : void { TagRecord::createFromArray(['id' => 'two', 'name' => 'two'])->save(); };
            $this->assertSkipped("A record's save()", $save);
        }
        finally
        {
            $db->query('DROP TRIGGER "skip_tag_insert" ON "tag"; DROP FUNCTION "skip_tag_insert"()');
        }
    }


    /**
     * Check that the call throws ExceptionRowNotInserted.
     */
    private function assertSkipped(string $description, \Closure $call) : void
    {
        $threw = false;

        try
        {
            $call();
        }
        catch (ExceptionRowNotInserted)
        {
            $threw = true;
        }

        if ($threw === false)
        {
            throw new \Exception("{$description} didn't throw ExceptionRowNotInserted for a row a trigger skipped.");
        }
    }
}
