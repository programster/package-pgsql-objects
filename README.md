PostgreSQL Objects Package
==========================

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
- [Tables and rows](docs/tables.md): the two classes for each table, required 
  columns and defaults, column types, generated columns and schemas.
- [IDs](docs/ids.md): why we recommend UUIDs, and where new rows' IDs come 
  from.
- [Loading](docs/loading.md): by ID, by filter, and a page at a time.
- [Saving](docs/saving.md): one object, many objects with `batchSave()`, and 
  many rows with `batchInsert()`.
- [Deleting](docs/deleting.md): through the object or the table, and what 
  happens to deleted objects.
- [Transactions](docs/transactions.md): saving and deleting objects of several 
  tables together, and how foreign keys and deferrable constraints affect the 
  order.
- [Constraints](docs/constraints.md): adding primary key, unique and foreign 
  key constraints, deferrable or not.
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

This package aims to act as your object-relational-map (ORM) to a PostgreSQL 
database. You write a class for each table, and one for its rows, and the 
package loads, saves and deletes the rows as objects for you.


## Requirements

- PHP 8.2 or newer, with the `pgsql` extension.
- PostgreSQL 14 or newer. Saving many new objects whose IDs the database 
  generates (e.g. `SERIAL`) in one go needs PostgreSQL 17 or newer.

```bash
composer require programster/pgsql-objects
```

**We recommend giving your tables UUID IDs** (a `uuid` column), and extending 
`AbstractUuidTable`, which generates them in code. Then every feature works on 
any supported version of PostgreSQL, and a new object's ID is known as soon as 
it's created, before it's saved, so related objects can reference it straight 
away and be saved together. The package generates v7 UUIDs, which are 
time-ordered, so they're fast to index. It already requires 
[ramsey/uuid](https://github.com/ramsey/uuid), so you can make one yourself 
the same way, e.g. for a migration or a test:

```php
$id = \Ramsey\Uuid\Uuid::uuid7()->toString(); // e.g. "019a5f3e-8c1b-7d2e-9f4a-3b6c8d0e1f2a"
```

See [IDs](docs/ids.md) for more, including tables whose IDs the database 
generates.


## Quick start

For each table, write a table class, extending `AbstractUuidTable` if its IDs 
are UUIDs or `AbstractTable` otherwise, and a row class extending 
`AbstractTableRowObject`, then fill in their abstract methods (see 
[Tables and rows](docs/tables.md) for a full example, or 
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
- **IDs** generated in code (e.g. v7 UUIDs) or by the database (e.g. 
  `SERIAL`). See [IDs](docs/ids.md).
- **An optional cache**, off by default, with one object per row while it's 
  on. See [The cache](docs/cache.md).
- **Generated columns**, schemas other than `public`, cloning, and safe 
  defaults: unknown column names, empty filters and saving deleted objects all 
  throw, rather than doing something you didn't mean.
- **Tested** on Linux, macOS, Windows and WSL, against PostgreSQL 14 to 18 
  (see [Testing](docs/testing.md)).

See the [contents](#contents) for the documentation of each.
