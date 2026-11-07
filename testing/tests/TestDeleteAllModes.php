<?php

/*
 * Test both ways deleteAll() can empty a table: the default DELETE, which works on a table that another table's
 * foreign key references, and the opt-in TRUNCATE, which PostgreSQL refuses for such a table, but otherwise works.
 */

use Programster\PgsqlLib\Exceptions\ExceptionQueryError;

class TestDeleteAllModes
{
    public function __construct()
    {
        $db = ConnectionHandler::getDb();
        $db->query('DROP TABLE IF EXISTS "delete_all_reference"');
        SampleTable::getInstance()->deleteAll();
    }


    public function run() : void
    {
        $db = ConnectionHandler::getDb();

        try
        {
            $this->testTruncate();

            // reference the sample table from another table, without any rows actually referencing it.
            $createReference =
                'CREATE TABLE "delete_all_reference"' .
                ' ("sample_id" uuid REFERENCES "reporting"."sample" ("id"))';

            $db->query($createReference);
            $this->testDeleteWorksWhenReferenced();
            $this->testTruncateFailsWhenReferenced();
        }
        finally
        {
            $db->query('DROP TABLE IF EXISTS "delete_all_reference"');
        }
    }


    private function testTruncate() : void
    {
        $table = SampleTable::getInstance();
        $table->insert(['reading' => 1.5]);
        $table->deleteAll(true);
        $this->assertEmpty("deleteAll(true)");
    }


    private function testDeleteWorksWhenReferenced() : void
    {
        $table = SampleTable::getInstance();
        $created = $table->insert(['reading' => 2.5]);
        $table->deleteAll();
        $this->assertEmpty("deleteAll() on a table referenced by a foreign key");

        // the cache must have been emptied too, so load() goes to the database, which no longer has the row.
        $threw = false;

        try
        {
            $table->load($created->getId());
        }
        catch (\Programster\PgsqlObjects\Exceptions\ExceptionNoSuchIdException)
        {
            $threw = true;
        }

        if ($threw === false)
        {
            throw new \Exception("deleteAll() left a deleted object in the cache.");
        }
    }


    private function testTruncateFailsWhenReferenced() : void
    {
        $threw = false;

        try
        {
            SampleTable::getInstance()->deleteAll(true);
        }
        catch (ExceptionQueryError)
        {
            $threw = true;
        }

        if ($threw === false)
        {
            throw new \Exception("TRUNCATE unexpectedly worked on a table referenced by a foreign key.");
        }
    }


    private function assertEmpty(string $description) : void
    {
        $result = ConnectionHandler::getDb()->query('SELECT COUNT(*) AS "count" FROM "reporting"."sample"');

        if (pg_fetch_assoc($result)['count'] !== "0")
        {
            throw new \Exception("{$description} did not delete every row.");
        }
    }
}
