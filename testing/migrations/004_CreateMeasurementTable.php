<?php

/*
 * Creates a table with double precision and money columns, so we can test how column types are
 * converted when loading rows.
 */

class CreateMeasurementTable implements \Programster\PgsqlMigrations\MigrationInterface
{
    public function up(\PgSql\Connection $dbConn) : void
    {
        $query =
            'CREATE TABLE "measurement" (
                "id" uuid NOT NULL,
                "reading" double precision NOT NULL,
                "price" money NOT NULL,
                PRIMARY KEY (id)
            );';

        $result = pg_query($dbConn, $query);

        if ($result === FALSE)
        {
            throw new \Exception("Failed to create the measurement table.");
        }
    }


    public function down(\PgSql\Connection $dbConn) : void
    {
        pg_query($dbConn, 'DROP TABLE "measurement"');
    }
}
