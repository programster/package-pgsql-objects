<?php

/*
 * Creates an author table, whose IDs are UUIDs generated in code, and a book table, whose IDs the database generates
 * (SERIAL), with a foreign key to its author, to test Transaction's order of changes. The foreign key isn't
 * deferrable (PostgreSQL's default), so an author must be inserted before its books, and deleted after them.
 */

class CreateAuthorAndBookTables implements \Programster\PgsqlMigrations\MigrationInterface
{
    public function up(\PgSql\Connection $dbConn) : void
    {
        $queries = [
            'CREATE TABLE "author" ("id" uuid NOT NULL, "name" varchar(255) NOT NULL, PRIMARY KEY (id))',
            'CREATE TABLE "book" (' .
                '"id" SERIAL NOT NULL,' .
                ' "author_id" uuid NOT NULL REFERENCES "author" ("id"),' .
                ' "title" varchar(255) NOT NULL,' .
                ' PRIMARY KEY (id)' .
            ')',
        ];

        foreach ($queries as $query)
        {
            $result = pg_query($dbConn, $query);

            if ($result === FALSE)
            {
                throw new \Exception("Failed to create the author and book tables, running: {$query}");
            }
        }
    }


    public function down(\PgSql\Connection $dbConn) : void
    {
        pg_query($dbConn, 'DROP TABLE "book", "author"');
    }
}
