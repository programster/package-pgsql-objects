<?php

/*
 * Creates a table with columns the database always generates (a stored generated column, and a non-ID identity
 * column), so we can test that they are never written to, and are read back after saving.
 */

class CreateInvoiceTable implements \Programster\PgsqlMigrations\MigrationInterface
{
    public function up($dbConn) : void
    {
        $query =
            'CREATE TABLE "invoice" (
                "id" uuid NOT NULL,
                "quantity" integer NOT NULL,
                "unit_price_pence" integer NOT NULL,
                "total_pence" integer GENERATED ALWAYS AS ("quantity" * "unit_price_pence") STORED,
                "sequence_number" integer GENERATED ALWAYS AS IDENTITY,
                PRIMARY KEY (id)
            );';

        $result = pg_query($dbConn, $query);

        if ($result === FALSE)
        {
            throw new \Exception("Failed to create the invoice table.");
        }
    }


    public function down($dbConn) : void
    {
        pg_query($dbConn, 'DROP TABLE "invoice"');
    }
}
