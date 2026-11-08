<?php

/*
 * Test that every way of deleting rows through the table, while the cache is enabled, only forgets the deleted rows'
 * cached objects, marking them as deleted so they can't be saved again, and leaves the remaining rows' objects in the
 * cache, so there is still only one object for each of them.
 */

use Programster\PgsqlObjects\CacheMode;
use Programster\PgsqlObjects\Exceptions\ExceptionNoSuchIdException;
use Programster\PgsqlObjects\Exceptions\ExceptionObjectDeleted;

class TestCacheDeletes
{
    public function __construct()
    {
    }


    public function run() : void
    {
        // deleteWhereAnd() and deleteWhereOr() are protected, so call them from within the table's scope.
        $deleteWhereAnd = function (array $wherePairs) : void { $this->deleteWhereAnd($wherePairs); };
        $deleteWhereOr = function (array $wherePairs) : void { $this->deleteWhereOr($wherePairs); };

        $deleteOne = [
            'deleteById()' => function (UserTable $table, UserRecord $one) : void {
                $table->deleteById($one->getId());
            },
            'deleteByIds()' => function (UserTable $table, UserRecord $one) : void {
                $table->deleteByIds([$one->getId()]);
            },
            'deleteWhereAnd()' => function (UserTable $table, UserRecord $one) use ($deleteWhereAnd) : void {
                $deleteWhereAnd->call($table, ['name' => 'one', 'email' => 'one@gmail.com']);
            },
            'deleteWhereOr()' => function (UserTable $table, UserRecord $one) use ($deleteWhereOr) : void {
                $deleteWhereOr->call($table, ['name' => 'one', 'email' => 'no-such-email']);
            },
        ];

        foreach ($deleteOne as $methodName => $delete)
        {
            [$table, $one, $two, $three] = $this->createCachedUsers();
            $delete($table, $one);
            $this->assertForgotten($methodName, $one);
            $this->assertStillCached($methodName, [$two, $three]);
        }

        [$table, $one, $two, $three] = $this->createCachedUsers();
        $table->deleteIdsNotIn([$two->getId(), $three->getId()]);
        $this->assertForgotten("deleteIdsNotIn()", $one);
        $this->assertStillCached("deleteIdsNotIn()", [$two, $three]);

        [$table, $one, $two, $three] = $this->createCachedUsers();
        $table->deleteAll();

        foreach ([$one, $two, $three] as $user)
        {
            $this->assertForgotten("deleteAll()", $user);
        }
    }


    /**
     * Start a case with the cache enabled, and three users in the table, whose objects are cached.
     *
     * @return array - the table, then the users called one, two and three.
     */
    private function createCachedUsers() : array
    {
        $db = ConnectionHandler::getDb();
        $db->query("TRUNCATE {$db->escapeIdentifier("user")}");
        $table = UserTable::getInstance();
        $table->disableCache();
        $table->enableCache();

        $rows = [
            ['name' => 'one', 'email' => 'one@gmail.com'],
            ['name' => 'two', 'email' => 'two@gmail.com'],
            ['name' => 'three', 'email' => 'three@gmail.com'],
        ];

        $usersByName = [];

        foreach ($table->batchInsert($rows) as $user)
        {
            $usersByName[$user->getName()] = $user;
        }

        return [$table, $usersByName['one'], $usersByName['two'], $usersByName['three']];
    }


    /**
     * Check that a deleted user can't be loaded, and its object can't be saved again.
     */
    private function assertForgotten(string $methodName, UserRecord $user) : void
    {
        $couldLoad = true;

        try
        {
            UserTable::getInstance()->load($user->getId());
        }
        catch (ExceptionNoSuchIdException)
        {
            $couldLoad = false;
        }

        $couldSave = true;

        try
        {
            $user->save();
        }
        catch (ExceptionObjectDeleted)
        {
            $couldSave = false;
        }

        if ($couldLoad || $couldSave)
        {
            throw new \Exception("After {$methodName}, a deleted user could still be loaded, or saved again.");
        }
    }


    /**
     * Check that loading the remaining users gives back the same objects, from the cache.
     */
    private function assertStillCached(string $methodName, array $users) : void
    {
        foreach ($users as $user)
        {
            if (UserTable::getInstance()->load($user->getId(), CacheMode::USE_CACHE) !== $user)
            {
                throw new \Exception("After {$methodName}, a remaining user's object was no longer the cached one.");
            }
        }
    }
}
