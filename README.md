PgSQL Objects: the PHP ORM built for PostgreSQL
===============================================

<!-- These badges show the 5.x branch's tests. Change "5.x" to "main" in them when 5.x is merged into main. -->
[![Linux tests](https://github.com/programster/package-pgsql-objects/actions/workflows/tests.yml/badge.svg?branch=5.x)](https://github.com/programster/package-pgsql-objects/actions/workflows/tests.yml?query=branch%3A5.x)
[![Windows tests](https://github.com/programster/package-pgsql-objects/actions/workflows/tests-windows.yml/badge.svg?branch=5.x)](https://github.com/programster/package-pgsql-objects/actions/workflows/tests-windows.yml?query=branch%3A5.x)
[![macOS tests](https://github.com/programster/package-pgsql-objects/actions/workflows/tests-macos.yml/badge.svg?branch=5.x)](https://github.com/programster/package-pgsql-objects/actions/workflows/tests-macos.yml?query=branch%3A5.x)
[![WSL tests](https://github.com/programster/package-pgsql-objects/actions/workflows/tests-wsl.yml/badge.svg?branch=5.x)](https://github.com/programster/package-pgsql-objects/actions/workflows/tests-wsl.yml?query=branch%3A5.x)

## Contents

**On this page:** [About](#about) · [Requirements](#requirements) · 
[Quick start](#quick-start) · [Features](#features)

**Guides**
- [Getting started](docs/getting-started.md): connect, create a table, write 
  its classes, and load and save your first rows.
- [Tables and records](docs/tables.md): the two classes for each table, required 
  columns and defaults, column types, generated columns and schemas.
- [Identifiers](docs/identifiers.md): why we strongly recommend UUIDs over 
  counting integers, and where new rows' IDs come from.
- [Loading](docs/loading.md): by ID, by filter, and a page at a time.
- [Saving](docs/saving.md): one object, many objects with `batchSave()`, and 
  many rows with `batchInsert()`.
- [Deleting](docs/deleting.md): through the object or the table, and what 
  happens to deleted objects.
- [Transactions](docs/transactions.md): saving and deleting objects of several 
  tables together, and why we recommend 
  [deferrable constraints](docs/transactions.md#deferrable-constraints), so the 
  order of the changes doesn't matter.
- [The cache](docs/cache.md): the optional cache, and `CacheMode`.
- [Writing your own queries](docs/custom-queries.md): table methods of your 
  own, safely escaped, that return objects.

**Reference**
- [Methods](docs/reference.md): every public method, in one place.
- [Exceptions](docs/exceptions.md): what each one means, and when it's thrown.

**Project**
- [Changelog](CHANGELOG.md): what has changed in each version, and how to 
  upgrade.
- [Testing](docs/testing.md): running this package's tests.


## About

PgSQL Objects is an object-relational mapper (ORM) for PHP that only works 
with PostgreSQL, **on purpose**. An ORM that supports many databases can only 
rely on what they all have in common. By being specific to PostgreSQL, this 
one is built around what PostgreSQL does well, and optimised for it:

- **Every write returns its row** (`RETURNING`), so records take on their 
  generated IDs, column defaults and generated columns, with no extra query.
- **Batches and transactions are a single round trip**: many inserts, updates 
  and deletes, across several tables, are sent as one query, which PostgreSQL 
  runs as one transaction.
- **New rows whose IDs the database generates are batch inserted** with one 
  `MERGE ... RETURNING` (PostgreSQL 17+), and matched back to their records.
- **Deferrable constraints are deferred** for every batch and transaction, so 
  the order of the changes doesn't matter.
- **PostgreSQL's own types and features**: native `uuid` IDs (time-ordered 
  version 7 by default), generated columns (`STORED`, and `VIRTUAL` on 
  PostgreSQL 18), and values converted to PHP types using each table's column 
  types, read from PostgreSQL's system catalogs.
- **Schemas are first-class**: every query uses the table's schema-qualified 
  name, rather than relying on the connection's `search_path`.

You write a class for each table, and a record class for its rows, and the 
package loads, saves and deletes the rows as records: objects you can work 
with like any other.


## Requirements

- PHP 8.2 or newer, with the `pgsql` extension.
- PostgreSQL 14 or newer. Saving many new objects whose IDs the database 
  generates (e.g. `SERIAL`) in one go needs PostgreSQL 17 or newer.

```bash
composer require programster/pgsql-objects
```

**We strongly recommend UUIDs for your tables' IDs**, rather than integers 
that count up (`SERIAL`): give each table a `uuid` ID column, and extend 
`AbstractUuidTable`, which generates them in code. Then:

- a new record's ID is known as soon as it's created, so related records can 
  reference it, and be saved together in one round trip,
- every feature works on any supported version of PostgreSQL,
- IDs can't be guessed (`/invoices/1042`) or counted to see how big you are,
- and they never clash when you copy or merge data between databases.

The package generates version 7 UUIDs, which are time-ordered, so they're as 
quick to insert into an index as integers. It already requires 
[ramsey/uuid](https://github.com/ramsey/uuid), so you can make one yourself 
the same way, e.g. for a migration or a test:

```php
$id = \Ramsey\Uuid\Uuid::uuid7()->toString(); // e.g. "019a5f3e-8c1b-7d2e-9f4a-3b6c8d0e1f2a"
```

See [Identifiers](docs/identifiers.md) for the full case for UUIDs, their 
costs, and how to use tables whose IDs the database generates.


## Quick start

For each table, write a table class, extending `AbstractUuidTable` if its IDs 
are UUIDs or `AbstractTable` otherwise, and a record class extending 
`AbstractRecord`, then fill in their abstract methods (see 
[Tables and records](docs/tables.md) for a full example, or 
[Getting started](docs/getting-started.md) for a walkthrough). Then:

```php
$userTable = UserTable::getInstance();

// create and save a new row.
$user = UserRecord::createNewFromArray(['name' => 'Ada', 'email' => 'ada@example.com']);
$user->save();

// load a row whose ID you know, e.g. from a form submission, change it, and save the change straight away.
$user = $userTable->load('01a1bc23-57a0-7318-8532-ce41ea306b73');
$user->update(['name' => 'Ada Lovelace']);

// load by a filter, and delete.
$matches = $userTable->loadWhereAnd(['email' => 'ada@example.com'], CacheMode::IGNORE_CACHE);
$userTable->deleteByIds([$user->getId()]);
```


## Features

- **Loading** by ID, by filter, or a page at a time, with values converted to 
  their PHP types. See [Loading](docs/loading.md).
- **Saving** one object straight away with `update()`, or many objects of a 
  table in a single query with `batchSave()`, and inserting many rows with 
  `batchInsert()`. See [Saving](docs/saving.md).
- **Transactions** that save and delete objects of several tables together, 
  all or nothing, in a single round trip, deferring the constraints that can 
  be. See [Transactions](docs/transactions.md).
- **Identifiers**: UUIDs generated in code (recommended), or IDs generated by 
  the database (e.g. `SERIAL`). See [Identifiers](docs/identifiers.md).
- **An optional cache**, off by default, with one object per row while it's 
  on. See [The cache](docs/cache.md).
- **Generated columns**, schemas other than `public`, cloning, and safe 
  defaults: unknown column names, empty filters and saving deleted objects all 
  throw, rather than doing something you didn't mean.
- **Tested** on Linux, macOS, Windows and WSL, against PostgreSQL 14 to 18 
  (see [Testing](docs/testing.md)).

See the [contents](#contents) for the documentation of each.
