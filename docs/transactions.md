# Transactions

A `Transaction` saves and deletes objects of any of your tables together, in 
one database transaction: either every change is made, or none are.

```php
$author = AuthorRecord::createNewFromArray(['name' => 'Ursula']);
$book = BookRecord::createNewFromArray(['author_id' => $author->getId(), 'title' => 'Earthsea']);

$transaction = new Transaction();
$transaction->save($author);       // before the book, which has a foreign key to it.
$transaction->save($book);
$transaction->delete($oldEdition); // delete rows before the rows they reference.
$transaction->commit();
```

- [How it works](#how-it-works)
- [Constraints and the order of changes](#constraints-and-the-order-of-changes)
- [Making constraints deferrable](#making-constraints-deferrable)
- [IDs the database generates](#ids-the-database-generates)
- [Inside a transaction that's already open](#inside-a-transaction-thats-already-open)
- [Rules](#rules)


## How it works

- `save()` and `delete()` only record the change. **Nothing is sent to the 
  database until `commit()`.**
- `commit()` sends every change as **a single query: one round trip**, however 
  many objects and tables there are. Changes next to each other, of the same 
  kind and table, are combined, as `batchSave()` and `deleteByIds()` would.
- Every object is checked before anything is sent, e.g. that an object being 
  saved hasn't been deleted since it was added.
- PostgreSQL runs the query as one transaction. If any change fails, it's all 
  rolled back, and the exception (usually pgsql-lib's `ExceptionQueryError`) is 
  thrown on.
- Objects are only marked as saved or deleted, and the [cache](cache.md) only 
  updated, **once the transaction has been committed**. So if it fails, every 
  object is left exactly as it was: a new object isn't marked as saved, and 
  doesn't keep an ID the database generated for a row that was rolled back.
- Each object is saved with the values it has when you call `commit()`, not 
  when you added it.


## Constraints and the order of changes

The changes are made in the order you add them. Whether that order matters 
depends on when PostgreSQL checks each constraint.

| Constraint | When it's checked |
|---|---|
| Foreign key, unique or primary key, created normally | As each change is made. This is PostgreSQL's default (`NOT DEFERRABLE`). |
| Foreign key, unique or primary key, created `DEFERRABLE` | Once every change has been made, at the commit, as a `Transaction` defers them. |
| `NOT NULL` and `CHECK` | Always as each change is made. They can't be deferred. |

So with normal constraints, **the order matters**:

- Add a row before any row with a foreign key to it, e.g. an author before 
  their books.
- Delete rows before the rows they reference, e.g. the books before their 
  author.
- Two rows can't swap values of a unique column, as the first change would 
  briefly give both rows the same value.

If a change breaks a normal constraint, the whole transaction fails there, 
even if a later change would have put things right.

With `DEFERRABLE` constraints, **the order doesn't matter**, as long as 
everything is right by the end. A `Transaction` starts by running 
`SET CONSTRAINTS ALL DEFERRED`, so PostgreSQL checks them all at the commit 
instead. `batchSave()` does the same. If one is still broken at the commit, 
the whole transaction is rolled back, as with any other failure.

You'll want deferrable constraints when no order can satisfy them, such as:

- **Rows that reference each other**, e.g. a team whose `owner_id` references a 
  user, whose `team_id` references the team. Whichever is inserted first 
  references a row that doesn't exist yet.
- **Swapping unique values**, e.g. reordering items with a unique `position`, 
  or swapping two users' email addresses.
- **Code that can't easily control the order**, e.g. saving whatever a form or 
  an import changed.

Otherwise, normal constraints and the right order are simpler, and catch a 
mistake at the change that caused it.


## Making constraints deferrable

A constraint is only deferrable if it was created that way. Nothing about the 
table class changes: it's only the database's constraint.

**`DEFERRABLE INITIALLY IMMEDIATE`** is usually what you want. It's checked as 
each change is made, as normal, unless a transaction defers it, as a 
`Transaction` and `batchSave()` do. So everywhere else, a mistake still shows 
straight away. `DEFERRABLE INITIALLY DEFERRED` is always checked at the end of 
the transaction instead.

In SQL, e.g. in a migration:

```sql
CREATE TABLE "book" (
    "id" SERIAL PRIMARY KEY,
    "author_id" uuid NOT NULL REFERENCES "author" ("id") DEFERRABLE INITIALLY IMMEDIATE,
    "title" varchar(255) NOT NULL
);
```

Or with this package's `Constraint`, which takes a `DeferConfig`:

```php
$db = BookTable::getInstance()->getDb();

$foreignKey = Constraint::createForeignKey(
    BookTable::getInstance()->getTable(),
    'author_id',
    'book_author_id_fkey',
    AuthorTable::getInstance()->getTable(),
    'id',
    DeferConfig::INITIALLY_IMMEDIATE,
    $db
);

$db->query($foreignKey->getSqlCreate());
```

`Constraint::createUnique()` and `createPrimary()` take a `DeferConfig` too.

**For an existing table**, find its constraints' names, and whether they're 
deferrable (`contype` is `f` for a foreign key, `p` for the primary key and `u` 
for unique):

```sql
SELECT conname, contype, condeferrable
FROM pg_constraint
WHERE conrelid = '"public"."book"'::regclass AND contype IN ('f', 'p', 'u');
```

A foreign key can be made deferrable in place:

```sql
ALTER TABLE "book" ALTER CONSTRAINT "book_author_id_fkey" DEFERRABLE INITIALLY IMMEDIATE;
```

A unique constraint has to be dropped and added again, which can be done in one 
statement, so there's no moment without it:

```sql
ALTER TABLE "product"
    DROP CONSTRAINT "product_position_key",
    ADD CONSTRAINT "product_position_key" UNIQUE ("position") DEFERRABLE INITIALLY IMMEDIATE;
```

Things to be aware of:

- **A foreign key can't reference a deferrable unique or primary key.** 
  PostgreSQL refuses to create one. So keep the primary keys and unique 
  columns that other tables reference non-deferrable, and make the foreign 
  keys that reference them deferrable instead.
- A deferrable unique constraint can't be used by `INSERT ... ON CONFLICT`, if 
  your own queries rely on one.
- A deferred constraint that fails only fails at the commit, so the error 
  comes from the commit, not the change that caused it. The error message 
  still says which constraint and which values.


## IDs the database generates

A foreign key to a row whose ID the database generates (e.g. `SERIAL`) must 
already be set when you add the object, as the new ID isn't known until the 
transaction runs. So a new author with a `SERIAL` ID and their new books can't 
be saved in the same transaction: save the author first, then the books. IDs 
generated in code, such as UUIDs, are known as soon as the object is created, 
as in the example at the top, so this doesn't come up with them.

New objects whose IDs the database generates can be saved in a transaction, 
and take on their IDs, but this needs PostgreSQL 17 or newer (see 
[IDs](ids.md)). On an older server, the commit throws an 
`ExceptionUnsupportedDatabaseVersion` before anything is sent.


## Inside a transaction that's already open

If a transaction is already open on the connection when you commit, the 
changes are made inside a savepoint within it:

- If a change fails, only these changes are undone, and the open transaction 
  can carry on.
- Nothing is final until the open transaction is committed. If it's rolled 
  back instead, the rows are gone, but the objects are still marked as saved or 
  deleted.
- Deferrable constraints aren't checked until the open transaction is 
  committed, so a broken one only shows then. After a successful commit, the 
  rest of the open transaction defers them too, until it ends.


## Rules

- Every object's table must extend `AbstractTable`, and every table must use 
  the same database connection, as a transaction can only cover one. Adding an 
  object whose table uses a different connection throws 
  `ExceptionInvalidTransaction`.
- Adding the same object again does nothing, so it stays where it was first 
  added. Adding an object both to save and to delete throws 
  `ExceptionInvalidTransaction`, as only one of them could be what was meant.
- Saving an object that has been deleted throws `ExceptionObjectDeleted`. 
  Deleting one that has already been deleted does nothing. Deleting one that 
  was never saved has no row to delete, but it's still marked as deleted.
- A transaction can only be committed once, whether or not it succeeds, and 
  nothing can be added after. Committing one with no changes does nothing.
- It only saves and deletes objects, and doesn't run any other queries.
- The only other query it might make is a one-time fetch of a table's column 
  info, the first time a process saves new objects whose IDs the database 
  generates to that table.
