# Writing your own queries

The built-in loads cover loading by ID and by simple filters. For anything 
else, such as comparisons other than equals, joins, ordering, or full-text 
search, write your own method on the table class. It gets the rows with its own 
query, and turns them into objects with `convertPgResultToObjects()`, so they 
work like any other loaded object, including the [cache](cache.md).

```php
use Programster\PgsqlObjects\CacheMode;

class UserTable extends AbstractUuidTable
{
    // ...

    /**
     * Load the users who signed up within the last $days days, newest first.
     *
     * @param int $days
     *
     * @param CacheMode $cacheMode - how to use the cache, when it's enabled.
     *
     * @return array<UserRecord>
     */
    public function loadRecentSignUps(int $days, CacheMode $cacheMode) : array
    {
        $db = $this->getDb();
        $createdAt = $db->escapeIdentifier('created_at');

        $query =
            "SELECT * FROM {$this->getEscapedQualifiedTableName()}" .
            " WHERE {$createdAt} > NOW() - " . $db->escapeValue("{$days} days") . "::interval" .
            " ORDER BY {$createdAt} DESC";

        $result = $db->query($query);
        return $this->convertPgResultToObjects($result, $cacheMode);
    }
}
```

Things to get right:

- **Select only the table's columns**, e.g. `SELECT * FROM` the table, or 
  `SELECT "user".* FROM "user" JOIN ...`. A column the row class has no setter 
  for throws `ExceptionUnknownColumn`, and every row must include the ID.
- **Escape everything you put into the query**: values with 
  `$db->escapeValue()` (or `escapeValues()` for a list), and names with 
  `$db->escapeIdentifier()`. Never put a value straight into the SQL.
- **Use `getEscapedQualifiedTableName()`** for the table, e.g. 
  `"public"."user"`, so the query uses the table's own schema, as the 
  package's queries do, rather than the connection's `search_path`.
- **Take a `CacheMode`**, and pass it on, so whoever calls your method chooses 
  how it uses the cache, just like the built-in loads.

`$db->query()` throws pgsql-lib's `ExceptionQueryError` if the query fails, 
with PostgreSQL's error message, so there's no `false` result to check.

`convertPgResultToObjects()` is protected, so it can only be used from within 
the table class. That's deliberate: queries for a table belong on its table 
class, where the rest of the code can find them.


## Writes

For writes, prefer the package's own methods, such as `save()`, `batchSave()`, 
the deletes and [Transaction](transactions.md), as they keep the objects and 
the cache in step with the database. A write of your own, e.g. an `UPDATE` 
of many rows at once, leaves any objects you're already holding for those 
rows, and the cached ones, with their old values. Reload them with 
`CacheMode::UPDATE_CACHE` afterwards if you need the new values, or if the rows 
were deleted, empty the table's cache with `emptyCache()`.


## Your own transactions

If you open a transaction yourself, the package's queries run inside it, and 
are only final once you commit it:

```php
$db = ConnectionHandler::getDb();
$db->query("BEGIN");

UserTable::getInstance()->batchSave($changedUsers);
$db->query("UPDATE ...");

$db->query("COMMIT");
```

A [Transaction](transactions.md) committed inside your own transaction uses a 
savepoint, so a failure only undoes its own changes. In either case, if your 
transaction is rolled back, the objects stay marked as saved or deleted, as 
they were when their part succeeded, so reload anything you still need.
