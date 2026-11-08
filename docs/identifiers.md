# Identifiers

Every table needs an identifier (ID) column, which is the primary key: the 
value that picks out one row. Most tutorials, and a lot of existing databases, 
use an integer that counts up, `1`, `2`, `3`..., from a `SERIAL` or 
`IDENTITY` column. **We strongly recommend UUIDs instead**, and this package is 
built around them: extend `AbstractUuidTable`, give the table a `uuid` ID 
column, and the package does the rest.

```sql
CREATE TABLE "user" (
    "id" uuid PRIMARY KEY,
    -- ...
);
```

- [What a UUID is](#what-a-uuid-is)
- [Why UUIDs are better](#why-uuids-are-better)
- [The costs, honestly](#the-costs-honestly)
- [Generating UUIDs yourself](#generating-uuids-yourself)
- [Choosing the version](#choosing-the-version)
- [IDs that aren't valid](#ids-that-arent-valid)
- [Where new rows' IDs come from](#where-new-rows-ids-come-from)
- [Tables whose IDs the database generates](#tables-whose-ids-the-database-generates)
- [Changing an ID](#changing-an-id)


## What a UUID is

A UUID (universally unique identifier) is a 128-bit value, written as 32 hex 
digits in five groups, e.g. `019a5f3e-8c1b-7d2e-9f4a-3b6c8d0e1f2a`. They 
contain so many random bits that, at the rates real applications create rows, 
two being the same is vanishingly unlikely, whoever generates them and 
wherever. (If it ever did happen, the primary key would reject the second row, 
rather than mixing two rows up.) So unlike a counter, a UUID doesn't need 
anything to keep track of which values have been used, and can be made 
anywhere, without asking the database.

There are several versions. This package uses **version 7**, which starts with 
the time it was made, to the millisecond, followed by random bits, so new ones 
sort after older ones. PostgreSQL has a native `uuid` type, which stores one 
in 16 bytes.


## Why UUIDs are better

**They're known before the row is saved.** The package generates a new 
record's UUID in code, as soon as the record is created. So you can use it 
straight away: put it in another record's foreign key, a URL, a log message or 
a queued job, before anything has gone to the database. With an integer, the 
ID doesn't exist until the database inserts the row, so related rows have to 
be saved one after another, waiting for each new ID.

```php
$user = UserRecord::createNewFromArray(['name' => 'Ada', 'email' => 'ada@example.com']);
$project = ProjectRecord::createNewFromArray(['user_id' => $user->getId(), 'name' => 'Engine']);

// both saved together, in one transaction, and one round trip to the database.
$transaction = new Transaction();
$transaction->save($user);
$transaction->save($project);
$transaction->commit();
```

**Everything in this package works with them, on every version of 
PostgreSQL.** Saving many new records at once, with `batchSave()` or a 
[Transaction](transactions.md), works on any supported version. With IDs the 
database generates, it needs PostgreSQL 17 or newer, and records that 
reference a new row can't be saved in the same transaction as it (see 
[below](#tables-whose-ids-the-database-generates)).

**They can't be guessed.** With counting IDs, anyone who sees 
`/invoices/1041` can try `/invoices/1042`. If a single access check is 
missing anywhere, that's someone else's invoice. A UUID gives nothing away 
about the others, so a mistake like that is much harder to exploit. You still 
need the access checks, but guessing is off the table.

**They don't give away your numbers.** A counting ID tells anyone who signs up 
how many users signed up before them, and comparing two IDs a week apart says 
how fast you're growing. Competitors, and the curious, do look.

**They're unique everywhere, not just in one table.** A UUID can't clash with 
any other, so you can:

- copy or merge rows between databases, e.g. from staging to production, or 
  combining two customers' data, without renumbering anything,
- create records offline, on a phone or in a browser, and sync them later,
- create records from several servers, workers or databases at once,
- and tell what a UUID in a log or a support ticket refers to, as it can only 
  be one row.

**There's no sequence to go wrong.** A `SERIAL` column gets its next value from 
a sequence, which isn't part of the table's data. Rows inserted with their own 
IDs, e.g. by an import or a restore, don't move it on, so later inserts fail 
with `duplicate key value violates unique constraint "..._pkey"` until 
someone resets it. Rolled-back inserts leave gaps that confuse people who 
expected `1, 2, 3`. UUIDs have none of that.

**Version 7 keeps them fast.** Older, fully random UUIDs (version 4) are 
inserted all over the primary key's index, which slows inserts on large 
tables. Version 7 UUIDs start with the time, so new rows go at the end of the 
index, just like a counting ID, and are as quick to insert. They also sort 
roughly by when they were made, which is handy for `loadAfterId()` and 
`ORDER BY "id"`.


## The costs, honestly

- **They're bigger:** 16 bytes, against 8 for a `bigint` and 4 for an 
  `integer`, in the table and in every index and foreign key that uses them. 
  For most applications, that's a small fraction of the space their data takes.
- **They're not friendly to read out:** nobody wants to say a UUID down the 
  phone. If people need to refer to something, e.g. an order, give it a short 
  reference of its own as well, such as `ORD-2024-0042`, and keep the UUID as 
  its ID.
- **Version 7 includes when it was made:** anyone with a UUID can tell when 
  its row was created, to the millisecond. That's rarely a secret, as most 
  tables have a `created_at` anyway, but if it is for one of yours, use a fully 
  random version 4 UUID for that table instead (see 
  [Choosing the version](#choosing-the-version)).

For almost every table, the benefits far outweigh these.


## Generating UUIDs yourself

The package generates a new record's UUID for you, so you rarely need to. When 
you do, e.g. in a migration, a seeder or a test, it already requires 
[ramsey/uuid](https://github.com/ramsey/uuid), so generate one the same way 
the package does:

```php
$id = \Ramsey\Uuid\Uuid::uuid7()->toString(); // e.g. "019a5f3e-8c1b-7d2e-9f4a-3b6c8d0e1f2a"

// or through a table, which generates whatever kind of ID that table uses.
$id = UserTable::getInstance()->generateId();
```

On PostgreSQL 18 or newer, the database can make them too, with `uuidv7()`, 
e.g. for rows inserted by SQL of your own. `gen_random_uuid()` makes version 
4 ones, on any supported version.


## Choosing the version

`AbstractUuidTable` generates version 7 UUIDs, unless a table's 
`getUuidVersion()` says otherwise. It returns a `UuidVersion`, with two cases:

| `UuidVersion` | Use it for |
|---|---|
| `V7` | Almost every table. The default. Time-ordered, so quick to insert into the index. |
| `V4` | A table where even the time each row was created must stay private. Fully random, so new rows go all over the index, which makes inserts into a large table slower. |

```php
use Programster\PgsqlObjects\UuidVersion;

class PatientTable extends AbstractUuidTable
{
    public function getUuidVersion() : UuidVersion
    {
        return UuidVersion::V4;
    }

    // ...
}
```

Whichever version a table generates, it loads, updates and deletes rows with 
IDs of **any** version, so a table that already holds v1 or v4 IDs works as it 
is, and new rows get the version you choose.

### If you absolutely need version 1

PostgreSQL's `uuid` type accepts every version, so a column only needs a 
particular one if it has been deliberately restricted, e.g. with 
`CHECK (uuid_extract_version("id") = 1)`. That's rare, and if you have one, 
consider dropping the restriction, so the column can take v7 UUIDs too. If you 
can't, override the table's `generateId()`:

```php
use Ramsey\Uuid\Provider\Node\RandomNodeProvider;
use Ramsey\Uuid\Uuid;

class LegacyDeviceTable extends AbstractUuidTable
{
    // version 1, for a column restricted to it, with a random node rather than this machine's MAC address.
    public function generateId() : string
    {
        return Uuid::uuid1((new RandomNodeProvider())->getNode())->toString();
    }

    // ...
}
```

Why version 1 isn't as good as version 7:

- **It doesn't sort by time.** A v1 UUID starts with the *lowest* bits of its 
  timestamp, which wrap round about every 7 minutes, so new rows go all over 
  the index, as with v4, and inserts into a large table are slower. v7 starts 
  with the time, so new rows go at the end.
- **It can give away the machine that made it.** By default, a v1 UUID ends 
  with the network (MAC) address of the server that generated it. The example 
  above avoids that with a random node, which you should always do.
- **It's an older design.** RFC 9562, the current UUID standard, recommends v7 
  for new systems, and v6 (a reordered v1) only where v1 compatibility matters.

The other versions don't make sense for new rows' IDs: v3 and v5 are hashes of 
a name, so the same name always gives the same UUID, v2 can only make a few 
IDs a minute, and v8 has no standard layout to generate. That's why 
`UuidVersion` only has `V7` and `V4`.


## IDs that aren't valid

On a UUID table, an ID that isn't a UUID, e.g. from a mistyped URL, is caught 
before any query runs: every method that takes an ID throws an 
`ExceptionInvalidId`. So your application can tell a malformed ID from one 
that's well formed but has no row, which throws `ExceptionNoSuchIdException`:

```php
use Programster\PgsqlObjects\Exceptions\ExceptionInvalidId;
use Programster\PgsqlObjects\Exceptions\ExceptionNoSuchIdException;

try
{
    $user = UserTable::getInstance()->load($_GET['id']);
}
catch (ExceptionInvalidId $exception)
{
    // not a UUID at all, e.g. respond with a 400.
}
catch (ExceptionNoSuchIdException $exception)
{
    // a UUID, but there's no user with it, e.g. respond with a 404.
}
```

UUIDs are accepted in either case, and turned into lower case, as PostgreSQL 
returns them, so an upper case UUID still finds its record in the 
[cache](cache.md), and `loadIds()` keys its records by the lower case one. Only 
the standard form is accepted: 32 hex digits in groups of 8, 4, 4, 4 and 12, 
separated by hyphens.

To check an ID yourself, without loading anything, call the table's 
`normalizeId()`, which returns it in its standard form, or throws 
`ExceptionInvalidId`. Tables whose IDs aren't UUIDs accept every ID as it is, 
unless they override `normalizeId()` themselves.


## Where new rows' IDs come from

Each table's ID column is called `id` by default, but you can override your 
table's `getIdColumnName()` to use another name, such as `uuid`.

IDs are only ever generated for new rows: by `createNewFromArray()`, by the 
table's `insert()` and `batchInsert()`, and when a record is cloned. A row 
loaded from the database always keeps its own ID. When a new row has no ID, 
the package chooses where its ID comes from in this order:

1. If `canCodeGenerateId()` returns true, the ID is generated in code by 
   `generateId()`, even if the database could also generate one. 
   `AbstractUuidTable` does this, with version 7 UUIDs.
2. Otherwise, if `canDatabaseGenerateId()` returns true, the ID is left for the 
   database to fill in, e.g. a `SERIAL` or `IDENTITY` column, or a default that 
   produces a new value per row, such as `gen_random_uuid()`. The record has no 
   ID until it is saved, and then picks up the generated one.
3. Otherwise, an exception is thrown, as the ID must be provided.


## Tables whose IDs the database generates

If you have existing tables with `SERIAL` or `IDENTITY` IDs, extend 
`AbstractTable`, and have `canCodeGenerateId()` return false, 
`canDatabaseGenerateId()` return true, and `generateId()` throw 
`ExceptionIdGeneratedInDatabase`. Everything works, with a few limits, as the 
ID isn't known until the row is inserted:

- Saving many new records in one go, with `batchSave()` or a 
  [transaction](transactions.md), inserts them with a single 
  `MERGE ... RETURNING`, which tells the package which generated ID belongs to 
  which record. This needs **PostgreSQL 17 or newer**. On an older server, 
  saving any such records this way throws an 
  `ExceptionUnsupportedDatabaseVersion` without saving anything, so save them 
  with `save()`, or insert their rows with the table's `batchInsert()`, instead. 
  Saving many records that already have IDs works on any version.
- A foreign key to a new row can't be set until that row has been saved and 
  has its ID, so it can't be saved in the same transaction as the row that 
  references it.
- `canCodeGenerateId()` must return false if the ID column is 
  `GENERATED ALWAYS AS IDENTITY`, as PostgreSQL rejects inserts that provide a 
  value for such a column. `GENERATED BY DEFAULT AS IDENTITY` is fine.

For new tables, use UUIDs.


## Changing an ID

A record's ID can be changed through its `update()`, e.g. 
`$user->update(['id' => $newId])`, which changes its row's ID too. Any foreign 
keys referencing the old ID must allow it, e.g. with `ON UPDATE CASCADE`.
