# Saving

There are several ways to save, depending on how much you're saving at once:

- [One object](#one-object), with its `update()` or `save()`.
- [Many objects of one table](#many-objects-of-one-table), with the table's 
  `batchSave()`.
- [Many new rows of one table](#many-new-rows-of-one-table), from arrays, with 
  the table's `batchInsert()`.
- Objects of several tables, and deletes, together, with a 
  [Transaction](transactions.md).

For deleting, see [Deleting](deleting.md).


## One object

Call an object's `update()` with the new values, e.g. from a form. It always 
saves the object to the database straight away: it updates the row if the 
object has been saved before, or inserts it if it hasn't (e.g. a new object 
from `createNewFromArray()`). Every key is checked first, so a typo throws an 
`ExceptionUnknownColumn` without changing anything.

```php
$user = UserTable::getInstance()->load($id);
$user->update(['name' => $_POST['name'], 'email' => $_POST['email']]);
```

`save()` does the same with the values the object already has:

```php
$user = UserRecord::createNewFromArray(['name' => 'Ada', 'email' => 'ada@example.com']);
$user->save(); // inserts it, as it has never been saved.
```

Afterwards, the object takes on the values the database returned, such as a 
`SERIAL` ID, column defaults and generated columns.

Once an object has been deleted, its `save()` and `update()` throw 
`ExceptionObjectDeleted`, so its row can't be inserted again by mistake. Clone 
it to save it as a new row.


## Many objects of one table

Change each object with its setters, which only change the object in memory, 
then pass them all to the table's `batchSave()`, which saves them in a single 
query. The query runs as one transaction, so either every object is saved, or 
none are.

```php
$table = ProductTable::getInstance();
$products = $table->loadWhereAnd(['category' => 'books'], CacheMode::USE_CACHE);

foreach ($products as $product)
{
    $product->setPrice($product->getPrice() * 2);
}

$table->batchSave($products);
```

So that you can do this, give your row classes setters that only set the 
value, and never call `save()`:

```php
class ProductRecord extends AbstractTableRowObject
{
    // ...

    # Setters
    public function setPrice(int $price) : void
    {
        $this->m_price = $price;
    }
}
```

A method that also saves isn't really a setter any more, and it would make 
`batchSave()` pointless, as each change would already have run its own query. 
If you want a one-step change and save for a single object, call `update()` 
instead, e.g. `$product->update(['price' => 200])`. If you really need a setter 
that can save, give it a parameter to choose, e.g. `setPrice(200, save: false)`, 
but keeping setters to just setting is simpler and less surprising.

Things to be aware of with `batchSave()`:

- New objects whose IDs the database generates are inserted with the rest, and 
  take on their generated IDs, but this needs PostgreSQL 17 or newer (see 
  [IDs](ids.md)).
- For objects that already have an ID, it can't read back values the database 
  generates, such as column defaults or generated columns, so they will be out 
  of date on those objects until you reload them with 
  `CacheMode::UPDATE_CACHE`. New objects without an ID do take them on.
- Deleted objects are rejected before anything is saved.
- Constraints created `DEFERRABLE` are only checked once every object has been 
  saved (see [Transactions](transactions.md#constraints-and-the-order-of-changes)).
- If a transaction is already open, it all runs within it.


## Many new rows of one table

The table's `batchInsert($rows)` inserts many rows from arrays in a single 
query, and returns the objects for them, with any values the database 
generated, such as `SERIAL` IDs, column defaults and generated columns. This 
works whatever generates the IDs, on any version of PostgreSQL. Rows can leave 
out the ID, and any column that has a default or allows null. The objects 
aren't necessarily in the same order as the rows.

```php
$products = ProductTable::getInstance()->batchInsert([
    ['name' => 'Pen', 'price' => 150],
    ['name' => 'Ink', 'price' => 300],
]);
```

An empty list throws `ExceptionMissingRequiredData`.


## Cloning

Cloning an object gives you a new, unsaved copy, which gets a new ID generated 
in code, or one from the database when it is saved. It's only stored if you 
save it. The clone of a deleted object isn't deleted, so it can be saved as a 
new row.
