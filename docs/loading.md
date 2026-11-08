# Loading

Every load takes a `CacheMode`, saying how it uses the [cache](cache.md), if 
it's enabled. With the cache disabled, which is the default, every load gets 
new objects with fresh data from the database, whichever mode you pass.

```php
$table = UserTable::getInstance();

// by ID. load() throws ExceptionNoSuchIdException if there's no such row.
$user = $table->load($id);
$users = $table->loadIds([$id1, $id2]); // indexed by their IDs.

// by filter: rows that have all of the values, or any of them.
$admins = $table->loadWhereAnd(['role' => 'admin', 'active' => true], CacheMode::USE_CACHE);
$someUsers = $table->loadWhereOr(['id' => [$id1, $id2], 'role' => 'admin'], CacheMode::USE_CACHE);

// everything, or everything but some IDs.
$everyone = $table->loadAll(CacheMode::USE_CACHE);
$others = $table->loadIdsNotIn([$id1], CacheMode::USE_CACHE);
```

A value in a filter can be an array, to match any of those values, e.g. 
`'id' => [1, 2, 3]`.

Some safety checks:

- A filter must not be empty, as that would load every row: use `loadAll()` 
  if that's what you mean. An empty filter throws 
  `ExceptionMissingRequiredData`.
- A column name that isn't one of the table's columns throws 
  `ExceptionUnknownColumn` before any query runs, as it's usually a typo.


## Pagination

There are two ways to page through a table:

- `loadRange($offset, $numElements, $cacheMode, $orderByColumn)` loads a page 
  by offset, ordered by the ID column by default, or by the column you pass, 
  with the ID column as a tiebreaker. Rows inserted or deleted before the 
  current page will shift later pages.
- `loadAfterId($afterId, $numElements, $cacheMode)` loads the rows whose IDs 
  come after `$afterId`, in ID order. Pass `null` for the first page, then the 
  ID of the last object on each page to load the next, until an empty page is 
  returned. This never skips or repeats rows when rows are inserted, updated, 
  or deleted between pages, and stays fast however deep into the table you go, 
  so prefer it for processing every row in a table.

```php
$afterId = null;

do
{
    $page = $table->loadAfterId($afterId, 500, CacheMode::IGNORE_CACHE);

    foreach ($page as $user)
    {
        // ...
        $afterId = $user->getId();
    }
}
while (count($page) > 0);
```
