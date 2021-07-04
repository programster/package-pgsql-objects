PostgreSQL Objects Package
==========================

This package aims to act as your object-relational-map to a PostgreSQL database.

To use this package, we recommend you create two class files for each of your tables.
One should end in the word `Table` and represent operations on the table itself.
This should ideally extend the `AbstractTable` in the package.
If you need to create something rather special, then one can just implement the `TableInterface` interface.
Once you have extended the class, all you have to do is fill in all of the abstract methods.

The second class you need to create is for a row/object of the table. This class should extend the `AbstractTableRowObject`.
Again, just fill in all of the abstract methods for the package to be able to manage your tables.


### Limitations
This package only works with tables that have an `id` column of type `uuid`.

### Key Features
- Save/updates objects to the database for you when you want it to.
- implement an automatica local cache, so you don't re-fetch objects when you don't need to.
E.g. if you make a call to MyTable::loadAll() before then trying to load a bunch of objects by ID, then the objects will be returned immediately without having to hit the database because they were all loaded into the cache.
- Supports cloning of the objects - will generate a new UUID for the clone and mark it as not being in the database. It will only be persisted if you save it to the database.

## Testing
If you wish to create some changes and run the tests, simply fill in you PostgreSQL host details into the `/testing/settings.php.tmpl` file and rename it to `/testing/settings.php`.

Then run the tests with:

```bash
php testing/main.php
```


