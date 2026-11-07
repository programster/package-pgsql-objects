<?php

/*
 * Creates a table whose identifier column is named "uuid" rather than "id", so we can test
 * tables that override getIdColumnName().
 */

class CreateProductTable implements \Programster\PgsqlMigrations\MigrationInterface
{
    public function up(\PgSql\Connection $dbConn) : void
    {
        $query =
            'CREATE TABLE "product" (
                "uuid" uuid NOT NULL,
                "name" varchar(255) NOT NULL,
                PRIMARY KEY (uuid)
            );';

        $result = pg_query($dbConn, $query);

        if ($result === FALSE)
        {
            throw new \Exception("Failed to create the product table.");
        }
    }


    public function down(\PgSql\Connection $dbConn) : void
    {
        pg_query($dbConn, 'DROP TABLE "product"');
    }
}
