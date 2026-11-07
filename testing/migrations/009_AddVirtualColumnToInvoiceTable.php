<?php

/*
 * Adds a virtual generated column to the invoice table, which isn't stored, but is calculated whenever it is read, so
 * we can test those as well as stored generated columns.
 * Virtual generated columns need PostgreSQL 18 or newer.
 */

class AddVirtualColumnToInvoiceTable implements \Programster\PgsqlMigrations\MigrationInterface
{
    public function up(\PgSql\Connection $dbConn) : void
    {
        $query =
            'ALTER TABLE "invoice"
                ADD COLUMN "total_with_vat_pence" integer
                GENERATED ALWAYS AS ("quantity" * "unit_price_pence" * 12 / 10) VIRTUAL;';

        $result = pg_query($dbConn, $query);

        if ($result === FALSE)
        {
            throw new \Exception("Failed to add the virtual column to the invoice table. This needs PostgreSQL 18+.");
        }
    }


    public function down(\PgSql\Connection $dbConn) : void
    {
        pg_query($dbConn, 'ALTER TABLE "invoice" DROP COLUMN "total_with_vat_pence"');
    }
}
