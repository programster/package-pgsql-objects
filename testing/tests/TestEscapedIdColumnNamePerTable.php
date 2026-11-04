<?php

/*
 * Test that each table uses its own ID column name, even when another table with a different ID
 * column name was used first.
 */

class TestEscapedIdColumnNamePerTable
{
    public function __construct()
    {
        $db = ConnectionHandler::getDb();
        $db->query("TRUNCATE {$db->escapeIdentifier("user")}, {$db->escapeIdentifier("product")}");
        UserTable::getInstance()->emptyCache();
        ProductTable::getInstance()->emptyCache();
    }


    public function run() : void
    {
        // use the "id" table first, so any shared state gets set to its column name.
        UserTable::getInstance()->getEscapedIdColumnName();

        $escapedProductIdColumn = ProductTable::getInstance()->getEscapedIdColumnName();

        if ($escapedProductIdColumn !== '"uuid"')
        {
            throw new \Exception("ProductTable used the ID column {$escapedProductIdColumn} instead of \"uuid\".");
        }

        // check a real query that relies on it.
        $product = ProductTable::getInstance()->create(['name' => 'widget']);
        ProductTable::getInstance()->deleteById($product->getId());

        if (count(ProductTable::getInstance()->loadAll()) !== 0)
        {
            throw new \Exception("deleteById() did not delete the product.");
        }
    }
}
