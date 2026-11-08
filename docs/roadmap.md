# Roadmap

Things we'd like to explore or add in future versions. Nothing here is promised, 
or scheduled, and anything may change once it's looked into properly.


## Better native integration with PostGIS

Many PostgreSQL databases use the [PostGIS](https://postgis.net/) extension, 
for geographic data. The aim is for this package to work seamlessly with it, 
and to be tested against it, so that it stays that way.

**Where it stands.** A first check, with PostGIS 3.6 on PostgreSQL 18, found 
no problems: `geometry` and `geography` columns save and load with every 
method, including `batchSave()` and transactions, from values written as text, 
such as EWKT (`SRID=4326;POINT(-0.1276 51.5072)`), and filters on them work. 
But:

- **Values are read back as hex EWKB**, e.g. 
  `0101000020E6100000DA1B7C613255C0BF...`, PostgreSQL's default text output for 
  geometry, which can't be read without a parser. So a record saved with EWKT 
  holds hex EWKB once it has been saved, as it takes on its row from the 
  database.
- **Spatial queries aren't built in**: the filters only compare for equality, 
  so finding rows within a distance, or inside an area, needs a method of your 
  own (see [Writing your own queries](custom-queries.md)).
- **PostGIS isn't part of the automated tests**, so nothing checks it keeps 
  working.

**Ideas to explore:**

- Running the tests against PostGIS too, with test tables that have 
  `geometry` and `geography` columns, on every supported version.
- A page in the documentation on using PostGIS, including a spatial query 
  method of your own, e.g. with `ST_DWithin` and a GiST index.
- An opt-in setting for a table, to read its spatial columns as EWKT (with 
  `ST_AsEWKT()`), which PostGIS accepts straight back when saving, so a record 
  holds the same form it's saved with. The package already knows which columns 
  are `geometry` or `geography` from their types.
- More generally, converting columns of particular types to PHP objects when 
  they're loaded, and back when they're saved, which would also suit `jsonb` 
  and timestamps.
