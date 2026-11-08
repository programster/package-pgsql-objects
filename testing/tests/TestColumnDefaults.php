<?php

/*
 * Test that a new object's columns that have database defaults get their defaults when the object wasn't given a value
 * for them, while objects inserted alongside it can give their own values, including null on purpose for a column that
 * allows it. This is checked for every way of inserting objects: save(), update() on an unsaved object, batchSave() and
 * Transaction, for a table whose IDs are generated in code (inserted one INSERT per object), and one whose IDs the
 * database generates (inserted by a MERGE, which needs PostgreSQL 17 or newer). The row class's accessors list every
 * column, so this checks the package handles it, rather than the row class.
 */

use Programster\PgsqlObjects\AbstractTable;
use Programster\PgsqlObjects\CacheMode;
use Programster\PgsqlObjects\Transaction;

class TestColumnDefaults
{
    # The objects to insert, by title, with what's given, and the status and category they should end up with.
    private const CASES = [
        'defaults'          => ['given' => [], 'status' => 'draft', 'category' => 'general'],
        'given'             => [
            'given'    => ['status' => 'published', 'category' => 'news'],
            'status'   => 'published',
            'category' => 'news',
        ],
        'null on purpose'   => ['given' => ['category' => null], 'status' => 'draft', 'category' => null],
        'null for not null' => ['given' => ['status' => null], 'status' => 'draft', 'category' => 'general'],
    ];


    public function run() : void
    {
        $tables = [
            'code IDs'     => [NoteUuidTable::getInstance(), NoteUuidRecord::class],
            'database IDs' => [NoteSerialTable::getInstance(), NoteSerialRecord::class],
        ];

        foreach ($tables as $kind => [$table, $recordClass])
        {
            $this->testSave($kind, $table, $recordClass);
            $this->testUpdateUnsaved($kind, $table, $recordClass);
            $this->testCloneKeepsNull($kind, $table, $recordClass);

            // inserting many objects whose IDs the database generates at once needs PostgreSQL 17 or newer.
            if ($kind === 'code IDs' || ConnectionHandler::getServerMajorVersion() >= 17)
            {
                $this->testBatchSave($kind, $table, $recordClass);
                $this->testTransaction($kind, $table, $recordClass);
            }
        }
    }


    /**
     * Each object saved on its own gets its defaults, both in the database and on the object.
     */
    private function testSave(string $kind, AbstractTable $table, string $recordClass) : void
    {
        $this->resetTable($table);

        foreach ($this->createObjects($recordClass) as $title => $object)
        {
            $object->save();
            $this->assertObjectHas("save() with {$kind}", $title, $object);
        }

        $this->assertDatabaseHasEveryCase("save() with {$kind}", $table);
    }


    /**
     * update() on a new object inserts it: a column given null in update() keeps it, while one never given gets its
     * default.
     */
    private function testUpdateUnsaved(string $kind, AbstractTable $table, string $recordClass) : void
    {
        $this->resetTable($table);
        $nullOnPurpose = $recordClass::createNewFromArray(['title' => 'null in update']);
        $nullOnPurpose->update(['category' => null]);
        $notGiven = $recordClass::createNewFromArray(['title' => 'not given']);
        $notGiven->update(['status' => 'published']);
        $rows = $this->loadByTitle($table);

        if ($rows['null in update']->getCategory() !== null || $rows['null in update']->getStatus() !== 'draft')
        {
            throw new \Exception("update() with {$kind} didn't keep the null it was given, or lost the default.");
        }

        if ($rows['not given']->getCategory() !== 'general' || $rows['not given']->getStatus() !== 'published')
        {
            throw new \Exception("update() with {$kind} lost a default, or the value it was given.");
        }
    }


    /**
     * A clone of a row with a null in a column that has a default copies the null, as the row's values were given.
     */
    private function testCloneKeepsNull(string $kind, AbstractTable $table, string $recordClass) : void
    {
        $this->resetTable($table);
        $original = $recordClass::createNewFromArray(['title' => 'original', 'category' => null]);
        $original->save();
        $loaded = $table->load($original->getId(), CacheMode::IGNORE_CACHE);
        $clone = clone $loaded;
        $clone->save();

        if ($table->load($clone->getId(), CacheMode::IGNORE_CACHE)->getCategory() !== null)
        {
            throw new \Exception("A clone with {$kind} got the default instead of copying the original's null.");
        }
    }


    /**
     * Objects that differ in which columns they were given, saved in one batch, each get what they should, and take
     * on the database's values, so that updating them afterwards keeps them.
     */
    private function testBatchSave(string $kind, AbstractTable $table, string $recordClass) : void
    {
        $this->resetTable($table);
        $objects = $this->createObjects($recordClass);
        $table->batchSave(array_values($objects));
        $this->assertDatabaseHasEveryCase("batchSave() with {$kind}", $table);
        $this->assertObjectsTookOnTheirRows("batchSave() with {$kind}", $table, $objects);
    }


    /**
     * The same, saved in a transaction.
     */
    private function testTransaction(string $kind, AbstractTable $table, string $recordClass) : void
    {
        $this->resetTable($table);
        $transaction = new Transaction();
        $objects = $this->createObjects($recordClass);

        foreach ($objects as $object)
        {
            $transaction->save($object);
        }

        $transaction->commit();
        $this->assertDatabaseHasEveryCase("A transaction with {$kind}", $table);
        $this->assertObjectsTookOnTheirRows("A transaction with {$kind}", $table, $objects);
    }


    /**
     * Create a new object for each case.
     *
     * @param string $recordClass - the row class to create them with.
     *
     * @return array<string, AbstractNoteRecord> - the objects, by title.
     */
    private function createObjects(string $recordClass) : array
    {
        $objects = [];

        foreach (self::CASES as $title => $case)
        {
            $objects[$title] = $recordClass::createNewFromArray(['title' => $title, ...$case['given']]);
        }

        return $objects;
    }


    /**
     * Check that the table has a row for each case, with the status and category it should have.
     *
     * @param string $context - what was being tested, for the failure message.
     *
     * @param AbstractTable $table
     *
     * @return void
     */
    private function assertDatabaseHasEveryCase(string $context, AbstractTable $table) : void
    {
        $rows = $this->loadByTitle($table);

        foreach (array_keys(self::CASES) as $title)
        {
            if (!isset($rows[$title]))
            {
                throw new \Exception("{$context} didn't insert the \"{$title}\" note.");
            }

            $this->assertObjectHas($context, $title, $rows[$title]);
        }
    }


    /**
     * Check that saved objects took on their rows' values, including the defaults the database filled in, and that
     * updating them afterwards keeps those values, rather than sending the nulls they had before they were saved.
     *
     * @param string $context - what was being tested, for the failure message.
     *
     * @param AbstractTable $table
     *
     * @param array<string, AbstractNoteRecord> $objects - the saved objects, by title.
     *
     * @return void
     */
    private function assertObjectsTookOnTheirRows(string $context, AbstractTable $table, array $objects) : void
    {
        foreach ($objects as $title => $object)
        {
            $this->assertObjectHas("{$context}, on the object", $title, $object);
            $object->update(['title' => "{$title} updated"]);
            $rowAfterUpdate = $table->load($object->getId(), CacheMode::IGNORE_CACHE);
            $this->assertObjectHas("{$context}, then update()", $title, $rowAfterUpdate);
        }
    }


    /**
     * Check that an object has the status and category its case says it should.
     *
     * @param string $context - what was being tested, for the failure message.
     *
     * @param string $title - the object's case.
     *
     * @param AbstractNoteRecord $object
     *
     * @return void
     */
    private function assertObjectHas(string $context, string $title, AbstractNoteRecord $object) : void
    {
        $case = self::CASES[$title];

        if ($object->getStatus() !== $case['status'] || $object->getCategory() !== $case['category'])
        {
            $actual = var_export([$object->getStatus(), $object->getCategory()], true);
            throw new \Exception("{$context} gave the \"{$title}\" note the wrong status and category: {$actual}.");
        }
    }


    /**
     * Load every row of the table, indexed by title.
     *
     * @param AbstractTable $table
     *
     * @return array<string, AbstractNoteRecord>
     */
    private function loadByTitle(AbstractTable $table) : array
    {
        $rows = [];

        foreach ($table->loadAll(CacheMode::IGNORE_CACHE) as $row)
        {
            $rows[$row->getTitle()] = $row;
        }

        return $rows;
    }


    private function resetTable(AbstractTable $table) : void
    {
        $db = ConnectionHandler::getDb();
        $db->query("TRUNCATE {$table->getEscapedQualifiedTableName()} RESTART IDENTITY");
    }
}
