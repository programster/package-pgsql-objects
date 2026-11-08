<?php

/*
 * Creates a table whose identifier is a natural key, the subscriber's email address, rather than a UUID or a number,
 * with a table that references it, to test natural keys, including changing one.
 */

class CreateSubscriberTable implements \Programster\PgsqlMigrations\MigrationInterface
{
    public function up(\PgSql\Connection $dbConn) : void
    {
        $queries = [
            'CREATE TABLE "subscriber" ("email" varchar(255) PRIMARY KEY, "name" varchar(255) NOT NULL)',
            'CREATE TABLE "subscription" (' .
                '"id" uuid PRIMARY KEY,' .
                ' "subscriber_email" varchar(255) NOT NULL' .
                ' REFERENCES "subscriber" ("email") ON UPDATE CASCADE DEFERRABLE INITIALLY IMMEDIATE,' .
                ' "list" varchar(255) NOT NULL' .
            ')',
        ];

        foreach ($queries as $query)
        {
            $result = pg_query($dbConn, $query);

            if ($result === FALSE)
            {
                throw new \Exception("Failed to create the subscriber tables, running: {$query}");
            }
        }
    }


    public function down(\PgSql\Connection $dbConn) : void
    {
        pg_query($dbConn, 'DROP TABLE "subscription", "subscriber"');
    }
}
