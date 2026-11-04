<?php

/*
 * Creates a table with two identical high-precision numeric columns, so we can test keeping one of them as a string
 * while the other is converted to a float.
 */

class CreateLedgerTable implements \Programster\PgsqlMigrations\MigrationInterface
{
    public function up($dbConn) : void
    {
        $query =
            'CREATE TABLE "ledger" (
                "id" uuid NOT NULL,
                "exact_amount" numeric(30,10) NOT NULL,
                "approximate_amount" numeric(30,10) NOT NULL,
                PRIMARY KEY (id)
            );';

        $result = pg_query($dbConn, $query);

        if ($result === FALSE)
        {
            throw new \Exception("Failed to create the ledger table.");
        }
    }


    public function down($dbConn) : void
    {
        pg_query($dbConn, 'DROP TABLE "ledger"');
    }
}
