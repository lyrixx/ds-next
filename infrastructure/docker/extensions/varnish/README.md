# Varnish

The `varnish` service, a [Varnish](https://varnish-cache.org/) cache in front of
nginx, in dev only: the router sends it the requests of the application, and it
forwards them to nginx (see "Reverse proxies" in the README of the demo extension).
Its `X-Cache` response header says `HIT` or `MISS`.

Its VCL, `default.vcl`, belongs to the project: `castor starter:sync` never changes
it. Restart Varnish after a change: `castor docker:up --service=varnish` recreates the
container only if its configuration changed, so run `castor stop --service=varnish`
first.

The size of the cache is in `VARNISH_SIZE` (256M), which `docker-compose.project.yml`
can change:

```yaml
services:
    varnish:
        environment:
            - VARNISH_SIZE=1G
```
