<?php

/*
 * Test that a table with a dot in its name, in a schema with a dot in its name, works throughout: the queries, and
 * the column info used to convert loaded values to the right PHP types (which pg_meta_data() can't look up, as it
 * splits a "schema.table" string on the first dot).
 */

class TestDottedNames
{
    public function __construct()
    {
        $table = DottedNameTable::getInstance();
        $table->deleteAll();
    }


    public function run() : void
    {
        $table = DottedNameTable::getInstance();
        $created = $table->insert(['reading' => 1.5]);
        $this->assertReading($table->load($created->getId(), false), 1.5, "insert()");

        $table->updateById($created->getId(), ['reading' => 2.5]);
        $this->assertReading($table->load($created->getId(), false), 2.5, "updateById()");

        $loaded = $table->load($created->getId(), false);
        $loaded->update(['reading' => 3.5]);
        $this->assertReading($table->load($created->getId(), false), 3.5, "the row object's update()");

        $newRecord = DottedNameRecord::createNewFromArray(['reading' => 4.5]);
        $table->batchSave([$newRecord]);
        $this->assertReading($table->load($newRecord->getId(), false), 4.5, "batchSave() inserting");

        // batch saving an existing object uses its update query, rather than its insert query.
        $existingRecord = $table->load($newRecord->getId(), false);
        $setReading = function (float $reading) : void { $this->m_reading = $reading; };
        $setReading->call($existingRecord, 5.5);
        $table->batchSave([$existingRecord]);
        $this->assertReading($table->load($newRecord->getId(), false), 5.5, "batchSave() updating");

        $matches = $table->loadWhereOr(['reading' => [3.5, 5.5]]);

        if (count($matches) !== 2)
        {
            throw new \Exception("loadWhereOr() did not load both rows, got " . count($matches) . ".");
        }

        $table->deleteByIds([$created->getId()]);
        $table->deleteAll();

        if (count($table->loadAll()) !== 0)
        {
            throw new \Exception("deleteByIds() and deleteAll() did not delete every row.");
        }
    }


    private function assertReading(DottedNameRecord $record, float $expected, string $methodName) : void
    {
        if ($record->getReading() !== $expected)
        {
            $actual = var_export($record->getReading(), true);
            throw new \Exception("Expected a reading of {$expected} after {$methodName}, but got {$actual}.");
        }
    }
}
