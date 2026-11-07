<?php

/*
 * Test that loaded column values are converted to the right PHP types: double precision to float,
 * and money left as the string the database returns rather than being mangled by floatval().
 */

class TestColumnTypeConversion
{
    public function __construct()
    {
        $db = ConnectionHandler::getDb();
        $db->query("TRUNCATE {$db->escapeIdentifier("measurement")}");
        MeasurementTable::getInstance()->emptyCache();
    }


    public function run() : void
    {
        $table = MeasurementTable::getInstance();
        $created = $table->insert(['reading' => 1.5, 'price' => '12.34']);

        // bypass the cache so the values come from the database.
        $loaded = $table->load($created->getId(), false);

        if ($loaded->getReading() !== 1.5)
        {
            $actual = var_export($loaded->getReading(), true);
            throw new \Exception("double precision column was not converted to a float, got {$actual}.");
        }

        if (!is_string($loaded->getPrice()) || !str_contains($loaded->getPrice(), '12.34'))
        {
            $actual = var_export($loaded->getPrice(), true);
            throw new \Exception("money column did not keep its value, got {$actual}.");
        }
    }
}
