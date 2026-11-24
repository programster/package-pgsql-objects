<?php

/*
 * Test that a UUID table rejects an ID that isn't a UUID (or isn't an int or a string at all) with ExceptionInvalidId,
 * before any query runs, from every method that takes an ID, and that a valid UUID in upper case is treated as the same
 * ID as the lower case one PostgreSQL returns, including in the cache, and as the keys of loadIds().
 */

use Programster\PgsqlObjects\CacheMode;
use Programster\PgsqlObjects\Exceptions\ExceptionInvalidId;

class TestInvalidIds
{
    public function __construct()
    {
        UserTable::getInstance()->enableCache(); // this test checks the cache is matched by an upper case UUID.
        $db = ConnectionHandler::getDb();
        $db->query("TRUNCATE {$db->escapeIdentifier("user")}");
        UserTable::getInstance()->emptyCache();
    }


    public function run() : void
    {
        $this->testInvalidIdsAreRejected();
        $this->testSurroundingWhitespaceIsRejected();
        $this->testUpperCaseUuidsMatch();
    }


    /**
     * Every method that takes an ID throws ExceptionInvalidId for one that isn't a UUID, and doesn't change anything.
     */
    private function testInvalidIdsAreRejected() : void
    {
        $table = UserTable::getInstance();
        $user = $table->insert(['name' => 'kept', 'email' => 'kept@example.com']);
        $bad = 'not-a-uuid';
        $row = ['name' => 'new', 'email' => 'new@example.com'];

        $calls = [
            'load()'               => function () use ($table, $bad) : void { $table->load($bad); },
            'load() with an int'   => function () use ($table) : void { $table->load(42); },
            'loadIds()'            => function () use ($table, $user, $bad) : void {
                $table->loadIds([RecordIds::get($user), $bad]);
            },
            'loadIdsNotIn()'       => function () use ($table, $bad) : void {
                $table->loadIdsNotIn([$bad], CacheMode::IGNORE_CACHE);
            },
            'loadAfterId()'        => function () use ($table, $bad) : void {
                $table->loadAfterId($bad, 10, CacheMode::IGNORE_CACHE);
            },
            'loadWhereAnd()'       => function () use ($table, $bad) : void {
                $table->loadWhereAnd(['id' => $bad], CacheMode::IGNORE_CACHE);
            },
            'loadWhereOr() list'   => function () use ($table, $user, $bad) : void {
                $table->loadWhereOr(['id' => [$user->getId(), $bad]], CacheMode::IGNORE_CACHE);
            },
            'deleteById()'         => function () use ($table, $bad) : void { $table->deleteById($bad); },
            'deleteByIds()'        => function () use ($table, $user, $bad) : void {
                $table->deleteByIds([RecordIds::get($user), $bad]);
            },
            'deleteIdsNotIn()'     => function () use ($table, $bad) : void { $table->deleteIdsNotIn([$bad]); },
            'deleteWhereOr()'      => function () use ($table, $bad) : void { $table->deleteWhereOr(['id' => $bad]); },
            'updateById()'         => function () use ($table, $bad) : void {
                $table->updateById($bad, ['name' => 'x']);
            },
            'updateById() new ID'  => function () use ($table, $user, $bad) : void {
                $table->updateById(RecordIds::get($user), ['id' => $bad]);
            },
            'insert()'             => function () use ($table, $row, $bad) : void {
                $table->insert(['id' => $bad, ...$row]);
            },
            'batchInsert()'        => function () use ($table, $row, $bad) : void {
                $table->batchInsert([$row, ['id' => $bad, ...$row]]);
            },
            'createFromArray()' => function () use ($row, $bad) : void {
                UserRecord::createFromArray(['id' => $bad, ...$row]);
            },
            'a record\'s update()' => function () use ($user, $bad) : void { $user->update(['id' => $bad]); },

            // IDs that aren't an int or a string at all.
            'insert() float'       => function () use ($table, $row) : void { $table->insert(['id' => 1.5, ...$row]); },
            'loadWhereOr() float'  => function () use ($table) : void {
                $table->loadWhereOr(['id' => [1.5]], CacheMode::IGNORE_CACHE);
            },
            'createFromArray() array' => function () use ($row) : void {
                UserRecord::createFromArray(['id' => ['x'], ...$row]);
            },
            'update() bool'        => function () use ($user) : void { $user->update(['id' => true]); },
            'createFromDatabaseRow() float' => function () use ($row) : void {
                UserRecord::createFromDatabaseRow(['id' => 1.5, ...$row]);
            },
        ];

        foreach ($calls as $methodName => $call)
        {
            $threw = false;

            try
            {
                $call();
            }
            catch (ExceptionInvalidId)
            {
                $threw = true;
            }

            if ($threw === false)
            {
                throw new \Exception("{$methodName} didn't throw ExceptionInvalidId for an ID that isn't a UUID.");
            }
        }

        $rows = $table->loadAll(CacheMode::IGNORE_CACHE);

        if (count($rows) !== 1 || $rows[0]->getName() !== 'kept' || $user->getId() !== $rows[0]->getId())
        {
            throw new \Exception("A call with an ID that isn't a UUID still changed something.");
        }
    }


    /**
     * A valid UUID with anything around it, even just a trailing newline, isn't accepted as an ID.
     */
    private function testSurroundingWhitespaceIsRejected() : void
    {
        $table = UserTable::getInstance();
        $uuid = $table->generateId();

        foreach (["{$uuid}\n", " {$uuid}", "{$uuid} "] as $id)
        {
            $threw = false;

            try
            {
                $table->normalizeId($id);
            }
            catch (ExceptionInvalidId)
            {
                $threw = true;
            }

            if ($threw === false)
            {
                throw new \Exception("normalizeId() accepted " . var_export($id, true) . " as a UUID.");
            }
        }
    }


    /**
     * A valid UUID in upper case is the same ID as the lower case one PostgreSQL returns.
     */
    private function testUpperCaseUuidsMatch() : void
    {
        $table = UserTable::getInstance();
        $user = $table->insert(['name' => 'upper', 'email' => 'upper@example.com']);
        $upperCaseId = strtoupper(RecordIds::getString($user));

        if ($table->load($upperCaseId) !== $user)
        {
            throw new \Exception("Loading by an upper case UUID didn't return the cached record.");
        }

        $loaded = $table->loadIds([$upperCaseId], CacheMode::IGNORE_CACHE);

        if (array_keys($loaded) !== [$user->getId()])
        {
            throw new \Exception("loadIds() didn't key its records by the lower case UUID.");
        }

        $created = UserRecord::createFromArray(['id' => $upperCaseId, 'name' => 'x', 'email' => 'x']);

        if ($created->getId() !== $user->getId())
        {
            throw new \Exception("createFromArray() didn't lower case the UUID it was given.");
        }

        if ($table->normalizeId($upperCaseId) !== $user->getId())
        {
            throw new \Exception("normalizeId() didn't lower case a valid UUID.");
        }
    }
}
