<?php

/*
 * Creates a table whose UUID identifier has a database default (gen_random_uuid(), which makes v4 UUIDs), so we can
 * test that IDs are still generated in code (v7 UUIDs) whenever the table can do so.
 */

class CreateEventTable implements \Programster\PgsqlMigrations\MigrationInterface
{
    public function up($dbConn) : void
    {
        $query =
            'CREATE TABLE "event" (
                "id" uuid NOT NULL DEFAULT gen_random_uuid(),
                "name" varchar(255) NOT NULL,
                PRIMARY KEY (id)
            );';

        $result = pg_query($dbConn, $query);

        if ($result === FALSE)
        {
            throw new \Exception("Failed to create the event table.");
        }
    }


    public function down($dbConn) : void
    {
        pg_query($dbConn, 'DROP TABLE "event"');
    }
}
