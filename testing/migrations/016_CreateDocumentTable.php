<?php

/*
 * Creates a document table, whose IDs are UUIDs generated in code, with a text column that can hold a large value, to
 * test that insert() sends its values as parameters (see TestInsertLargeValue).
 */

class CreateDocumentTable implements \Programster\PgsqlMigrations\MigrationInterface
{
    public function up(\PgSql\Connection $dbConn) : void
    {
        $query = 'CREATE TABLE "document" ("id" uuid NOT NULL, "body" text NOT NULL, PRIMARY KEY (id))';
        $result = pg_query($dbConn, $query);

        if ($result === FALSE)
        {
            throw new \Exception("Failed to create the document table.");
        }
    }


    public function down(\PgSql\Connection $dbConn) : void
    {
        pg_query($dbConn, 'DROP TABLE "document"');
    }
}
