# Constraints

The `Constraint` class builds the SQL to add and drop primary key, unique and 
foreign key constraints, with the names escaped for you, e.g. for use in 
migrations. It doesn't run anything itself: it gives you the SQL to run.

```php
use Programster\PgsqlObjects\Constraint;
use Programster\PgsqlObjects\DeferConfig;

$db = ConnectionHandler::getDb();

$uniqueEmail = Constraint::createUnique(
    UserTable::getInstance()->getTable(),
    'user_email_key',
    ['email'],
    DeferConfig::NOT_DEFERRABLE,
    $db
);

$db->query($uniqueEmail->getSqlCreate()); // ALTER TABLE "public"."user" ADD CONSTRAINT ...

// and to undo it, e.g. in a migration's down():
$db->query($uniqueEmail->getSqlDelete()); // ALTER TABLE "public"."user" DROP CONSTRAINT ...
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
other than `public`, and a foreign key can reference a table in a different 
schema from its own.

A `Constraint` also has `getName()`, `getTable()` and `getTableName()`.


## Deferrable constraints

Every kind takes a `DeferConfig`, saying whether the constraint can be 
deferred to the end of a transaction:

| `DeferConfig` | SQL | Checked |
|---|---|---|
| `NOT_DEFERRABLE` | `NOT DEFERRABLE` | As each change is made, always. PostgreSQL's default. |
| `INITIALLY_IMMEDIATE` | `DEFERRABLE INITIALLY IMMEDIATE` | As each change is made, unless the transaction defers it. |
| `INITIALLY_DEFERRED` | `DEFERRABLE INITIALLY DEFERRED` | At the end of the transaction, always. |

A [Transaction](transactions.md) and `batchSave()` defer every deferrable 
constraint, so with `INITIALLY_IMMEDIATE`, the order of their changes doesn't 
matter for that constraint, while everywhere else, a mistake still shows 
straight away. [Transactions](transactions.md#constraints-and-the-order-of-changes) 
explains when you'd want that, and how to change an existing constraint.

A foreign key can't reference a deferrable primary key or unique constraint: 
PostgreSQL refuses to create one. So keep the keys other tables reference 
`NOT_DEFERRABLE`, and make the foreign keys that reference them deferrable 
instead.
