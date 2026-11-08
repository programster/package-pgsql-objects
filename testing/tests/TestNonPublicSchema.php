<?php

/*
 * Test that a table outside the public schema is always used in its own schema, rather than through the search_path,
 * which would find the decoy table with the same name in the public schema. Both the queries and the column info used
 * to convert the loaded values (from pg_meta_data()) must come from the reporting schema's table.
 */

use Programster\PgsqlObjects\CacheMode;

class TestNonPublicSchema
{
    public function __construct()
    {
        $db = ConnectionHandler::getDb();
        $db->query('TRUNCATE "reporting"."sample", "public"."sample"');
        SampleTable::getInstance()->emptyCache();
    }


    public function run() : void
    {
        $db = ConnectionHandler::getDb();
        $table = SampleTable::getInstance();
        $db->query("INSERT INTO \"public\".\"sample\" (\"id\", \"reading\") VALUES (gen_random_uuid(), 'decoy')");
        $created = $table->insert(['reading' => 1.5]);

        // bypass the cache so the values come from the database.
        $loaded = $table->load($created->getId(), CacheMode::UPDATE_CACHE);

        // the decoy's reading column is text, so its column info would have left the value as the string "1.5".
        if ($loaded->getReading() !== 1.5)
        {
            $actual = var_export($loaded->getReading(), true);
            throw new \Exception("reading was not converted using the reporting table's column info, got {$actual}.");
        }

        // every row is loaded, which would include the decoy row if the wrong table was used.
        $matches = $table->loadAll(CacheMode::UPDATE_CACHE);

        if (count($matches) !== 1 || $matches[0]->getId() !== $created->getId())
        {
            throw new \Exception("loadAll() did not load only the row from the reporting schema's table.");
        }

        $matches = $table->loadWhereAnd(['reading' => 1.5], CacheMode::USE_CACHE);

        if (count($matches) !== 1 || $matches[0]->getId() !== $created->getId())
        {
            throw new \Exception("loadWhereAnd() did not load only the row from the reporting schema's table.");
        }

        $table->deleteByIds([$created->getId()]);
        $remaining = $db->query('SELECT COUNT(*) AS "count" FROM "reporting"."sample"');

        if (pg_fetch_assoc($remaining)['count'] !== "0")
        {
            throw new \Exception("deleteByIds() did not delete the row from the reporting schema's table.");
        }
    }
}
