<?php

/*
 * Adds a virtual generated column to the invoice table, which isn't stored, but is calculated whenever it is read, so
 * we can test those as well as stored generated columns.
 * Virtual generated columns need PostgreSQL 18 or newer, so on older servers it's a stored generated column instead,
 * which the package treats the same way, so that the rest of the tests can still run there.
 */

class AddVirtualColumnToInvoiceTable implements \Programster\PgsqlMigrations\MigrationInterface
{
    public function up(\PgSql\Connection $dbConn) : void
    {
        // the server's version, e.g. "18.6 (Debian 18.6-1)". Not the client library's, which may differ.
        $majorVersion = (int)explode('.', pg_version($dbConn)['server'])[0];
        $storage = ($majorVersion >= 18) ? 'VIRTUAL' : 'STORED';

        $query =
            'ALTER TABLE "invoice"
                ADD COLUMN "total_with_vat_pence" integer
                GENERATED ALWAYS AS ("quantity" * "unit_price_pence" * 12 / 10) ' . $storage . ';';

        $result = pg_query($dbConn, $query);

        if ($result === FALSE)
        {
            throw new \Exception("Failed to add the {$storage} generated column to the invoice table.");
        }
    }


    public function down(\PgSql\Connection $dbConn) : void
    {
        pg_query($dbConn, 'ALTER TABLE "invoice" DROP COLUMN "total_with_vat_pence"');
    }
}
