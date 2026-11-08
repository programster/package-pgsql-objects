<?php

/*
 * Test the object cache: it is disabled by default, so every load queries the database, and while it's enabled there
 * is only one object for each row, which loading, saving and updating all keep using. Rows are changed with raw SQL,
 * behind the cache's back, to tell whether a load came from the cache or the database.
 */

use Programster\PgsqlObjects\CacheMode;
use Programster\PgsqlObjects\Exceptions\ExceptionMissingRequiredData;

class TestCache
{
    public function __construct()
    {
        $db = ConnectionHandler::getDb();
        $db->query("TRUNCATE {$db->escapeIdentifier("user")}");
        UserTable::getInstance()->disableCache();
    }


    public function run() : void
    {
        $this->testDisabledByDefault();
        $this->testLoadAllFillsTheCache();
        $this->testLoadAllCacheModes();
        $this->testQueriesReturnCachedObjectsAsTheyAre();
        $this->testLoadByIdCacheModes();
        $this->testIgnoreCacheDoesNotAddToTheCache();
        $this->testSavingKeepsTheSavedObject();
        $this->testTableUpdateRefreshesTheCachedObject();
        $this->testCacheObjectRejectsUnsavedObjects();
        $this->testDisableCacheEmptiesIt();
    }


    private function testDisabledByDefault() : void
    {
        $table = UserTable::getInstance();
        $user = $table->insert(['name' => 'original', 'email' => 'disabled@gmail.com']);
        $this->changeNameBehindTheCache($user->getId(), 'changed');
        $loaded = $table->load($user->getId());

        if ($table->isCacheEnabled() || $loaded === $user || $loaded->getName() !== 'changed')
        {
            throw new \Exception("With the cache disabled, load() didn't return a fresh object from the database.");
        }
    }


    private function testLoadAllFillsTheCache() : void
    {
        $table = $this->createCachedTableWithUsers();
        $users = $table->loadAll(CacheMode::USE_CACHE);
        $first = $users[0];
        $this->changeNameBehindTheCache($first->getId(), 'changed');
        $loaded = $table->load($first->getId());

        if ($loaded !== $first || $loaded->getName() === 'changed')
        {
            throw new \Exception("load() didn't return the object loadAll() cached, without a query.");
        }
    }


    private function testLoadAllCacheModes() : void
    {
        $table = $this->createCachedTableWithUsers();
        $user = $table->loadAll(CacheMode::USE_CACHE)[0];
        $this->changeNameBehindTheCache($user->getId(), 'changed');

        // IGNORE_CACHE gives a new object with the database's values, and leaves the cached one alone.
        $ignored = $this->findObject($table->loadAll(CacheMode::IGNORE_CACHE), $user->getId());

        if ($ignored === $user || $ignored->getName() !== 'changed' || $user->getName() === 'changed')
        {
            throw new \Exception("loadAll() with IGNORE_CACHE didn't return a new object and leave the cache alone.");
        }

        // USE_CACHE gives the cached object as it is.
        $used = $this->findObject($table->loadAll(CacheMode::USE_CACHE), $user->getId());

        if ($used !== $user || $user->getName() === 'changed')
        {
            throw new \Exception("loadAll() with USE_CACHE didn't return the cached object as it was.");
        }

        // UPDATE_CACHE updates the cached object's values, in place.
        $updated = $this->findObject($table->loadAll(CacheMode::UPDATE_CACHE), $user->getId());

        if ($updated !== $user || $user->getName() !== 'changed')
        {
            throw new \Exception("loadAll() with UPDATE_CACHE didn't update the cached object in place.");
        }
    }


    private function testQueriesReturnCachedObjectsAsTheyAre() : void
    {
        $table = $this->createCachedTableWithUsers();
        $user = $table->loadAll(CacheMode::USE_CACHE)[0];
        $this->changeNameBehindTheCache($user->getId(), 'changed');
        $matches = $table->loadWhereAnd(['id' => $user->getId()], CacheMode::USE_CACHE);

        if (count($matches) !== 1 || $matches[0] !== $user || $user->getName() === 'changed')
        {
            throw new \Exception("loadWhereAnd() didn't return the cached object as it was.");
        }
    }


    private function testLoadByIdCacheModes() : void
    {
        $table = $this->createCachedTableWithUsers();
        $user = $table->loadAll(CacheMode::USE_CACHE)[0];
        $id = $user->getId();
        $this->changeNameBehindTheCache($id, 'changed');

        // load() defaults to USE_CACHE, which returns the cached object without a query.
        if ($table->load($id) !== $user || $user->getName() === 'changed')
        {
            throw new \Exception("load() with USE_CACHE didn't return the cached object without a query.");
        }

        $ignored = $table->load($id, CacheMode::IGNORE_CACHE);

        if ($ignored === $user || $ignored->getName() !== 'changed' || $user->getName() === 'changed')
        {
            throw new \Exception("load() with IGNORE_CACHE didn't return a new object and leave the cache alone.");
        }

        if ($table->load($id, CacheMode::UPDATE_CACHE) !== $user || $user->getName() !== 'changed')
        {
            throw new \Exception("load() with UPDATE_CACHE didn't update the cached object in place.");
        }
    }


    private function testIgnoreCacheDoesNotAddToTheCache() : void
    {
        $table = $this->createCachedTableWithUsers();
        $ignored = $table->loadAll(CacheMode::IGNORE_CACHE)[0];

        // if IGNORE_CACHE had added it, USE_CACHE would give back the same object.
        if ($table->load($ignored->getId()) === $ignored)
        {
            throw new \Exception("loadAll() with IGNORE_CACHE added its objects to the cache.");
        }

        // whereas USE_CACHE adds a row it had to fetch, so loading it again gives the same object.
        $fetched = $table->load($ignored->getId());

        if ($table->load($ignored->getId()) !== $fetched)
        {
            throw new \Exception("load() with USE_CACHE didn't add the row it fetched to the cache.");
        }
    }


    private function testSavingKeepsTheSavedObject() : void
    {
        $table = $this->createCachedTableWithUsers();

        // a new object that is saved becomes the cached object for its row.
        $user = UserRecord::createNewFromArray(['name' => 'saved', 'email' => 'saved@gmail.com']);
        $user->save();
        $this->assertCached($user, "save() of a new object");

        // as does one that is updated, including when its ID changes.
        $user->update(['name' => 'updated']);
        $this->assertCached($user, "update()");
        $user->update(['id' => $table->generateId()]);
        $this->assertCached($user, "update() that changed the ID");

        // and the objects given to batchSave().
        $batchUser = UserRecord::createNewFromArray(['name' => 'batch', 'email' => 'batch@gmail.com']);
        $table->batchSave([$batchUser]);
        $this->assertCached($batchUser, "batchSave()");
    }


    private function testTableUpdateRefreshesTheCachedObject() : void
    {
        $table = $this->createCachedTableWithUsers();
        $user = $table->loadAll(CacheMode::USE_CACHE)[0];
        $updated = $table->updateById($user->getId(), ['name' => 'by the table']);

        if ($updated !== $user || $user->getName() !== 'by the table')
        {
            throw new \Exception("updateById() didn't refresh and return the cached object.");
        }
    }


    private function testCacheObjectRejectsUnsavedObjects() : void
    {
        $table = $this->createCachedTableWithUsers();
        $unsaved = UserRecord::createNewFromArray(['name' => 'unsaved', 'email' => 'unsaved@gmail.com']);
        $threw = false;

        try
        {
            $table->cacheObject($unsaved);
        }
        catch (ExceptionMissingRequiredData)
        {
            $threw = true;
        }

        if ($threw === false)
        {
            throw new \Exception("cacheObject() accepted an object that hasn't been saved.");
        }
    }


    private function testDisableCacheEmptiesIt() : void
    {
        $table = $this->createCachedTableWithUsers();
        $user = $table->loadAll(CacheMode::USE_CACHE)[0];
        $table->disableCache();

        if ($table->isCacheEnabled() || $table->load($user->getId()) === $user)
        {
            throw new \Exception("disableCache() didn't disable and empty the cache.");
        }
    }


    /**
     * Start a case with an empty user table, with the cache enabled and empty, and two users in it.
     */
    private function createCachedTableWithUsers() : UserTable
    {
        $db = ConnectionHandler::getDb();
        $db->query("TRUNCATE {$db->escapeIdentifier("user")}");
        $table = UserTable::getInstance();
        $table->disableCache();
        $table->enableCache();
        $rows = [['name' => 'one', 'email' => 'one@gmail.com'], ['name' => 'two', 'email' => 'two@gmail.com']];
        $table->batchInsert($rows);
        $table->emptyCache();
        return $table;
    }


    /**
     * Check that loading the object's row gives back that same object, from the cache.
     */
    private function assertCached(UserRecord $user, string $afterWhat) : void
    {
        if (UserTable::getInstance()->load($user->getId()) !== $user)
        {
            throw new \Exception("After {$afterWhat}, the cache didn't hold the object that was saved.");
        }
    }


    /**
     * Find the object with the given ID in a list of them, as loadAll() doesn't promise an order.
     */
    private function findObject(array $users, string $id) : UserRecord
    {
        $found = null;

        foreach ($users as $user)
        {
            if ($user->getId() === $id)
            {
                $found = $user;
            }
        }

        if ($found === null)
        {
            throw new \Exception("The user {$id} wasn't loaded.");
        }

        return $found;
    }


    /**
     * Change a user's name with raw SQL, so the cache doesn't know about it.
     */
    private function changeNameBehindTheCache(string $id, string $name) : void
    {
        $db = ConnectionHandler::getDb();
        $query = "UPDATE {$db->escapeIdentifier("user")} SET \"name\" = {$db->escapeValue($name)}";
        $db->query("{$query} WHERE \"id\" = {$db->escapeValue($id)}");
    }
}
