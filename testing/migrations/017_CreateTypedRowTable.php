<?php

/*
 * Creates a table with a range of column types, to test that a batch's values, which are sent as parameters and cast
 * to their column's type, are saved correctly.
 */

class CreateTypedRowTable implements \Programster\PgsqlMigrations\MigrationInterface
{
    public function up(\PgSql\Connection $dbConn) : void
    {
        $query =
            'CREATE TYPE "typed_row_status" AS ENUM (\'active\', \'closed\');' .
            'CREATE TABLE "typed_row" (' .
                '"id" uuid NOT NULL PRIMARY KEY,' .
                ' "name" varchar(5) NOT NULL,' .
                ' "amount" numeric(8,2),' .
                ' "flag" boolean,' .
                ' "tags" text[],' .
                ' "meta" jsonb,' .
                ' "status" "typed_row_status",' .
                ' "happened_at" timestamptz,' .
                ' "note" text' .
            ')';

        if (pg_query($dbConn, $query) === false)
        {
            throw new \Exception("Failed to create the typed_row table.");
        }
    }


    public function down(\PgSql\Connection $dbConn) : void
    {
        pg_query($dbConn, 'DROP TABLE "typed_row"; DROP TYPE "typed_row_status"');
    }
}
