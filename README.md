PostgreSQL Objects Package
==========================

<!-- These badges show the 5.x branch's tests. Change "5.x" to "main" in them when 5.x is merged into main. -->
[![Linux tests](https://github.com/programster/package-pgsql-objects/actions/workflows/tests.yml/badge.svg?branch=5.x)](https://github.com/programster/package-pgsql-objects/actions/workflows/tests.yml?query=branch%3A5.x)
[![Windows tests](https://github.com/programster/package-pgsql-objects/actions/workflows/tests-windows.yml/badge.svg?branch=5.x)](https://github.com/programster/package-pgsql-objects/actions/workflows/tests-windows.yml?query=branch%3A5.x)
[![macOS tests](https://github.com/programster/package-pgsql-objects/actions/workflows/tests-macos.yml/badge.svg?branch=5.x)](https://github.com/programster/package-pgsql-objects/actions/workflows/tests-macos.yml?query=branch%3A5.x)
[![WSL tests](https://github.com/programster/package-pgsql-objects/actions/workflows/tests-wsl.yml/badge.svg?branch=5.x)](https://github.com/programster/package-pgsql-objects/actions/workflows/tests-wsl.yml?query=branch%3A5.x)

This package aims to act as your object-relational-map (ORM) to a PostgreSQL 
database. You write a class for each table, and one for its rows, and the 
package loads, saves and deletes the rows as objects for you.


## Requirements

- PHP 8.2 or newer, with the `pgsql` extension.
- PostgreSQL 14 or newer. Saving many new objects whose IDs the database 
  generates in one go needs PostgreSQL 17 or newer (see [IDs](docs/ids.md)).

```bash
composer require programster/pgsql-objects
```


## Quick start

For each table, write a table class, extending `AbstractUuidTable` if its IDs 
are UUIDs or `AbstractTable` otherwise, and a row class extending 
`AbstractTableRowObject`, then fill in their abstract methods (see 
[Tables and rows](docs/tables.md) for a full example). Then:

```php
$users = UserTable::getInstance();

// create and save a new row.
$user = UserRecord::createNewFromArray(['name' => 'Ada', 'email' => 'ada@example.com']);
$user->save();

// load it again, change it, and save the change straight away.
$user = $users->load($user->getId());
$user->update(['name' => 'Ada Lovelace']);

// load by a filter, and delete.
$matches = $users->loadWhereAnd(['email' => 'ada@example.com'], CacheMode::IGNORE_CACHE);
$users->deleteByIds([$user->getId()]);
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

The [documentation](docs/README.md) has the detail of each.


## Changes

See [CHANGELOG.md](CHANGELOG.md) for what has changed in each version, 
including what to update when upgrading to a new major version.


## Testing

The package is tested on Linux, macOS, Windows and WSL, against PostgreSQL 14 
to 18. See [Testing](docs/testing.md) to run the tests yourself.
