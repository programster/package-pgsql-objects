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
        $this->testSpreadingLoadedObjects();
        $this->testNothingToSave();
    }


    private function testInsertsAndUpdates() : void
    {
        $table = ProductTable::getInstance();
        $existing = $table->insert(['name' => 'existing']);
        $existing->update(['name' => 'renamed']); // saves straight away, so change it again for the batch below.
        $existingInBatch = $table->load(RecordIds::get($existing));
        $newProduct = ProductRecord::createFromArray(['name' => 'new']);

        $existingInBatch->update(['name' => 'batch renamed']);
        $table->batchSave($existingInBatch, $newProduct);

        $getName = function (ProductRecord $product) : ?string { return $product->getName(); };
        $savedIds = [RecordIds::get($existing), RecordIds::get($newProduct)];
        $savedProducts = $table->loadIds($savedIds, CacheMode::UPDATE_CACHE);
        $names = array_map($getName, $savedProducts);
        sort($names);

        if ($names !== ['batch renamed', 'new'])
        {
            $namesList = implode(", ", $names);
            throw new \Exception("batchSave() did not insert and update the expected rows, got: {$namesList}");
        }
    }


    /**
     * The objects from a load, which loadIds() keys by their IDs, can be spread straight into batchSave().
     */
    private function testSpreadingLoadedObjects() : void
    {
        $table = ProductTable::getInstance();
        $first = $table->insert(['name' => 'first']);
        $second = $table->insert(['name' => 'second']);
        $products = $table->loadIds([RecordIds::get($first), RecordIds::get($second)], CacheMode::IGNORE_CACHE);

        foreach ($products as $product)
        {
            $product->setName("spread {$product->getName()}");
        }

        $table->batchSave(...$products);
        $first = $table->load(RecordIds::get($first), CacheMode::IGNORE_CACHE);
        $second = $table->load(RecordIds::get($second), CacheMode::IGNORE_CACHE);

        if ($first->getName() !== 'spread first' || $second->getName() !== 'spread second')
        {
            throw new \Exception("batchSave() didn't save the objects spread from loadIds()'s keyed array.");
        }
    }


    /**
     * Saving no objects does nothing, rather than failing.
     */
    private function testNothingToSave() : void
    {
        $table = ProductTable::getInstance();
        $countBefore = count($table->loadAll(CacheMode::IGNORE_CACHE));
        $table->batchSave();

        if (count($table->loadAll(CacheMode::IGNORE_CACHE)) !== $countBefore)
        {
            throw new \Exception("batchSave() with no objects changed the table.");
        }
    }
}
