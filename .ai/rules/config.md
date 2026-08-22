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
