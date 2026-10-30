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
  project: `castor starter:sync` never changes it, so that the data of a database is
  never lost to an upgrade nobody planned.
- `extension.json`: what the extension adds to the variables of `castor.php`, for now
  `php_extensions` (e.g. `["redis"]` installs `php8.5-redis` in the images).
- `tasks.php`: its Castor tasks, imported when the extension is there.
- `README.md`: what the extension gives to the project (URL, DSN for the
  application...).

`castor starter:add <name>` copies an extension of docker-starter in the project, then
`castor starter:sync` updates it like the other files of docker-starter. A project can
also have its own extensions: `castor starter:sync` never touches a directory that
docker-starter does not have.
