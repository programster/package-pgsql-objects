# Tables and rows

For each table, you write two classes:

- A **table class**, ending in `Table`, for operations on the table as a 
  whole, such as loading and inserting rows. Extend `AbstractUuidTable` if the 
  table's IDs are UUIDs, or `AbstractTable` otherwise. Each table class is a 
  singleton, which you get with `getInstance()`.
- A **row class**, for one row of the table, extending 
  `AbstractTableRowObject`. Each object is one row.

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
- [A row class](#a-row-class)
- [Required columns, nulls and defaults](#required-columns-nulls-and-defaults)
- [Column types](#column-types)
- [Columns the database generates](#columns-the-database-generates)
- [Schemas](#schemas)
- [Getting an object's values](#getting-an-objects-values)


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


## A row class

```php
use Programster\PgsqlObjects\AbstractTableRowObject;
use Programster\PgsqlObjects\TableInterface;

class UserRecord extends AbstractTableRowObject
{
    private ?string $m_name = null;
    private ?string $m_email = null;
    private ?string $m_nickname = null;
    private ?string $m_createdAt = null;


    public function getTableHandler() : TableInterface
    {
        return UserTable::getInstance();
    }


    // a getter for each column to send to the database, apart from the ID, which the package handles.
    protected function getAccessorFunctions() : array
    {
        $accessors = [
            'name'     => function () : ?string { return $this->m_name; },
            'email'    => function () : ?string { return $this->m_email; },
            'nickname' => function () : ?string { return $this->m_nickname; },
        ];

        // leave created_at out until it has a value, so the database's default applies (see below).
        if ($this->m_createdAt !== null)
        {
            $accessors['created_at'] = function () : ?string { return $this->m_createdAt; };
        }

        return $accessors;
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

**A column with a default must be left out of `getAccessorFunctions()` while 
its value is null**, as `created_at` is above. A row object sends every column 
its accessors return, so it would otherwise send `NULL`, which PostgreSQL 
stores instead of using the default, or rejects if the column is `NOT NULL`. 
Once the object has been saved, it takes on the value the database chose, so 
the accessor is included from then on.


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
the database after `save()`, `update()`, `insert()` and `updateById()`.

`batchSave()` can't read them back for objects that already have an ID, so 
reload those objects with `CacheMode::UPDATE_CACHE` if you need the new 
values.


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
[Constraint](constraints.md).


## Getting an object's values

Each object has a getter for each column, from your row class, and `getId()`. 
`getArrayForm()` returns its ID and the values of the columns in 
`getAccessorFunctions()`, keyed by column name, so a column left out there 
until it has a value (like `created_at` above) is left out here too. It's the 
raw values, so filter out anything sensitive, such as password hashes, before 
returning it from an API.

`isSavedInDatabase()` tells you whether the object has been saved, and 
`isDeleted()` whether it has been deleted.
