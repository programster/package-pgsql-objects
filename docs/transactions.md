# Transactions

A `Transaction` saves and deletes objects of any of your tables together, in 
one database transaction: either every change is made, or none are.

```php
$author = AuthorRecord::createNewFromArray(['name' => 'Ursula']);
$book = BookRecord::createNewFromArray(['author_id' => $author->getId(), 'title' => 'Earthsea']);

$transaction = new Transaction();
$transaction->save($author);
$transaction->save($book);
$transaction->delete($oldEdition);
$transaction->commit();
```

The author is saved before the book, as the book has a foreign key to it. With 
a deferrable foreign key, the order wouldn't matter (see 
[Deferrable constraints](#deferrable-constraints)).

- [How it works](#how-it-works)
- [Deferrable constraints](#deferrable-constraints): why we recommend them, and 
  how to make yours deferrable
  - [Why it helps](#why-it-helps)
  - [What can be deferred](#what-can-be-deferred)
  - [Which setting to use](#which-setting-to-use)
  - [New tables](#new-tables)
  - [Existing tables](#existing-tables)
  - [The Constraint class](#the-constraint-class)
  - [Things to be aware of](#things-to-be-aware-of)
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
- After the commit, each saved object takes on its row from the database, as 
  with `save()`, including any values the database filled in, such as its 
  generated ID, column defaults and generated columns. Every insert and update 
  returns its row in the same query, so this needs no extra round trips.
- Each object is saved with the values it has when you call `commit()`, not 
  when you added it.


## Deferrable constraints

The changes are made in the order you add them, and how much that order 
matters depends on your database's constraints, and whether they're 
**deferrable**. **We recommend making your foreign keys deferrable**, with 
`DEFERRABLE INITIALLY IMMEDIATE`, so that the order of a transaction's changes 
doesn't matter. This section explains why, and how, for new tables and 
existing ones, including with this package's `Constraint` class. It applies to 
`batchSave()` too, which defers constraints in the same way.

### Why it helps

PostgreSQL normally checks a foreign key, unique or primary key constraint as 
each change is made. So when you save many objects together, **the order 
matters**: every change has to leave the database valid, not just the last 
one. A book saved before its new author fails, even if the author is saved a 
moment later in the same transaction:

```php
$author = AuthorRecord::createNewFromArray(['name' => 'Ursula']);
$book = BookRecord::createNewFromArray(['author_id' => $author->getId(), 'title' => 'Earthsea']);

$transaction = new Transaction();
$transaction->save($book);   // fails here, with a normal foreign key: the author doesn't exist yet.
$transaction->save($author);
$transaction->commit();
```

The whole transaction is then rolled back, so nothing is lost, but you have to 
work out the right order yourself, and keep it right as the code changes:

- add rows before the rows that reference them,
- delete rows before the rows they reference,
- and save related objects of several tables in an order that suits every 
  foreign key between them.

Sometimes there's no order that works at all:

- **Rows that reference each other**, e.g. a team whose `owner_id` references a 
  user, whose `team_id` references the team. Whichever is saved first 
  references a row that doesn't exist yet.
- **Swapping unique values**, e.g. reordering items with a unique `position`, 
  or swapping two users' email addresses. The first change briefly gives two 
  rows the same value.

**A deferrable constraint can be checked once, at the end of the transaction, 
instead.** Every `Transaction`, and every `batchSave()`, starts by running 
`SET CONSTRAINTS ALL DEFERRED`, so with deferrable constraints, **the order 
doesn't matter**: the example above just works, and so do the cases with no 
working order, as long as everything is right by the end. If something is 
still wrong then, e.g. a book whose author is never saved, the commit fails, 
and the whole transaction is rolled back, as with any other failure.

This matters most when the order is hard to control, such as code that saves 
whatever a form or an import changed, or a `Transaction` built up by several 
parts of your application.


### What can be deferred

| Constraint | Can be deferred? |
|---|---|
| Foreign key | Yes, if it's created `DEFERRABLE`. |
| Unique, and primary key | Yes, if it's created `DEFERRABLE`, but see [Things to be aware of](#things-to-be-aware-of). |
| `NOT NULL` and `CHECK` | No. They're always checked as each change is made. |

Constraints aren't deferrable unless they're created that way: PostgreSQL's 
default is `NOT DEFERRABLE`, and `SET CONSTRAINTS ALL DEFERRED` quietly has no 
effect on those. Nothing in your table or record classes changes: it's only the 
database's constraint.


### Which setting to use

A deferrable constraint also says when it's checked, unless a transaction says 
otherwise:

| SQL | `DeferConfig` | Checked |
|---|---|---|
| `NOT DEFERRABLE` | `NOT_DEFERRABLE` | As each change is made, always. PostgreSQL's default. |
| `DEFERRABLE INITIALLY IMMEDIATE` | `INITIALLY_IMMEDIATE` | As each change is made, unless the transaction defers it, as `Transaction` and `batchSave()` do. |
| `DEFERRABLE INITIALLY DEFERRED` | `INITIALLY_DEFERRED` | At the end of the transaction, always. |

**`DEFERRABLE INITIALLY IMMEDIATE` is usually what you want.** The package's 
batches defer it, so their order doesn't matter, but everywhere else, such as 
a single `save()` or a query of your own, it's checked straight away, so a 
mistake still shows at the change that caused it.


### New tables

Add `DEFERRABLE INITIALLY IMMEDIATE` to the constraint, e.g. in a migration:

```sql
CREATE TABLE "book" (
    "id" uuid PRIMARY KEY,
    "author_id" uuid NOT NULL REFERENCES "author" ("id") DEFERRABLE INITIALLY IMMEDIATE,
    "title" varchar(255) NOT NULL
);
```

Or add it afterwards, with the [Constraint class](#the-constraint-class).


### Existing tables

First, find the table's constraints, and whether they're deferrable 
(`contype` is `f` for a foreign key, `p` for the primary key and `u` for 
unique):

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


### The Constraint class

The `Constraint` class builds the SQL to add and drop primary key, unique and 
foreign key constraints, with the names escaped for you, and takes a 
`DeferConfig`, so it's easy to make them deferrable, e.g. in migrations. It 
doesn't run anything itself: it gives you the SQL to run.

```php
use Programster\PgsqlObjects\Constraint;
use Programster\PgsqlObjects\DeferConfig;

$db = ConnectionHandler::getDb();

$foreignKey = Constraint::createForeignKey(
    BookTable::getInstance()->getTable(),
    'author_id',
    'book_author_id_fkey',
    AuthorTable::getInstance()->getTable(),
    'id',
    DeferConfig::INITIALLY_IMMEDIATE,
    $db
);

$db->query($foreignKey->getSqlCreate()); // ALTER TABLE "public"."book" ADD CONSTRAINT ...

// and to undo it, e.g. in a migration's down():
$db->query($foreignKey->getSqlDelete()); // ALTER TABLE "public"."book" DROP CONSTRAINT ...
```

The three kinds:

```php
Constraint::createPrimary($table, $constraintName, $columnNames, $deferConfig, $db);
Constraint::createUnique($table, $constraintName, $columnNames, $deferConfig, $db);

Constraint::createForeignKey(
    $table,
    $columnName,
    $constraintName,
    $referencedTable,
    $referencedColumn,
    $deferConfig,
    $db
);
```

A table can be given as a name, which PostgreSQL looks up using the 
connection's `search_path`, or as a pgsql-lib `Table`, from a table class's 
`getTable()`, which includes its schema. Use a `Table` for a table in a schema 
other than `public`. A foreign key can reference a table in a different schema 
from its own.

A `Constraint` also has `getName()`, `getTable()` and `getTableName()`.

To change an existing foreign key to be deferrable, use the `ALTER CONSTRAINT` 
in [Existing tables](#existing-tables), rather than dropping it and adding it 
again.


### Things to be aware of

- **A foreign key can't reference a deferrable unique or primary key.** 
  PostgreSQL refuses to create one. So keep the primary keys and unique 
  columns that other tables reference non-deferrable, and make the foreign 
  keys that reference them deferrable instead. That covers the most common 
  case, saving related rows in any order.
- A deferrable unique constraint can't be used by `INSERT ... ON CONFLICT`, if 
  your own queries rely on one.
- A deferred constraint that's broken only fails at the commit, so the error 
  comes from the commit, not the change that caused it. The error message 
  still says which constraint, and which values.
- Inside a transaction that's already open, deferred constraints aren't 
  checked until that transaction is committed (see 
  [below](#inside-a-transaction-thats-already-open)).


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
