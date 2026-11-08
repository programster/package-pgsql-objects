<?php

/*
 * Test that changing an object's ID through updateById() removes the old ID from the cache, on a table
 * whose ID column isn't named "id".
 */

use Programster\PgsqlObjects\Exceptions\ExceptionNoSuchIdException;

class TestUpdateChangesIdColumn
{
    public function __construct()
    {
        ProductTable::getInstance()->enableCache(); // this test checks how the cache behaves.
        $db = ConnectionHandler::getDb();
        $db->query("TRUNCATE {$db->escapeIdentifier("product")}");
        ProductTable::getInstance()->emptyCache();
    }


    public function run() : void
    {
        $table = ProductTable::getInstance();
        $product = $table->insert(['name' => 'widget']);
        $oldId = $product->getId();
        $newId = $table->generateId();

        $updatedProduct = $table->updateById($oldId, ['uuid' => $newId, 'name' => 'widget']);

        if ($updatedProduct->getId() !== $newId)
        {
            throw new \Exception("updateById() did not return the object with its new ID.");
        }

        $oldIdStillLoads = true;

        try
        {
            $table->load($oldId);
        }
        catch (ExceptionNoSuchIdException)
        {
            $oldIdStillLoads = false;
        }

        if ($oldIdStillLoads)
        {
            throw new \Exception("The object could still be loaded by its old ID after the ID was changed.");
        }
    }
}
