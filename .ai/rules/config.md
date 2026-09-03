---
paths:
  - 'config/**'
  - config/database.php
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

## Neon needs the endpoint ID in DB_PASSWORD on Vercel
Neon routes a connection to the right compute by TLS SNI, which libpq only sends from Postgres 14 onwards. The PHP runtime on Vercel (`vercel-php`) ships an older libpq, so connections fail with `SQLSTATE[08006] ERROR: Endpoint ID is not specified`. The libpq installed during the build does not help — that is the build container, not the function runtime.

Neon's fallback is to carry the endpoint in the password: `DB_PASSWORD=endpoint=<endpoint-id>;<password>`, where the endpoint ID is the first label of the host with `-pooler` removed. Modern clients accept the same value, so it is safe everywhere. Do not pass it as a `?options=endpoint%3D...` query parameter on `DB_URL` — the url parser maps `options` onto the connection's `options` key and drops `PDO::ATTR_EMULATE_PREPARES`, bringing back the pooler transaction bug.
