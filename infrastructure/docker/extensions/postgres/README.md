# PostgreSQL

The `postgres` service, with a database `app` for the user `app` (password: `app`),
and the `pgsql` PHP extension in the images. A new project has it by default.

In the application:

```
DATABASE_URL="postgresql://app:app@postgres:5432/app?serverVersion=16&charset=utf8"
```

To query the database:

```
castor postgres                          # interactive psql
castor postgres -- "SELECT now();"       # one-shot query
```

The version of PostgreSQL is in `docker-compose.version.yml`, which belongs to the
project: `castor starter:sync` never changes it, since a major version cannot read
the data of the previous one.
