# Meilisearch

The `meilisearch` service, in development mode (no master key), with its dashboard on
`https://meilisearch.<root_domain>`.

In the application:

```
MEILISEARCH_URL="http://meilisearch:7700"
```

The version of Meilisearch is in `docker-compose.version.yml`, which belongs to the
project: `castor starter:sync` never changes it.
