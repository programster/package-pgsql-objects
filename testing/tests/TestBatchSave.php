<?php

/*
 * Test that batchSave() inserts new objects and updates existing ones, for a table whose IDs are generated in code.
 * TestBatchSaveDatabaseIds covers objects whose IDs the database generates.
 */

use Programster\PgsqlObjects\CacheMode;

class TestBatchSave
{
    public function __construct()
    {
        $db = ConnectionHandler::getDb();
        $db->query("TRUNCATE {$db->escapeIdentifier("product")}, {$db->escapeIdentifier("ticket")}");
        ProductTable::getInstance()->emptyCache();
        TicketTable::getInstance()->emptyCache();
    }


    public function run() : void
    {
        $this->testInsertsAndUpdates();
    }


    private function testInsertsAndUpdates() : void
    {
        $table = ProductTable::getInstance();
        $existing = $table->insert(['name' => 'existing']);
        $existing->update(['name' => 'renamed']); // saves straight away, so change it again for the batch below.
        $existingInBatch = $table->load($existing->getId());
        $newProduct = ProductRecord::createNewFromArray(['name' => 'new']);

        $existingInBatch->update(['name' => 'batch renamed']);
        $table->batchSave([$existingInBatch, $newProduct]);

        $getName = function (ProductRecord $product) : ?string { return $product->getName(); };
        $savedProducts = $table->loadIds([$existing->getId(), $newProduct->getId()], CacheMode::UPDATE_CACHE);
        $names = array_map($getName, $savedProducts);
        sort($names);

        if ($names !== ['batch renamed', 'new'])
        {
            $namesList = implode(", ", $names);
            throw new \Exception("batchSave() did not insert and update the expected rows, got: {$namesList}");
        }
    }

}
