# Tables and rows

For each table, you write two classes:

- A **table class**, ending in `Table`, for operations on the table as a 
  whole, such as loading and inserting rows. Extend `AbstractUuidTable` if the 
  table's IDs are UUIDs, or `AbstractTable` otherwise. Each table class is a 
  singleton, which you get with `getInstance()`.
- A **row class**, for one row of the table, extending 
  `AbstractTableRowObject`.

Then fill in their abstract methods. If you need something rather special, you 
can implement `TableInterface` yourself instead of extending `AbstractTable`, 
but a few features, such as [transactions](transactions.md), need a table that 
extends `AbstractTable`.


## A table class

```php
use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlObjects\AbstractUuidTable;

class UserTable extends AbstractUuidTable
{
    // the connection to use, e.g. from your own connection handler.
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

A table in a schema other than `public` also overrides `getSchemaName()`. The 
names you return are plain names, not escaped: the package escapes them, and 
qualifies every query with the schema, rather than relying on the connection's 
`search_path`.

A table whose IDs aren't UUIDs extends `AbstractTable`, and also says how its 
IDs are generated, with `generateId()`, `canCodeGenerateId()` and 
`canDatabaseGenerateId()` (see [IDs](ids.md)).


## A row class

```php
use Programster\PgsqlObjects\AbstractTableRowObject;
use Programster\PgsqlObjects\TableInterface;

class UserRecord extends AbstractTableRowObject
{
    private ?string $m_name = null;
    private ?string $m_email = null;


    public function getTableHandler() : TableInterface
    {
        return UserTable::getInstance();
    }


    // a getter for each column, apart from the ID, which the package handles.
    protected function getAccessorFunctions() : array
    {
        return [
            'name'  => function () : ?string { return $this->m_name; },
            'email' => function () : ?string { return $this->m_email; },
        ];
    }


    // a setter for each column, apart from the ID.
    protected function getSetFunctions() : array
    {
        return [
            'name'  => function (?string $x) : void { $this->m_name = $x; },
            'email' => function (?string $x) : void { $this->m_email = $x; },
        ];
    }


    # Accessors
    public function getName() : ?string { return $this->m_name; }
    public function getEmail() : ?string { return $this->m_email; }
}
```

Every column needs a setter, as rows are loaded through them: a column the 
object has no setter for throws an `ExceptionUnknownColumn`, which is usually a 
typo. So if you load rows with your own query, select only the table's 
columns.

To change many objects and then save them together, give the row class public 
setters too, which only set the value (see [Saving](saving.md)).


## Column types

When rows are loaded, integer, float and boolean columns (including integer 
IDs) are converted to their PHP types. Everything else stays as the string the 
database returns.

If converting a column would lose information, such as a high-precision 
`numeric` column becoming a float, list it in your table's 
`getFieldsToKeepAsString()` to keep the exact string instead, e.g. for 
currency amounts you'll handle with bcmath or a decimal library. `money` 
columns are always kept as strings, as PostgreSQL formats them for its locale.


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
