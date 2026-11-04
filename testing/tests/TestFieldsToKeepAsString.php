<?php

/*
 * Test that columns listed in getFieldsToKeepAsString() keep the exact string the database returns, while other
 * columns of the same type are still converted.
 */

class TestFieldsToKeepAsString
{
    public function __construct()
    {
        $db = ConnectionHandler::getDb();
        $db->query("TRUNCATE {$db->escapeIdentifier("ledger")}");
        LedgerTable::getInstance()->emptyCache();
    }


    public function run() : void
    {
        // more significant digits than a float can hold, so converting it would lose precision.
        $amount = '12345678901234567890.1234567890';
        $table = LedgerTable::getInstance();
        $created = $table->create(['exact_amount' => $amount, 'approximate_amount' => $amount]);

        // bypass the cache so the values come from the database.
        $loaded = $table->load($created->getId(), false);

        if ($loaded->getExactAmount() !== $amount)
        {
            $actual = var_export($loaded->getExactAmount(), true);
            throw new \Exception("A column listed in getFieldsToKeepAsString() was not kept as a string, got {$actual}.");
        }

        if (!is_float($loaded->getApproximateAmount()))
        {
            $actual = var_export($loaded->getApproximateAmount(), true);
            throw new \Exception("A numeric column not listed in getFieldsToKeepAsString() was not converted, got {$actual}.");
        }
    }
}
