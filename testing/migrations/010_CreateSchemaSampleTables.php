<?php

/*
 * Creates a sample table in a reporting schema, and a decoy table with the same name in the public schema, whose
 * reading column has a different type. This lets the tests check that a table outside the public schema is always
 * looked up in its own schema, rather than through the search_path (which would find the public one).
 */

class CreateSchemaSampleTables implements \Programster\PgsqlMigrations\MigrationInterface
{
    public function up(\PgSql\Connection $dbConn) : void
    {
        $queries = [
            'CREATE SCHEMA "reporting"',
            'CREATE TABLE "reporting"."sample"' .
                ' ("id" uuid NOT NULL, "reading" double precision NOT NULL, PRIMARY KEY (id))',
            'CREATE TABLE "public"."sample" ("id" uuid NOT NULL, "reading" text NOT NULL, PRIMARY KEY (id))',
        ];

        foreach ($queries as $query)
        {
            $result = pg_query($dbConn, $query);

            if ($result === FALSE)
            {
                throw new \Exception("Failed to create the schema sample tables, running: {$query}");
            }
        }
    }


    public function down(\PgSql\Connection $dbConn) : void
    {
        pg_query($dbConn, 'DROP TABLE "public"."sample"');
        pg_query($dbConn, 'DROP SCHEMA "reporting" CASCADE');
    }
}
