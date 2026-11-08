<?php

/*
 * Test that every method given column names throws an ExceptionUnknownColumn for one it doesn't recognise, such as a
 * typo, rather than silently dropping the value or failing with a less clear database error, and that nothing is
 * changed when it does.
 */

use Programster\PgsqlObjects\CacheMode;
use Programster\PgsqlObjects\Exceptions\ExceptionUnknownColumn;

class TestUnknownColumns
{
    public function __construct()
    {
        $db = ConnectionHandler::getDb();
        $db->query("TRUNCATE {$db->escapeIdentifier("user")}");
        UserTable::getInstance()->emptyCache();
    }


    public function run() : void
    {
        $table = UserTable::getInstance();
        $user = $table->insert(['name' => 'original', 'email' => 'original@gmail.com']);
        $userId = $user->getId();

        $typoRow = ['name' => 'typo', 'emial' => 'typo@gmail.com'];

        $calls = [
            'createNewFromArray()'    => function () use ($typoRow) : void {
                UserRecord::createNewFromArray($typoRow);
            },
            'createFromDatabaseRow()' => function () use ($userId) : void {
                UserRecord::createFromDatabaseRow(['id' => $userId, 'name' => 'a', 'email' => 'a', 'extra' => 1]);
            },
            'update()'                => function () use ($user) : void {
                $user->update(['name' => 'changed', 'emial' => 'x']);
            },
            'insert()'                => function () use ($table, $typoRow) : void { $table->insert($typoRow); },
            'batchInsert()'           => function () use ($table, $typoRow) : void { $table->batchInsert([$typoRow]); },
            'updateById()'            => function () use ($table, $userId) : void {
                $table->updateById($userId, ['name' => 'changed', 'emial' => 'x']);
            },
            'loadWhereAnd()'          => function () use ($table) : void {
                $table->loadWhereAnd(['emial' => 'x'], CacheMode::USE_CACHE);
            },
            'loadWhereOr()'           => function () use ($table) : void {
                $table->loadWhereOr(['name' => 'x', 'emial' => 'x'], CacheMode::USE_CACHE);
            },
            'deleteWhereAnd()'        => function () use ($table) : void {
                $table->deleteWhereAnd(['emial' => 'x']);
            },
            'deleteWhereOr()'         => function () use ($table) : void {
                $table->deleteWhereOr(['name' => 'original', 'emial' => 'x']);
            },
            'loadRange()'             => function () use ($table) : void {
                $table->loadRange(0, 10, CacheMode::USE_CACHE, 'emial');
            },
        ];

        foreach ($calls as $methodName => $call)
        {
            $threw = false;

            try
            {
                $call();
            }
            catch (ExceptionUnknownColumn)
            {
                $threw = true;
            }

            if ($threw === false)
            {
                throw new \Exception("{$methodName} did not throw an ExceptionUnknownColumn for an unknown column.");
            }
        }

        // update() checks every key before changing anything, so neither the object nor its row changed.
        $loaded = $table->load($userId, CacheMode::UPDATE_CACHE);
        $rowCount = count($table->loadAll(CacheMode::UPDATE_CACHE));

        if ($user->getName() !== 'original' || $loaded->getName() !== 'original' || $rowCount !== 1)
        {
            throw new \Exception("A call with an unknown column changed the object or the table.");
        }
    }
}
