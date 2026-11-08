# Tables and records

For each table, you write two classes:

- A **table class**, ending in `Table`, for operations on the table as a 
  whole, such as loading and inserting rows. Extend `AbstractUuidTable` if the 
  table's IDs are UUIDs, or `AbstractTable` otherwise. Each table class is a 
  singleton, which you get with `getInstance()`.
- A **record class**, ending in `Record`, extending `AbstractRecord`. Each 
  record is one row of the table, as an object, which you can load, change, 
  save and delete.

Then fill in their abstract methods. If you need something rather special, you 
can implement `TableInterface` yourself instead of extending `AbstractTable`, 
but a few features, such as [transactions](transactions.md), need a table that 
extends `AbstractTable`.

The examples on this page are for this table:

```sql
CREATE TABLE "user" (
    "id" uuid PRIMARY KEY,
    "name" varchar(255) NOT NULL,
    "email" varchar(255) NOT NULL,
    "nickname" varchar(255),
    "created_at" timestamptz NOT NULL DEFAULT NOW()
);
```

- [A table class](#a-table-class)
- [A record class](#a-record-class)
- [Required columns, nulls and defaults](#required-columns-nulls-and-defaults)
- [Column types](#column-types)
- [Columns the database generates](#columns-the-database-generates)
- [Schemas](#schemas)
- [Getting a record's values](#getting-a-records-values)


## A table class

```php
use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlObjects\AbstractUuidTable;

class UserTable extends AbstractUuidTable
{
    // the connection to use (see Getting started).
    public function getDb() : PgSqlConnection
    {
        return ConnectionHandler::getDb();
    }


    // the columns that may be left out of a new row, as they allow null.
    public function getFieldsThatAllowNull() : array
    {
        return ['nickname'];
    }


    // the columns that may be left out of a new row, as they have a default.
    public function getFieldsThatHaveDefaults() : array
    {
        return ['created_at'];
    }


    // the columns the database always generates itself (see below).
    public function getVirtualFields() : array
    {
        return [];
    }


    public function getObjectClassName() : string
    {
        return UserRecord::class;
    }


    public function getTableName() : string
    {
        return 'user';
    }
}
```

A table whose IDs aren't UUIDs extends `AbstractTable`, and also says how its 
IDs are generated, with `generateId()`, `canCodeGenerateId()` and 
`canDatabaseGenerateId()` (see [IDs](ids.md)). A table whose ID column isn't 
called `id` overrides `getIdColumnName()`.


## A record class

```php
use Programster\PgsqlObjects\AbstractRecord;
use Programster\PgsqlObjects\TableInterface;

class UserRecord extends AbstractRecord
{
    private ?string $m_name = null;
    private ?string $m_email = null;
    private ?string $m_nickname = null;
    private ?string $m_createdAt = null;


    public function getTableHandler() : TableInterface
    {
        return UserTable::getInstance();
    }


    // a getter for each column, apart from the ID, which the package handles.
    protected function getAccessorFunctions() : array
    {
        return [
            'name'       => function () : ?string { return $this->m_name; },
            'email'      => function () : ?string { return $this->m_email; },
            'nickname'   => function () : ?string { return $this->m_nickname; },
            'created_at' => function () : ?string { return $this->m_createdAt; },
        ];
    }


    // a setter for every column, apart from the ID.
    protected function getSetFunctions() : array
    {
        return [
            'name'       => function (?string $x) : void { $this->m_name = $x; },
            'email'      => function (?string $x) : void { $this->m_email = $x; },
            'nickname'   => function (?string $x) : void { $this->m_nickname = $x; },
            'created_at' => function (?string $x) : void { $this->m_createdAt = $x; },
        ];
    }


    # Accessors
    public function getName() : ?string { return $this->m_name; }
    public function getEmail() : ?string { return $this->m_email; }
    public function getNickname() : ?string { return $this->m_nickname; }
    public function getCreatedAt() : ?string { return $this->m_createdAt; }


    # Setters
    // setters that only set, so that many objects can be changed and saved together (see Saving).
    public function setNickname(?string $nickname) : void
    {
        $this->m_nickname = $nickname;
    }
}
```

**Every column needs a setter** in `getSetFunctions()`, as rows are loaded 
through them: a column the object has no setter for throws an 
`ExceptionUnknownColumn`, which is usually a typo. So if you load rows with 
your own query, select only the table's columns (see 
[Writing your own queries](custom-queries.md)).

`getAccessorFunctions()` lists the columns that are sent to the database when 
the object is saved. Columns the database generates (see below) are never 
sent, even if they're listed.

The public setters are separate from `getSetFunctions()`, and are up to you. 
Keep them to just setting the value, and never saving, so that many objects 
can be changed and then saved together (see [Saving](saving.md)).


## Required columns, nulls and defaults

When you create a new object with `createNewFromArray()`, every column with a 
setter must be given a value, unless it's listed in the table's:

- `getFieldsThatAllowNull()`, as it can be null,
- `getFieldsThatHaveDefaults()`, as the database fills it in,
- or `getVirtualFields()`, as the database always generates it.

Leaving out any other column throws `ExceptionMissingRequiredData`, saying 
which one, before anything is sent to the database.

**When a new object is inserted, a column with a default gets that default** 
if the object has no value for it. Each object decides for itself, so objects 
saved together, e.g. with `batchSave()` or a [Transaction](transactions.md), 
can each give a value for a column or leave it to the default:

- A column the object was **never given**, e.g. `created_at` here, isn't sent, 
  so the database fills in its default.
- A column given a value is sent as it is.
- A column given `null` on purpose, as a key of `createNewFromArray()` or 
  `update()`, is sent as `NULL` if it allows null, e.g. 
  `['nickname' => null]` stores `NULL`, even if `nickname` had a default.
- A `NOT NULL` column that's `null` always gets its default, as `NULL` could 
  never be stored there.

```php
$userTable = UserTable::getInstance();
$withDefault = UserRecord::createNewFromArray(['name' => 'Ada', 'email' => 'ada@example.com']);
$withOwnValue = UserRecord::createNewFromArray([
    'name' => 'Grace',
    'email' => 'grace@example.com',
    'created_at' => '2024-01-01 00:00:00+00',
]);

$userTable->batchSave([$withDefault, $withOwnValue]); // created_at is NOW() for Ada, and 2024 for Grace.
```

This only works for the columns listed in the table's 
`getFieldsThatHaveDefaults()`, so list every column that has one. The package 
can't see your own public setters, so a column set to `null` through one of 
them counts as never given, and gets its default: to store `NULL` in a column 
that has a default, pass it to `createNewFromArray()` or `update()`.

Once an object has been saved, it sends every value, as it's updated rather 
than inserted. However it was saved, with `save()`, `update()`, `batchSave()` 
or a [Transaction](transactions.md), it takes on the values the database 
chose, such as the default `created_at`, so updating it later keeps them.


## Column types

When rows are loaded, integer, float and boolean columns (including integer 
IDs) are converted to their PHP types. Everything else, such as text, dates, 
UUIDs and JSON, stays as the string the database returns.

If converting a column would lose information, such as a high-precision 
`numeric` column becoming a float, list it in your table's 
`getFieldsToKeepAsString()` to keep the exact string instead, e.g. for 
currency amounts you'll handle with bcmath or a decimal library. `money` 
columns are always kept as strings, as PostgreSQL formats them for its locale.

```php
public function getFieldsToKeepAsString() : array
{
    return ['balance'];
}
```


## Columns the database generates

Some columns' values are always computed by PostgreSQL itself, and it rejects 
any value for them: generated columns (`GENERATED ALWAYS AS (...) STORED`, or 
`VIRTUAL` on PostgreSQL 18), and non-ID `GENERATED ALWAYS AS IDENTITY` columns. 
List them in your table's `getVirtualFields()`. They then aren't required when 
creating objects, are never sent in inserts or updates, and are read back from 
the database after `save()`, `update()`, `insert()`, `updateById()`, 
`batchSave()` and a [Transaction](transactions.md).


## Schemas

A table in a schema other than `public` overrides `getSchemaName()`:

```php
public function getSchemaName() : string
{
    return 'billing';
}
```

The names you return from `getTableName()` and `getSchemaName()` are plain 
names, not escaped. The package escapes them, so any characters work, even 
dots and quotes, and it qualifies every query with the schema, rather than 
relying on the connection's `search_path`. Use 
`getEscapedQualifiedTableName()` (e.g. `"billing"."invoice"`) when writing 
your own queries, and `getTable()` for pgsql-lib's query helpers and 
[Constraint](transactions.md#the-constraint-class).


## Getting a record's values

Each object has a getter for each column, from your record class, and `getId()`. 
`getArrayForm()` returns its ID and the values of the columns in 
`getAccessorFunctions()`, keyed by column name. It's the raw values, so filter 
out anything sensitive, such as password hashes, before returning it from an 
API.

`isSavedInDatabase()` tells you whether the object has been saved, and 
`isDeleted()` whether it has been deleted.
