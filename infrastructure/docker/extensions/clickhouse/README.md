# ClickHouse

The `clickhouse` service, with a database `app` for the user `app` (password: `app`),
and its SQL console on `https://clickhouse.<root_domain>/play`.

In the application, over HTTP (most PHP clients):

```
CLICKHOUSE_URL="http://app:app@clickhouse:8123/app"
```

The native protocol is on port `9000`.

To query the database:

```
castor clickhouse                               # interactive client
castor clickhouse -- "SELECT version();"        # one-shot query
```

The version of ClickHouse is in `docker-compose.version.yml`, which belongs to the
project: `castor starter:sync` never changes it.
