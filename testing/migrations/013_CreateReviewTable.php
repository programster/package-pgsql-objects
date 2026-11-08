<?php

/*
 * Creates a review table, whose IDs are UUIDs generated in code, with a DEFERRABLE foreign key to its author, to test
 * that a Transaction defers the constraints that can be, so a review can be saved before its author, or its author
 * deleted before it, as long as the rows are right by the end. Unlike book's, this foreign key is DEFERRABLE.
 */

class CreateReviewTable implements \Programster\PgsqlMigrations\MigrationInterface
{
    public function up(\PgSql\Connection $dbConn) : void
    {
        $query =
            'CREATE TABLE "review" (' .
                '"id" uuid NOT NULL,' .
                ' "author_id" uuid NOT NULL REFERENCES "author" ("id") DEFERRABLE INITIALLY IMMEDIATE,' .
                ' "text" varchar(255) NOT NULL,' .
                ' PRIMARY KEY (id)' .
            ')';

        $result = pg_query($dbConn, $query);

        if ($result === FALSE)
        {
            throw new \Exception("Failed to create the review table.");
        }
    }


    public function down(\PgSql\Connection $dbConn) : void
    {
        pg_query($dbConn, 'DROP TABLE "review"');
    }
}
