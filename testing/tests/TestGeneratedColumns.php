<?php

/*
 * Test columns listed in getVirtualFields(), covering stored and virtual generated columns, and identity
 * columns:
 * - they aren't required when creating objects, and any values provided for them are ignored.
 * - they are never written to, so inserts and updates (including batches) succeed.
 * - their values are read back from the database after save(), update(), insert() and updateById().
 */

use Programster\PgsqlObjects\CacheMode;

class TestGeneratedColumns
{
    public function __construct()
    {
        InvoiceTable::getInstance()->enableCache(); // this test checks how the cache behaves.
        $db = ConnectionHandler::getDb();
        $db->query("TRUNCATE {$db->escapeIdentifier("invoice")}");
        InvoiceTable::getInstance()->emptyCache();
    }


    public function run() : void
    {
        $this->testCreate();
        $this->testCreateIgnoresGeneratedValues();
        $this->testSaveNewObject();
        $this->testObjectUpdate();
        $this->testSaveExistingObject();
        $this->testTableUpdateOfCachedObject();
        $this->testBatchSave();
    }


    private function testCreate() : void
    {
        $invoice = InvoiceTable::getInstance()->insert(['quantity' => 2, 'unit_price_pence' => 150]);
        $this->assertTotal($invoice, 300, "insert()");

        if ($invoice->getSequenceNumber() === null)
        {
            throw new \Exception("insert() did not read back the generated identity column.");
        }
    }


    private function testCreateIgnoresGeneratedValues() : void
    {
        $invoice = InvoiceTable::getInstance()->insert([
            'quantity' => 3,
            'unit_price_pence' => 100,
            'total_pence' => 999,
            'total_with_vat_pence' => 999,
            'sequence_number' => -1,
        ]);

        $this->assertTotal($invoice, 300, "insert() with generated values provided");

        if ($invoice->getSequenceNumber() === -1)
        {
            throw new \Exception("insert() wrote the provided value to a generated identity column.");
        }
    }


    private function testSaveNewObject() : void
    {
        $invoice = InvoiceRecord::createNewFromArray(['quantity' => 4, 'unit_price_pence' => 25]);
        $invoice->save();
        $this->assertTotal($invoice, 100, "save() of a new object");
        $loaded = InvoiceTable::getInstance()->load($invoice->getId(), CacheMode::UPDATE_CACHE);
        $this->assertTotal($loaded, 100, "loading a saved object");
    }


    private function testObjectUpdate() : void
    {
        $invoice = InvoiceTable::getInstance()->insert(['quantity' => 1, 'unit_price_pence' => 50]);
        $invoice->update(['quantity' => 5]);
        $this->assertTotal($invoice, 250, "the object's update()");
        $this->assertTotal(InvoiceTable::getInstance()->load($invoice->getId()), 250, "the cache after update()");
    }


    private function testSaveExistingObject() : void
    {
        $invoice = InvoiceTable::getInstance()->insert(['quantity' => 1, 'unit_price_pence' => 70]);
        $invoice->setQuantity(3);
        $invoice->save();
        $this->assertTotal($invoice, 210, "save() of an existing object");
    }


    private function testTableUpdateOfCachedObject() : void
    {
        $table = InvoiceTable::getInstance();
        $invoice = $table->insert(['quantity' => 1, 'unit_price_pence' => 10]); // now in the cache.
        $updated = $table->updateById($invoice->getId(), ['quantity' => 6, 'total_pence' => 1]);
        $this->assertTotal($updated, 60, "updateById() of a cached object");
        $this->assertTotal($table->load($invoice->getId()), 60, "the cache after updateById()");
    }


    private function testBatchSave() : void
    {
        $table = InvoiceTable::getInstance();
        $existing = $table->insert(['quantity' => 1, 'unit_price_pence' => 5]);
        $existing->setQuantity(2);
        $new = InvoiceRecord::createNewFromArray(['quantity' => 3, 'unit_price_pence' => 5]);

        $table->batchSave([$existing, $new]);

        $loaded = $table->load($existing->getId(), CacheMode::UPDATE_CACHE);
        $this->assertTotal($loaded, 10, "batchSave() of an existing object");
        $this->assertTotal($table->load($new->getId(), CacheMode::UPDATE_CACHE), 15, "batchSave() of a new object");
    }


    /**
     * Check both the stored (total_pence) and virtual (total_with_vat_pence) generated columns.
     */
    private function assertTotal(InvoiceRecord $invoice, int $expectedTotal, string $context) : void
    {
        if ($invoice->getTotalPence() !== $expectedTotal)
        {
            $actual = var_export($invoice->getTotalPence(), true);

            throw new \Exception(
                "Stored generated column was wrong after {$context}: expected {$expectedTotal}, got {$actual}."
            );
        }

        $expectedTotalWithVat = intdiv($expectedTotal * 12, 10);

        if ($invoice->getTotalWithVatPence() !== $expectedTotalWithVat)
        {
            $actual = var_export($invoice->getTotalWithVatPence(), true);

            throw new \Exception(
                "Virtual generated column was wrong after {$context}: expected {$expectedTotalWithVat}, got {$actual}."
            );
        }
    }
}
