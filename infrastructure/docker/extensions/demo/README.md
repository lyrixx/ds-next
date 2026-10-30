# Demo

A model to write an extension: a `demo` service (a web server which answers with the
details of the request) on `https://demo.<root_domain>`, and a `castor demo:call` task
which calls it from the builder.

An extension is a directory of `infrastructure/docker/extensions/`: Castor loads the
extensions of this directory, and only them. Every file is optional:

- `docker-compose.yml`: the services of the extension in dev, merged with the other
  compose files of the stack. Their paths are relative to `infrastructure/docker/`,
  like in the other compose files.
- `docker-compose.prod.yml`: the same for the production stack (`castor start -c
  prod`).
- `docker-compose.version.yml`: what depends on the version of the service, in dev and
  in the production stack: its image, and the volume of its data. It belongs to the
  project: `castor starter:sync` creates it with the extension, then never changes it,
  so that the data of a database is never lost to an upgrade nobody planned. Its other
  files of the project are listed in `.castor/starter/sync-project-files.json` (e.g.
  the VCL of the varnish extension).
- `extension.json`: what the extension adds to the stack:
  - `php_extensions`: PHP extensions, on top of those of `castor.php` (e.g. `["redis"]`
    installs `php8.5-redis` in the images);
  - `reverse_proxy`: its service which is a reverse proxy in front of the application,
    and its port (e.g. `{"service": "varnish", "port": 80}`, see below).
- `tasks.php`: its Castor tasks, imported when the extension is there.
- `README.md`: what the extension gives to the project (URL, DSN for the
  application...).

## Reverse proxies

The router sends the requests of the application to the reverse proxies of the
extensions, in the order of the extensions (their names), which forward them to the
next one, then to nginx (`frontend:8080`): e.g. Traefik → `redirectionio:80` →
`varnish:80` → `frontend:8080`. Castor gives each one its next hop in the
`<SERVICE>_UPSTREAM` variable of Docker Compose (e.g. `REDIRECTIONIO_UPSTREAM`), and
the router the first one (`PROJECT_HTTP_SERVICE`). The service of the proxy declares its
port to the router:

```yaml
services:
    varnish:
        labels:
            - "traefik.enable=true"
            - "project-name=${PROJECT_NAME}"
            - "traefik.http.services.${PROJECT_NAME}-varnish.loadbalancer.server.port=80"
```

`castor starter:add <name>` copies an extension of docker-starter in the project, then
`castor starter:sync` updates it like the other files of docker-starter. A project can
also have its own extensions: `castor starter:sync` never touches a directory that
docker-starter does not have.
