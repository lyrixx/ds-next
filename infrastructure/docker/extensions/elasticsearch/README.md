# Elasticsearch

The `elasticsearch` service, a single node without security (no TLS, no password),
on `https://elasticsearch.<root_domain>`, and Kibana on `https://kibana.<root_domain>`
(dev only).

In the application:

```
ELASTICSEARCH_URL="http://elasticsearch:9200"
```

The versions of Elasticsearch and Kibana are in `docker-compose.version.yml`, which
belongs to the project: `castor starter:sync` never changes it. Keep both versions the
same: Kibana refuses to start against another version of Elasticsearch.

To install plugins (e.g. `analysis-icu`), build the image with the `Dockerfile` of the
extension: replace `image` with `build` in `docker-compose.version.yml`:

```yaml
services:
    elasticsearch:
        build:
            context: extensions/elasticsearch
            args:
                ELASTICSEARCH_IMAGE: elasticsearch:9.5.5
                ELASTICSEARCH_PLUGINS: analysis-icu
        environment:
            - xpack.security.enabled=false
        volumes:
            - elasticsearch-data:/usr/share/elasticsearch/data
```

Then rebuild it with `castor build --service=elasticsearch`.

