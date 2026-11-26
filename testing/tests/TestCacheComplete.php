<?php

/*
 * Test CacheMode::USE_CACHE_IF_COMPLETE: once a loadAll() has filled the cache with every row, loadAll() returns the
 * cached objects without a query, for as long as the table's own writes keep the cache holding every row, and queries
 * again once anything could have left a row out of it. Rows are inserted with raw SQL, behind the cache's back, to tell
 * whether a loadAll() came from the cache or the database.
 */

use Programster\PgsqlObjects\CacheMode;
use Programster\PgsqlObjects\SortBy;
use Programster\PgsqlObjects\SortOrder;

class TestCacheComplete
{
    public function __construct()
    {
        ProductTable::getInstance()->disableCache();
        TagTable::getInstance()->disableCache();
    }


    public function run() : void
    {
        $this->testFirstLoadQueriesAndFillsTheCache();
        $this->testCompleteCacheIsUsedWithoutAQuery();
        $this->testWhichModesFillTheCache();
        $this->testSortByAlwaysQueries();
        $this->testDisabledCacheAlwaysQueries();
        $this->testTheTablesOwnWritesKeepItComplete();
        $this->testEmptyingOrDisablingTheCacheClearsIt();
        $this->testAnOpenTransactionClearsIt();
        $this->testLoadingAllForgetsRowsDeletedElsewhere();
        $this->testSkippedBatchSaveRowClearsIt();
        $this->testOtherLoadsActAsUseCache();
    }


    private function testFirstLoadQueriesAndFillsTheCache() : void
    {
        $table = $this->createCachedTableWithProducts();

        if ($table->isCacheComplete())
        {
            throw new \Exception("The cache said it held every row before anything had loaded them all.");
        }

        $products = $table->loadAll(CacheMode::USE_CACHE_IF_COMPLETE);

        if (count($products) !== 2 || $table->isCacheComplete() === false)
        {
            throw new \Exception("The first loadAll() with USE_CACHE_IF_COMPLETE didn't load and cache every row.");
        }
    }


    private function testCompleteCacheIsUsedWithoutAQuery() : void
    {
        $table = $this->createCachedTableWithProducts();
        $first = $table->loadAll(CacheMode::USE_CACHE_IF_COMPLETE);
        $this->insertBehindTheCache('hidden');
        $second = $table->loadAll(CacheMode::USE_CACHE_IF_COMPLETE);

        if (count($second) !== 2 || $this->sortById($first) !== $this->sortById($second))
        {
            throw new \Exception("loadAll() with USE_CACHE_IF_COMPLETE queried, rather than using the complete cache.");
        }

        // USE_CACHE always queries, so it finds the hidden row.
        if (count($table->loadAll(CacheMode::USE_CACHE)) !== 3)
        {
            throw new \Exception("loadAll() with USE_CACHE didn't query the database.");
        }
    }


    private function testWhichModesFillTheCache() : void
    {
        foreach ([CacheMode::USE_CACHE, CacheMode::UPDATE_CACHE] as $mode)
        {
            $table = $this->createCachedTableWithProducts();
            $table->loadAll($mode);

            if ($table->isCacheComplete() === false)
            {
                throw new \Exception("loadAll() with {$mode->name} didn't fill the cache with every row.");
            }
        }

        $table = $this->createCachedTableWithProducts();
        $table->loadAll(CacheMode::IGNORE_CACHE);

        if ($table->isCacheComplete())
        {
            throw new \Exception("loadAll() with IGNORE_CACHE said the cache held every row, without caching.");
        }

        // a filtered load can't fill the cache with every row.
        $table->loadWhereAnd(['name' => 'one'], CacheMode::UPDATE_CACHE);
        $table->loadRange(0, 10, CacheMode::UPDATE_CACHE);

        if ($table->isCacheComplete())
        {
            throw new \Exception("A filtered or limited load said the cache held every row.");
        }
    }


    private function testSortByAlwaysQueries() : void
    {
        $table = $this->createCachedTableWithProducts();
        $table->loadAll(CacheMode::USE_CACHE_IF_COMPLETE);
        $this->insertBehindTheCache('hidden');
        $sorted = $table->loadAll(CacheMode::USE_CACHE_IF_COMPLETE, new SortBy('name', SortOrder::ASC));

        if (count($sorted) !== 3 || $sorted[0]->getName() !== 'hidden')
        {
            throw new \Exception("loadAll() with USE_CACHE_IF_COMPLETE and a SortBy didn't query the database.");
        }
    }


    private function testDisabledCacheAlwaysQueries() : void
    {
        $table = $this->createCachedTableWithProducts();
        $table->disableCache();
        $table->loadAll(CacheMode::USE_CACHE_IF_COMPLETE);
        $this->insertBehindTheCache('hidden');

        if (count($table->loadAll(CacheMode::USE_CACHE_IF_COMPLETE)) !== 3 || $table->isCacheComplete())
        {
            throw new \Exception("With the cache disabled, USE_CACHE_IF_COMPLETE didn't query the database.");
        }
    }


    private function testTheTablesOwnWritesKeepItComplete() : void
    {
        $table = $this->createCachedTableWithProducts();
        $table->loadAll(CacheMode::USE_CACHE_IF_COMPLETE);

        $inserted = $table->insert(['name' => 'inserted']);
        $this->assertCompleteAndMatchesTheDatabase($table, "insert()");

        $batchInserted = $table->batchInsert([['name' => 'batch one'], ['name' => 'batch two']]);
        $this->assertCompleteAndMatchesTheDatabase($table, "batchInsert()");

        $saved = ProductRecord::createFromArray(['name' => 'saved']);
        $saved->save();
        $this->assertCompleteAndMatchesTheDatabase($table, "a new record's save()");

        $saved->setName('saved again');
        $saved->save();
        $this->assertCompleteAndMatchesTheDatabase($table, "an existing record's save()");

        $batchSaved = ProductRecord::createFromArray(['name' => 'batch saved']);
        $inserted->setName('inserted, then batch saved');
        $table->batchSave($batchSaved, $inserted);
        $this->assertCompleteAndMatchesTheDatabase($table, "batchSave()");

        $table->updateById(RecordIds::get($inserted), ['name' => 'updated']);
        $this->assertCompleteAndMatchesTheDatabase($table, "updateById()");

        $table->updateById(RecordIds::get($inserted), ['uuid' => $table->generateId()]);
        $this->assertCompleteAndMatchesTheDatabase($table, "updateById() changing the ID");

        $table->deleteById(RecordIds::get($saved));
        $this->assertCompleteAndMatchesTheDatabase($table, "deleteById()");

        $table->deleteByIds([RecordIds::get($batchInserted[0])]);
        $this->assertCompleteAndMatchesTheDatabase($table, "deleteByIds()");

        $table->deleteWhereAnd(['name' => 'batch two']);
        $this->assertCompleteAndMatchesTheDatabase($table, "deleteWhereAnd()");

        $table->deleteWhereOr(['name' => 'batch saved']);
        $this->assertCompleteAndMatchesTheDatabase($table, "deleteWhereOr()");

        $kept = $table->loadAll(CacheMode::USE_CACHE_IF_COMPLETE)[0];
        $table->deleteIdsNotIn([RecordIds::get($kept)]);
        $this->assertCompleteAndMatchesTheDatabase($table, "deleteIdsNotIn()");

        $kept->delete();
        $this->assertCompleteAndMatchesTheDatabase($table, "a record's delete()");

        $table->insert(['name' => 'last']);
        $table->deleteAll();
        $this->assertCompleteAndMatchesTheDatabase($table, "deleteAll()");
    }


    private function testEmptyingOrDisablingTheCacheClearsIt() : void
    {
        $table = $this->createCachedTableWithProducts();
        $table->loadAll(CacheMode::USE_CACHE_IF_COMPLETE);
        $table->emptyCache();

        if ($table->isCacheComplete() || count($table->loadAll(CacheMode::IGNORE_CACHE)) !== 2)
        {
            throw new \Exception("emptyCache() left the cache saying it held every row.");
        }

        $table->loadAll(CacheMode::USE_CACHE_IF_COMPLETE);
        $table->disableCache();
        $table->enableCache();

        if ($table->isCacheComplete())
        {
            throw new \Exception("disableCache() left the cache saying it held every row.");
        }
    }


    private function testAnOpenTransactionClearsIt() : void
    {
        $db = ConnectionHandler::getDb();

        // a write in an open transaction removes its row from the cache, as it could still be rolled back.
        $table = $this->createCachedTableWithProducts();
        $table->loadAll(CacheMode::USE_CACHE_IF_COMPLETE);
        $db->query("BEGIN");
        $table->insert(['name' => 'in a transaction']);
        $db->query("ROLLBACK");

        if ($table->isCacheComplete())
        {
            throw new \Exception("An insert in an open transaction left the cache saying it held every row.");
        }

        // so does a batchSave(), which caches its objects with cacheObject() rather than by loading their rows.
        $table = $this->createCachedTableWithProducts();
        $product = $table->loadAll(CacheMode::USE_CACHE_IF_COMPLETE)[0];
        $db->query("BEGIN");
        $product->setName('batch saved in a transaction');
        $table->batchSave($product);
        $db->query("ROLLBACK");

        if ($table->isCacheComplete())
        {
            throw new \Exception("A batchSave() in an open transaction left the cache saying it held every row.");
        }

        // a delete in an open transaction could be rolled back, bringing back a row the cache forgot.
        $table = $this->createCachedTableWithProducts();
        $products = $table->loadAll(CacheMode::USE_CACHE_IF_COMPLETE);
        $db->query("BEGIN");
        $table->deleteById(RecordIds::get($products[0]));
        $db->query("ROLLBACK");

        if ($table->isCacheComplete())
        {
            throw new \Exception("A delete in an open transaction left the cache saying it held every row.");
        }

        // a loadAll() in an open transaction doesn't cache its rows, so can't fill the cache.
        $table = $this->createCachedTableWithProducts();
        $db->query("BEGIN");
        $table->loadAll(CacheMode::UPDATE_CACHE);
        $db->query("ROLLBACK");

        if ($table->isCacheComplete())
        {
            throw new \Exception("A loadAll() in an open transaction said the cache held every row.");
        }
    }


    private function testLoadingAllForgetsRowsDeletedElsewhere() : void
    {
        $table = $this->createCachedTableWithProducts();
        $products = $table->loadAll(CacheMode::USE_CACHE);
        $deletedId = RecordIds::getString($products[0]);
        $db = ConnectionHandler::getDb();
        $db->query("DELETE FROM {$db->escapeIdentifier("product")} WHERE \"uuid\" = {$db->escapeValue($deletedId)}");

        $table->loadAll(CacheMode::USE_CACHE);
        $fromTheCache = $table->loadAll(CacheMode::USE_CACHE_IF_COMPLETE);

        if (count($fromTheCache) !== 1 || RecordIds::getString($fromTheCache[0]) === $deletedId)
        {
            throw new \Exception("loadAll() kept the cached object of a row deleted behind the cache's back.");
        }
    }


    private function testSkippedBatchSaveRowClearsIt() : void
    {
        $db = ConnectionHandler::getDb();
        $db->query("TRUNCATE {$db->escapeIdentifier("tag")}");
        $table = TagTable::getInstance();
        $table->disableCache();
        $table->enableCache();
        $table->insert(['id' => 'kept', 'name' => 'kept']);
        $table->loadAll(CacheMode::USE_CACHE_IF_COMPLETE);

        $db->query(
            'CREATE FUNCTION "skip_tag_insert"() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RETURN NULL; END; $$;' .
            ' CREATE TRIGGER "skip_tag_insert" BEFORE INSERT ON "tag" FOR EACH ROW EXECUTE FUNCTION "skip_tag_insert"()'
        );

        try
        {
            $table->batchSave(TagRecord::createFromArray(['id' => 'skipped', 'name' => 'skipped']));
        }
        finally
        {
            $db->query('DROP TRIGGER "skip_tag_insert" ON "tag"; DROP FUNCTION "skip_tag_insert"()');
        }

        // the skipped row's object is cached although the row doesn't exist, so the cache can't be trusted.
        if ($table->isCacheComplete() || count($table->loadAll(CacheMode::USE_CACHE_IF_COMPLETE)) !== 1)
        {
            throw new \Exception("A batchSave() row that a trigger skipped left the cache saying it held every row.");
        }
    }


    private function testOtherLoadsActAsUseCache() : void
    {
        $table = $this->createCachedTableWithProducts();
        $product = $table->loadAll(CacheMode::USE_CACHE_IF_COMPLETE)[0];
        $product->setName('unsaved change');
        $id = RecordIds::get($product);

        $loaded = [
            "load()" => $table->load($id, CacheMode::USE_CACHE_IF_COMPLETE),
            "loadIds()" => $table->loadIds([$id], CacheMode::USE_CACHE_IF_COMPLETE)[$id],
            "loadWhereAnd()" => $table->loadWhereAnd(['uuid' => $id], CacheMode::USE_CACHE_IF_COMPLETE)[0],
        ];

        foreach ($loaded as $method => $object)
        {
            if ($object !== $product || $object->getName() !== 'unsaved change')
            {
                throw new \Exception("{$method} with USE_CACHE_IF_COMPLETE didn't return the cached object as it is.");
            }
        }
    }


    /**
     * Check the cache still holds every row, and that loadAll() gives exactly the rows the database has.
     */
    private function assertCompleteAndMatchesTheDatabase(ProductTable $table, string $afterWhat) : void
    {
        $fromTheCache = $this->getIds($table->loadAll(CacheMode::USE_CACHE_IF_COMPLETE));
        $fromTheDatabase = $this->getIds($table->loadAll(CacheMode::IGNORE_CACHE));

        if ($table->isCacheComplete() === false)
        {
            throw new \Exception("After {$afterWhat}, the cache no longer said it held every row.");
        }

        if ($fromTheCache !== $fromTheDatabase)
        {
            $cached = implode(", ", $fromTheCache);
            $stored = implode(", ", $fromTheDatabase);
            throw new \Exception("After {$afterWhat}, the cache held [{$cached}] but the table holds [{$stored}].");
        }
    }


    private function createCachedTableWithProducts() : ProductTable
    {
        $db = ConnectionHandler::getDb();
        $db->query("TRUNCATE {$db->escapeIdentifier("product")}");
        $table = ProductTable::getInstance();
        $table->disableCache();
        $table->enableCache();
        $table->batchInsert([['name' => 'one'], ['name' => 'two']]);
        $table->emptyCache();
        return $table;
    }


    /**
     * Insert a product with raw SQL, so the cache doesn't know about it.
     */
    private function insertBehindTheCache(string $name) : void
    {
        $db = ConnectionHandler::getDb();
        $id = ProductTable::getInstance()->generateId();
        $values = "{$db->escapeValue($id)}, {$db->escapeValue($name)}";
        $db->query("INSERT INTO \"product\" (\"uuid\", \"name\") VALUES ({$values})");
    }


    /**
     * Get the IDs of the objects, sorted, as loadAll() without a SortBy doesn't promise an order.
     * @param array<ProductRecord> $products
     * @return array<string>
     */
    private function getIds(array $products) : array
    {
        $ids = [];

        foreach ($products as $product)
        {
            $ids[] = RecordIds::getString($product);
        }

        sort($ids);
        return $ids;
    }


    /**
     * Sort the objects by ID, so two lists from loadAll() can be compared.
     * @param array<ProductRecord> $products
     * @return array<ProductRecord>
     */
    private function sortById(array $products) : array
    {
        $compareIds = function (ProductRecord $a, ProductRecord $b) : int {
            return RecordIds::getString($a) <=> RecordIds::getString($b);
        };

        usort($products, $compareIds);
        return $products;
    }
}
