# Documentation

The [README](../README.md) has a quick start. These pages go into the detail.

- [Tables and rows](tables.md): the two classes you write for each table, 
  column types, and columns the database generates.
- [IDs](ids.md): where new rows' IDs come from, and changing an ID.
- [Loading](loading.md): loading by ID or by filter, and pagination.
- [Saving](saving.md): saving one object, many objects of a table with 
  `batchSave()`, inserting rows with `batchInsert()`, and cloning.
- [Transactions](transactions.md): saving and deleting objects of several 
  tables together, in one transaction and one round trip, and how foreign keys 
  and other constraints affect the order.
- [The cache](cache.md): the optional cache, and `CacheMode`.
- [Testing](testing.md): running this package's tests.

See the [changelog](../CHANGELOG.md) for what has changed in each version, 
including what to update when upgrading to a new major version.
