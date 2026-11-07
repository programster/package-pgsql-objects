<?php

/*
 * Creates a table with a dot in its name, in a schema with a dot in its name, to test that names that would be
 * ambiguous as a plain "schema.table" string are still handled correctly.
 */

class CreateDottedNameTable implements \Programster\PgsqlMigrations\MigrationInterface
{
    public function up(\PgSql\Connection $dbConn) : void
    {
        $queries = [
            'CREATE SCHEMA "odd.schema"',
            'CREATE TABLE "odd.schema"."odd.table"' .
                ' ("id" uuid NOT NULL, "reading" double precision NOT NULL, PRIMARY KEY (id))',
        ];

        foreach ($queries as $query)
        {
            $result = pg_query($dbConn, $query);

            if ($result === FALSE)
            {
                throw new \Exception("Failed to create the dotted name table, running: {$query}");
            }
        }
    }


    public function down(\PgSql\Connection $dbConn) : void
    {
        pg_query($dbConn, 'DROP SCHEMA "odd.schema" CASCADE');
    }
}
