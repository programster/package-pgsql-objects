<?php

/*
 * Test that changing an object's ID through update() removes the old ID from the cache, on a table
 * whose ID column isn't named "id".
 */

use Programster\PgsqlObjects\Exceptions\ExceptionNoSuchIdException;
use Programster\PgsqlObjects\Utils;

class TestUpdateChangesIdColumn
{
    public function __construct()
    {
        $db = ConnectionHandler::getDb();
        $db->query("TRUNCATE {$db->escapeIdentifier("product")}");
        ProductTable::getInstance()->emptyCache();
    }


    public function run() : void
    {
        $table = ProductTable::getInstance();
        $product = $table->create(['name' => 'widget']);
        $oldId = $product->getId();
        $newId = Utils::generateUuid();

        $updatedProduct = $table->update($oldId, ['uuid' => $newId, 'name' => 'widget']);

        if ($updatedProduct->getId() !== $newId)
        {
            throw new \Exception("update() did not return the object with its new ID.");
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
