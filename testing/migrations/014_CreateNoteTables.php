<?php

/*
 * Creates two tables with the same columns, to test how new objects' columns that have defaults are inserted: one
 * whose IDs are UUIDs generated in code, and one whose IDs the database generates (SERIAL), as those are inserted
 * differently. status is NOT NULL with a default, and category allows null and has a default.
 */

class CreateNoteTables implements \Programster\PgsqlMigrations\MigrationInterface
{
    public function up(\PgSql\Connection $dbConn) : void
    {
        $columns =
            '"title" varchar(255) NOT NULL,' .
            ' "status" varchar(255) NOT NULL DEFAULT \'draft\',' .
            ' "category" varchar(255) DEFAULT \'general\'';

        $queries = [
            "CREATE TABLE \"note_uuid\" (\"id\" uuid PRIMARY KEY, {$columns})",
            "CREATE TABLE \"note_serial\" (\"id\" SERIAL PRIMARY KEY, {$columns})",
        ];

        foreach ($queries as $query)
        {
            $result = pg_query($dbConn, $query);

            if ($result === FALSE)
            {
                throw new \Exception("Failed to create the note tables, running: {$query}");
            }
        }
    }


    public function down(\PgSql\Connection $dbConn) : void
    {
        pg_query($dbConn, 'DROP TABLE "note_uuid", "note_serial"');
    }
}
