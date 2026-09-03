---
paths:
  - 'config/**'
---

# Config

## Postgres database must be created with UTF8 encoding
The local Postgres cluster defaults to WIN1252. A database created with that default rejects any character outside Latin-1 with `SQLSTATE[22P05] Untranslatable character`, which the SAP org import hits.

Create the database explicitly:
`create database tasku_db with template template0 encoding 'UTF8' lc_collate 'Indonesian_Indonesia.1252' lc_ctype 'Indonesian_Indonesia.1252'`

Encoding cannot be changed in place — a wrong one means drop and recreate. Check with `select pg_encoding_to_char(encoding) from pg_database where datname = current_database()`.

## Neon pooler needs PDO::ATTR_EMULATE_PREPARES
The Neon `-pooler` host is PgBouncer in transaction pooling mode. It cannot carry PDO's server side prepared statements from one statement to the next, so the second statement inside a transaction aborts it and everything after fails with `SQLSTATE[25P02] current transaction is aborted, commands ignored until end of transaction block`. It looks like an application bug — creating a sub task fails (select then insert inside `DB::transaction`) while a plain single-statement update succeeds.

Fix already in place: the `pgsql` connection sets `PDO::ATTR_EMULATE_PREPARES` from `DB_EMULATE_PREPARES`, and `.env` sets it to true. Keep it true on any pooled Neon host. The alternative is the direct (non-pooler) host, which trades connection pooling for server side prepares.
