# Documentation

New to the package? Start with [Getting started](getting-started.md). The 
[README](../README.md) has the requirements and a quick start.

**Guides**
- [Getting started](getting-started.md): connect, create a table, write its 
  classes, and load and save your first rows.
- [Tables and records](tables.md): the two classes for each table, required 
  columns and defaults, column types, generated columns and schemas.
- [Identifiers](identifiers.md): why we strongly recommend using application 
  generated UUIDs over database generated identifiers (`SERIAL` etc).
- [Loading](loading.md): by ID, by filter, and a page at a time.
- [Saving](saving.md): one object, many objects with `batchSave()`, and many 
  rows with `batchInsert()`.
- [Deleting](deleting.md): through the object or the table, and what happens 
  to deleted objects.
- [Transactions](transactions.md): saving and deleting objects of several 
  tables together, and why we recommend 
  [deferrable constraints](transactions.md#deferrable-constraints), so the 
  order of the changes doesn't matter.
- [The cache](cache.md): the optional cache, and `CacheMode`.
- [Writing your own queries](custom-queries.md): table methods of your own, 
  safely escaped, that return objects.

**Reference**
- [Methods](reference.md): every public method, in one place.
- [Exceptions](exceptions.md): what each one means, and when it's thrown.

**Project**
- [Changelog](../CHANGELOG.md): what has changed in each version, and how to 
  upgrade.
- [Testing](testing.md): running this package's tests.
- [Roadmap](roadmap.md): what we'd like to explore next, such as better native 
  integration with PostGIS.
