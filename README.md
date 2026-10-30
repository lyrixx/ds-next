<h1 align="center">
  <a href="https://github.com/jolicode/docker-starter"><img src="https://jolicode.com/media/original/oss/headers/docker-starter.png" alt="Docker Starter"></a>
  <br />
  Docker Starter
  <br />
  <sub><em><h6>A docker-based infrastructure wrapped in an easy-to-use command line, oriented for PHP projects.</h6></em></sub>
</h1>

This repository contains a collection of Dockerfile and docker-compose configurations
for your PHP projects with built-in support for HTTPS, custom domain, databases, workers...
and is used as a foundation for our projects at [JoliCode](https://jolicode.com/).

> [!WARNING]
> You are reading the README of version 4 that uses [Castor](https://castor.jolicode.com).

* If you are using [Invoke](https://www.pyinvoke.org/), you can read the [dedicated README](https://github.com/jolicode/docker-starter/tree/v3.11.0);
* If you are using [Fabric](https://www.fabfile.org/), you can read the [dedicated README](https://github.com/jolicode/docker-starter/tree/v2.0.0);

## Project configuration

Before executing any command, you need to configure a few parameters in the
`castor.php` file, in the `create_default_variables()` function:

* `project_name` (**required**): This will be used to prefix all docker objects
(network, images, containers);

* `root_domain` (optional, default: `project_name + '.test'`): This is the root
domain where the application will be available;

* `extra_domains` (optional): This contains extra domains where the application
will be available;

* `php_version` (optional, default: `8.5`): This is the PHP version installed inside the containers.

* `app_dir` (optional, default: `application`): This is the directory of the
application, relative to the root of the repository. Use `''` if the
application lives at the root of the repository;

* `http_port` (optional, default: `8000`): This is the port where the
production images are available, when running them locally (`castor start -c prod`).
The `HTTP_PORT` environment variable takes precedence;

* `registry` (optional): This is the docker registry used to cache images
layers and to push the production images. By default, it comes from the
`REGISTRY` environment variable, set by the CI workflows. If you hardcode it,
keep the environment variable as an override (lowercased, as Docker requires):
`'registry' => strtolower($_SERVER['REGISTRY'] ?? 'ghcr.io/your-organization/your-project'),`;

* `php_extensions` (optional, default: `[]`): The PHP extensions to install in
the images, on top of those of docker-starter and of its extensions, by their
name in the Debian packages (e.g. `['imagick', 'redis']` installs `php8.5-imagick` and `php8.5-redis`);

* `system_packages` (optional, default: `[]`): The Debian packages to install
in every image, for the needs of the application at runtime (e.g.
`['imagemagick', 'ghostscript']`);

* `builder_system_packages` (optional, default: `[]`): The Debian packages to
install only in the builder, for the development tools (e.g. `['mariadb-client']`);

* `nodejs` (optional, default: `true`): Whether to install Node.js and Yarn in
the builder: `true` for the version of docker-starter, a major version (e.g.
`'22'`) to pin another one, or `false` if the application does not need them
(e.g. with the Symfony AssetMapper). Yarn is the version of the `packageManager`
field of `package.json`, or the latest stable one: a project still on Yarn 1
pins it (`"packageManager": "yarn@1.22.22"`);

* `writable_dirs` (optional, default: `[]`): The directories the application
writes in at runtime in production, relative to the application directory,
usually the mount points of volumes (e.g. `['var/share', 'public/uploads']`):
they belong to the application user in the `php` image, so that a volume
inherits it when it is first created.

The services (database, Redis...) come with the [extensions](#extensions).

For example:

```php
function create_default_variables(): array
{
    $projectName = 'app';
    $tld = 'test';

    return [
        'project_name' => $projectName,
        'root_domain' => "{$projectName}.{$tld}",
        'extra_domains' => [
            "www.{$projectName}.{$tld}",
            "admin.{$projectName}.{$tld}",
            "api.{$projectName}.{$tld}",
        ],
        'php_version' => 8.5,
    ];
)
```

Will give you `https://app.test`,  `https://www.app.test`,
`https://api.app.test` and `https://admin.app.test` pointing at your
`application/` directory.

> [!NOTE]
> Some castor tasks have been added for DX purposes. Checkout and adapt
> the tasks `install`, `migrate` and `cache_clear` to your project.

### The services of the project

Add the services of your project, and your changes to those of docker-starter
(environment variables, volumes...), to `infrastructure/docker/docker-compose.project.yml`,
and to `infrastructure/docker/docker-compose.prod.project.yml` for the production
stack, rather than to the files of docker-starter. Castor loads them after the
files of docker-starter, and Docker Compose
[merges them](https://docs.docker.com/compose/how-tos/multiple-compose-files/merge/).
Unlike `docker-compose.override.yml` and `docker-compose.prod.override.yml`,
kept for the local tweaks of each developer, they are committed.

## Extensions

The services most projects need come as extensions of docker-starter: the
directories of `infrastructure/docker/extensions/`, which Castor loads with the
rest of the stack. A new project has `postgres`. Add or remove the others with:

```bash
castor starter:add redis
castor starter:remove postgres
```

Then run `castor start`, to rebuild and restart the stack, and commit.
`castor starter:add` copies the extension from the docker-starter version of
your project (the `ref` of `.docker-starter.json`), then `castor starter:sync`
updates it like the other files of docker-starter. `castor starter:remove`
keeps the volumes of the extension, and their data.

| Extension | What it adds |
|---|---|
| `postgres` | PostgreSQL, and the `pgsql` PHP extension (a new project has it) |
| `mariadb` | MariaDB, and the `mysql` PHP extension |
| `redis` | Redis, Redis Insight on `https://redis.<root_domain>`, and the `redis` PHP extension |
| `rabbitmq` | RabbitMQ, its dashboard on `https://rabbitmq.<root_domain>`, and the `amqp` PHP extension |
| `meilisearch` | Meilisearch, and its dashboard on `https://meilisearch.<root_domain>` |
| `mailpit` | Mailpit, which catches the emails sent in dev, on `https://mailpit.<root_domain>` |
| `cron` | A `cron` service in dev, which runs the jobs of the project |
| `demo` | A model to write an extension |

The `README.md` of each extension says what to configure in the application
(e.g. the DSN).

The version of the services which keep data (PostgreSQL, MariaDB, RabbitMQ,
Meilisearch) is in the `docker-compose.version.yml` file of their extension. It
belongs to your project: `castor starter:sync` never changes it, since a new
version cannot always read the data of the previous one. Upgrade it when you
planned the migration of the data. The `crontab` of the `cron` extension belongs
to your project too.

Your project can also have its own extensions: `castor starter:sync` never
touches a directory that docker-starter does not have. See the
[README of the demo extension](infrastructure/docker/extensions/demo/README.md)
for the files of an extension.

## Git worktree support

When running `castor` commands inside a [git worktree](https://git-scm.com/docs/git-worktree),
the stack is **automatically isolated** from other worktrees:

- Docker Compose **project name**, **volumes**, and **networks** are suffixed with `-wt-<worktree-name>`
- **Ports** are derived from the worktree name (use `castor docker:ports` to display them)

This allows running multiple feature branches in parallel without conflicts.

## Usage documentation

We provide a [README.dist.md](./README.dist.md) to bootstrap your project
documentation, with everything you need to know to start and interact with the
infrastructure.

If you want to install a Symfony project, you can run (before `castor init`):

```bash
castor symfony [--web-app]
```

To replace this README with the dist, and remove all unnecessary files, you can
run:

```bash
castor init
```

It also removes the extensions but `postgres` (docker-starter runs all of them,
to test them), and, in a clone of docker-starter, records its version in
`.docker-starter.json`, for `castor starter:sync` and `castor starter:add`.

> [!NOTE]
> This command can be run only once

The GitHub Actions workflows of `.github/` are kept as the CI of your project:
`castor init` only removes their "docker-starter only" blocks, which test
docker-starter itself (PHP versions matrix, demo application...).

Also, in order to improve your usage of castor scripts, you can install console
autocompletion script.

If you are using bash:

```bash
castor completion | sudo tee /etc/bash_completion.d/castor
```

If you are using something else, please refer to your shell documentation. You
may need to use `castor completion > /to/somewhere`.

Castor supports completion for `bash`, `zsh` & `fish` shells.

## How to update docker-starter in a project

The castor tasks shared by all the projects (`docker:*`, `qa:*`, `starter:*`,
the contexts...) live in `.castor/starter/`. In your project, this directory is
read-only, like a vendor directory: `castor starter:sync` (or `castor composer
update`, see below) updates it, never edit it. Customize the project elsewhere:

* configure the shared tasks with the variables of `create_default_variables()`
  in `castor.php` (see [Project configuration](#project-configuration));
* add your own tasks in `castor.php`, or in your own files of `.castor/`;
* change what a shared task does with a listener (see
  [Change a shared task with a listener](#change-a-shared-task-with-a-listener)).

The other files that come from docker-starter (`Dockerfile`, compose files, CI
workflows...) can be changed, but the fewer lines your project changes in them,
the fewer conflicts `castor starter:sync` leaves. So rather than editing them:

* configure the images with the variables of `castor.php` (`php_extensions`,
  `nodejs`, `writable_dirs`...), and add the services of docker-starter with its
  [extensions](#extensions);
* add the services of your project (database, search engine...), and change
  those of docker-starter (environment variables, volumes...), in
  `infrastructure/docker/docker-compose.project.yml`, and in
  `infrastructure/docker/docker-compose.prod.project.yml` for the production
  stack. Castor loads them after the files of docker-starter, Docker Compose
  [merges them](https://docs.docker.com/compose/how-tos/multiple-compose-files/merge/),
  and `castor starter:sync` never touches them. Unlike
  `docker-compose.override.yml` and `docker-compose.prod.override.yml`, kept for
  the local tweaks of each developer, they are committed;
* build the images of your project with their own Dockerfile, in their own
  directory of `infrastructure/docker/services/` (e.g. `build: services/grafana`
  in `docker-compose.project.yml`).

Real changes of the `Dockerfile` remain (e.g. another Debian version): keep
them as small as possible.

### Update all the files with `castor starter:sync`

`castor starter:sync` updates the files of your project that come from
docker-starter (`Dockerfile`, compose files, CI workflows, castor tasks...),
with a 3-way merge between the docker-starter version your project is based on,
the new docker-starter version, and your project:

* the changes of your project are kept, and the conflicts are left with the
  usual git markers (`<<<<<<< project`), the task then exits with an error. The
  next runs refuse to start until they are resolved, and Castor itself does not
  start while a file of `.castor/starter/` has conflicts (resolve these ones
  with a text editor);
* the files deleted from your project are not recreated, the files deleted from
  docker-starter are deleted, unless your project modified them;
* the files of `application/` go to the application directory of your project
  (the `app_dir` variable);
* only the [extensions](#extensions) of your project are updated: the task never
  adds one (see `castor starter:add`);
* the files which belong to your project, but which docker-starter gives a
  starting point (`app-project.ini`, `nginx/project.conf`, the
  `docker-compose.version.yml` of the extensions, the `crontab`...), are created
  when docker-starter adds them, then never changed;
* the GitHub workflows are merged without their "docker-starter only" blocks,
  as `castor init` removed them from your project.

As long as `.castor/starter/` is left untouched, it is updated without
conflicts.

The first time, tell it which docker-starter commit (or tag) your project is
based on, it saves it in the `.docker-starter.json` file:

```bash
castor starter:sync --from=<commit>
```

If your project was created before this task existed, fetch it first from
docker-starter, at the version you update to (`<ref>`, e.g. `main`), commit it
(the task refuses to run with uncommitted changes), then run it from the
docker-starter version of your last update (`<commit>`, the one your project
was created from if it was never updated). Set the `app_dir` variable in
`castor.php` before, if your application does not live in `application/`:

```bash
mkdir -p .castor/starter
curl -fsSL https://raw.githubusercontent.com/jolicode/docker-starter/<ref>/.castor/starter/sync.php -o .castor/starter/sync.php
git add .castor/starter/sync.php && git commit -m "Add castor starter:sync"
castor starter:sync --from=<commit> --to=<ref>
```

This first update usually conflicts where your project changed by hand what
docker-starter now configures (e.g. the paths of the application directory):
keep the version of docker-starter there, and the real changes of your project.
A merge without conflicts can also keep such a change, when docker-starter did
not touch that line in the meantime (e.g. a path in the nginx configuration).
So once this first update is committed, compare your project with
docker-starter at the same version (the `ref` of `.docker-starter.json`), and
align what is not a real change of your project:

```bash
git clone --quiet https://github.com/jolicode/docker-starter ../docker-starter
git -C ../docker-starter checkout --quiet <ref>
git diff --no-index ../docker-starter/infrastructure infrastructure
git diff --no-index ../docker-starter/.castor/starter .castor/starter
```

Then, to update to the latest docker-starter version (or `--to=<ref>`):

```bash
castor starter:sync [--dry-run]
```

Commit the `.docker-starter.json` file with the result. The task never touches
the files that the docker-starter version you update to lists in
`.castor/starter/sync-default-exclude.json` (the documentation, `castor.php`,
the application, the configuration of PHP-CS-Fixer, PHPStan and Dependabot,
this file itself...), and only creates those of
`.castor/starter/sync-project-files.json`. Add the files of your project to
leave alone to the `exclude` key of `.docker-starter.json`: a trailing slash
excludes a whole directory, and shell wildcards are supported. The `repository`
key allows to use a fork of docker-starter.

If your project changes the rules of PHP-CS-Fixer, exclude `.castor/starter/`
and `infrastructure/docker/extensions/` from them in `.php-cs-fixer.php`:
otherwise, it rewrites the files of docker-starter, and each update conflicts.

### Update automatically, every week

The `.github/workflows/starter-sync.yml` workflow runs `castor starter:sync`
every Monday, and opens a pull request with the result, in draft when there
are conflicts to resolve (on its branch, then mark it as ready). Nothing
happens while the previous one is open.

Give it a token in the `STARTER_SYNC_TOKEN` secret of the repository (a
fine-grained token or a GitHub App, which can write the contents, the pull
requests and the workflows): with the default token of the workflow, the CI
does not run on the pull request, and an update of the workflows cannot be
pushed.

The steps shared by the jobs of the CI (Docker Buildx, the login to the
registry, Castor) are in the `.github/actions/setup` composite action, updated
with the rest: keep the jobs of your project in the workflows, and call it
after `actions/checkout`.

### Install the shared castor tasks as a package

By default, your project keeps a copy of `.castor/starter/`. To update it with
`castor composer update` instead of copying files around, install it as a
[Castor remote package](https://castor.jolicode.com/going-further/extending-castor/remote-imports/):

1. Remove the `.castor/starter/` directory;
2. Create a `castor.composer.json` file next to `castor.php`:
   ```json
   {
       "repositories": [
           {
               "type": "vcs",
               "url": "https://github.com/jolicode/docker-starter"
           }
       ],
       "require": {
           "jolicode/docker-starter": "dev-main"
       }
   }
   ```
3. Import the package in `castor.php`, next to the import of `.castor/`:
   ```php
   import('composer://jolicode/docker-starter', file: '.castor/starter/index.php');
   ```
4. Ignore the `/.castor/vendor/` directory in `.gitignore`, and commit the
   `castor.composer.lock` file;
5. If PHPStan analyses the castor files, add
   `.castor/vendor/jolicode/docker-starter/.castor/starter` to its
   `scanDirectories`.

Then, `castor composer update jolicode/docker-starter` updates the tasks. As the
copy of `.castor/starter/`, the package is read-only: customize it as explained
[above](#how-to-update-docker-starter-in-a-project).

> [!WARNING]
> Castor remote imports are still experimental.

The infrastructure files (`Dockerfile`, compose files, CI workflows...) are not
part of the package: they are meant to be adapted to each project, update them
with `castor starter:sync` (which then leaves `.castor/starter/` alone).

### Change a shared task with a listener

To run something before or after a shared task, use a
[listener](https://castor.jolicode.com/going-further/extending-castor/events/)
instead of editing it. For example, to warm the Symfony cache before PHPStan:

```php
use Castor\Attribute\AsListener;
use Castor\Event\BeforeExecuteTaskEvent;

use function Castor\context;
use function docker\docker_compose_run;

#[AsListener(event: BeforeExecuteTaskEvent::class)]
function warmup_cache_before_phpstan(BeforeExecuteTaskEvent $event): void
{
    if ('qa:phpstan' !== $event->task->getName()) {
        return;
    }

    docker_compose_run(['bin/console', 'cache:warmup', '--env=dev'], c: context()->withAllowFailure());
}
```

## Cookbooks

### How to install third party tools with Composer

<details>

<summary>Read the cookbook</summary>

If you want to install some third party tools with Composer, it is recommended to install them in their dedicated directory.
PHPStan and PHP-CS-Fixer are already installed in the `tools` directory.

We suggest to:

1. create a composer.json which requires only this tool in `tools/<tool name>/composer.json`;

1. create an executable symbolic link to the tool from the root directory of the project: `ln -s ../<tool name>/vendor/bin/<tool bin> tools/bin/<tool bin>`;

> [!NOTE]
> Relative symlinks works here, because the first part of the command is relative to the second part, not to the current directory.

Since `tools/bin` path is appended to the `$PATH`, tools will be available globally in the builder container.
`castor qa:install` and `castor qa:update` install and update every tool of
`tools/`, yours included (e.g. Rector in `tools/rector/`): add the task that runs it
to `castor.php`.

</details>

### How to change the layout of the project

<details>

<summary>Read the cookbook</summary>

If you want to rename the `application` directory, or even move its content to
the root directory, set the `app_dir` variable in the `create_default_variables()`
function of `castor.php` (`''` for the root directory), move the files, and
rebuild the infrastructure with `castor build`:

```php
function create_default_variables(): array
{
    return [
        'project_name' => 'app',
        'app_dir' => '',
    ];
}
```

Castor passes it to Docker Compose (`APP_DIR`, relative to the root of the
repository) and to the images (`APP_PATH` build argument, the path inside the
containers, e.g. `/var/www`): the builder lands in the right directory, nginx
serves the right `public/` directory, and the production images embed the
right directory.

When the application lives at the root of the repository, the production
images are built from the whole repository: add to the `.dockerignore` file of
the application, now at the root, what is not needed at runtime and would
otherwise end up in the images:

```
.castor
.castor.stub.php
.docker-starter.json
.github
.home
.php-cs-fixer.cache
.php-cs-fixer.php
.twig-cs-fixer.cache
castor.*
infrastructure
phpstan.neon
tools
```

</details>

### How to change the PHP and nginx settings

<details>

<summary>Read the cookbook</summary>

Put the PHP settings of your project in
`infrastructure/docker/services/php/php/mods-available/app-project.ini`. Every
image loads it (dev and production), after the settings of docker-starter, and
docker-starter never changes this file:

```ini
; priority=60
[PHP]
memory_limit = 1G
```

Put the nginx directives of your project (headers, extra locations...) in
`infrastructure/docker/services/php/nginx/project.conf`, included in the
`server` block of the dev and production images. docker-starter never changes
this file either. For example, to accept uploads up to 200 MB, along with
`upload_max_filesize = 200M` and `post_max_size = 200M` in `app-project.ini`:

```nginx
client_max_body_size 200m;
```

Then rebuild the images with `castor build`.

</details>

### How to use MySQL instead of PostgreSQL

<details>

<summary>Read the cookbook</summary>

Start from the `mariadb` [extension](#extensions): run `castor starter:add
mariadb`, then rename `infrastructure/docker/extensions/mariadb/` to
`infrastructure/docker/extensions/mysql/`. It becomes an extension of your
project, which `castor starter:sync` never touches. Remove PostgreSQL with
`castor starter:remove postgres`. In the files of `mysql/`, rename the `mariadb`
service (and the namespace of `tasks.php`), and use the following configuration
for the database service:

```
    mysql:
        image: mysql:8
        environment:
            - MYSQL_ALLOW_EMPTY_PASSWORD=1
        healthcheck:
            test: "mysqladmin ping -h localhost"
            interval: 5s
            timeout: 5s
            retries: 10
         volumes:
            - mysql-data:/var/lib/mysql
```

</details>

### How to use with Webpack Encore

<details>

<summary>Read the cookbook</summary>

> [!NOTE]
> this cookbook documents the integration of webpack 5+. For older version
> of webpack, use previous version of the docker starter.

If you want to use Webpack Encore in a Symfony project,

1. Follow [instructions on symfony.com](https://symfony.com/doc/current/frontend/encore/installation.html#installing-encore-in-symfony-applications) to install webpack encore.

    You will need to follow [these instructions](https://symfony.com/doc/current/frontend/encore/simple-example.html) too.

2. Create a new service for encore:

    Add the following content to the `infrastructure/docker/docker-compose.project.yml` file:

    ```yaml
    services:
        encore:
            build:
                context: services/php
                target: builder
            volumes:
                - "../..:/var/www:cached"
            command: >
                yarn run dev-server
                    --hot
                    --host 0.0.0.0
                    --public https://encore.${PROJECT_ROOT_DOMAIN}
                    --allowed-hosts ${PROJECT_ROOT_DOMAIN}
                    --allowed-hosts encore.${PROJECT_ROOT_DOMAIN}
                    --client-web-socket-url-hostname encore.${PROJECT_ROOT_DOMAIN}
                    --client-web-socket-url-port 443
                    --client-web-socket-url-protocol wss
                    --server-type http
            labels:
                - "project-name=${PROJECT_NAME}"
                - "traefik.enable=true"
                - "traefik.http.routers.${PROJECT_NAME}-encore.rule=Host(`encore.${PROJECT_ROOT_DOMAIN}`)"
                - "traefik.http.routers.${PROJECT_NAME}-encore.tls=true"
                - "traefik.http.services.encore.loadbalancer.server.port=8000"
            healthcheck:
            test: ["CMD", "curl", "-f", "http://localhost:8000/build/app.css"]
            profiles:
                - default
    ```

If the assets are not reachable, you may accept self-signed certificate. To do so, open a new tab
at https://encore.app.test and click on accept.

</details>

### How to use with AssetMapper

<details>

<summary>Read the cookbook</summary>

1. Follow [instructions on symfony.com](https://symfony.com/doc/current/frontend/asset_mapper.html#installation) to install AssetMapper.

1. If the application does not need Node.js anymore, remove it from the
   builder in `castor.php`, and rebuild it with `castor build`:

    ```php
            'nodejs' => false,
    ```
</details>

### How to add Elasticsearch and Kibana

<details>

<summary>Read the cookbook</summary>

In order to use Elasticsearch and Kibana, you should add the following content
to the `infrastructure/docker/docker-compose.project.yml` file:

```yaml
volumes:
    elasticsearch-data: {}

services:
    elasticsearch:
        image: elasticsearch:7.8.0
        volumes:
            - elasticsearch-data:/usr/share/elasticsearch/data
        environment:
            - "discovery.type=single-node"
        labels:
            - "traefik.enable=true"
            - "project-name=${PROJECT_NAME}"
            - "traefik.http.routers.${PROJECT_NAME}-elasticsearch.rule=Host(`elasticsearch.${PROJECT_ROOT_DOMAIN}`)"
            - "traefik.http.routers.${PROJECT_NAME}-elasticsearch.tls=true"
        healthcheck:
            test: "curl --fail http://localhost:9200/_cat/health || exit 1"
            interval: 5s
            timeout: 5s
            retries: 5
        profiles:
            - default

    kibana:
        image: kibana:7.8.0
        depends_on:
            - elasticsearch
        labels:
            - "traefik.enable=true"
            - "project-name=${PROJECT_NAME}"
            - "traefik.http.routers.${PROJECT_NAME}-kibana.rule=Host(`kibana.${PROJECT_ROOT_DOMAIN}`)"
            - "traefik.http.routers.${PROJECT_NAME}-kibana.tls=true"
        profiles:
            - default
```

Then, you will be able to browse:

* `https://kibana.<root_domain>`
* `https://elasticsearch.<root_domain>`

In your application, you can use the following configuration:

* scheme: `http`;
* host: `elasticsearch`;
* port: `9200`.

</details>

### How to use with Sylius

<details>

<summary>Read the cookbook</summary>

If you want to install a Sylius project, you can run (before `castor init`):

```bash
castor sylius
```

</details>

### How to add Mercure

<details>

<summary>Read the cookbook</summary>

In order to use Mercure, you should add the following content to the
`infrastructure/docker/docker-compose.project.yml` file:

```yaml
services:
    mercure:
        image: dunglas/mercure
        environment:
            - "MERCURE_PUBLISHER_JWT_KEY=password"
            - "MERCURE_SUBSCRIBER_JWT_KEY=password"
            - "ALLOW_ANONYMOUS=1"
            - "CORS_ALLOWED_ORIGINS=*"
        labels:
            - "traefik.enable=true"
            - "project-name=${PROJECT_NAME}"
            - "traefik.http.routers.${PROJECT_NAME}-mercure.rule=Host(`mercure.${PROJECT_ROOT_DOMAIN}`)"
            - "traefik.http.routers.${PROJECT_NAME}-mercure.tls=true"
        profiles:
            - default
```

If you are using Symfony, you must put the following configuration in the `.env` file:

```
MERCURE_PUBLISH_URL=http://mercure/.well-known/mercure
MERCURE_JWT_TOKEN=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJtZXJjdXJlIjp7InN1YnNjcmliZSI6W10sInB1Ymxpc2giOltdfX0.t9ZVMwTzmyjVs0u9s6MI7-oiXP-ywdihbAfPlghTBeQ
```

</details>

### How to add redirection.io

<details>

<summary>Read the cookbook</summary>

In order to use redirection.io, you should add the following content to the
`infrastructure/docker/docker-compose.project.yml` file to run the agent:

```yaml
services:
    redirectionio-agent:
        build: services/redirectionio-agent
```

Add the following file `infrastructure/docker/services/redirectionio-agent/Dockerfile`:

```Dockerfile
FROM alpine:3.12 AS alpine

WORKDIR /tmp

RUN apk add --no-cache wget ca-certificates \
    && wget https://packages.redirection.io/dist/stable/2/any/redirectionio-agent-latest_any_amd64.tar.gz \
    && tar -xzvf redirectionio-agent-latest_any_amd64.tar.gz

FROM scratch

# Binary copied from tar
COPY --from=alpine /tmp/redirection-agent/redirectionio-agent /usr/local/bin/redirectionio-agent

# Configuration, can be replaced by your own
COPY etc /etc

# Root SSL Certificates, needed as we do HTTPS requests to our service
COPY --from=alpine /etc/ssl/certs/ca-certificates.crt /etc/ssl/certs/

CMD ["/usr/local/bin/redirectionio-agent"]
```

Add `infrastructure/docker/services/redirectionio-agent/etc/redirectionio/agent.yml`:

```yaml
instance_name: "my-instance-dev" ### You may want to change this
listen: 0.0.0.0:10301
```

Then you'll need `wget`. In
`infrastructure/docker/services/php/Dockerfile`, in stage `frontend`:

```Dockerfile
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        wget \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*
```

You can group this command with another one.

Then, **after** installing nginx, you need to install the module:

```Dockerfile
RUN wget -q -O - https://packages.redirection.io/gpg.key | gpg --dearmor > /usr/share/keyrings/redirection.io.gpg \
    && echo "deb [signed-by=/usr/share/keyrings/redirection.io.gpg] https://packages.redirection.io/deb/stable/2 focal main" | tee -a /etc/apt/sources.list.d/packages_redirection_io_deb.list \
    && apt-get update \
    && apt-get install libnginx-mod-redirectionio \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*
```

Finally, you need to edit
`infrastructure/docker/services/php/nginx/conf.d/default.conf` to add the
following configuration in the `server` block:

```
redirectionio_pass redirectionio-agent:10301;
redirectionio_project_key "AAAAAAAAAAAAAAAA:BBBBBBBBBBBBBBBB";
```

**Don't forget to change the project key**.

</details>

### How to add Blackfire.io

<details>

<summary>Read the cookbook</summary>

In order to use Blackfire.io, you should add the following content to the
`infrastructure/docker/docker-compose.project.yml` file to run the agent:

```yaml
services:
    blackfire:
        image: blackfire/blackfire
        environment:
            BLACKFIRE_SERVER_ID: FIXME
            BLACKFIRE_SERVER_TOKEN: FIXME
            BLACKFIRE_CLIENT_ID: FIXME
            BLACKFIRE_CLIENT_TOKEN: FIXME
        profiles:
            - default

```

Then you'll need `wget`. In
`infrastructure/docker/services/php/Dockerfile`, in stage `base`:

```Dockerfile
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        wget \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*
```

You can group this command with another one.

Then, **after** installing PHP, you need to install the probe:

```Dockerfile
RUN wget -q -O - https://packages.blackfire.io/gpg.key | gpg --dearmor > /usr/share/keyrings/blackfire.io.gpg \
    && sh -c 'echo "deb [signed-by=/usr/share/keyrings/blackfire.io.gpg] http://packages.blackfire.io/debian any main" > /etc/apt/sources.list.d/blackfire.list' \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        blackfire-php \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/* \
    && sed -i 's#blackfire.agent_socket.*#blackfire.agent_socket=tcp://blackfire:8707#' /etc/php/${PHP_VERSION}/mods-available/blackfire.ini
```

If you want to profile HTTP calls, you need to enable the probe with PHP-FPM.
So in `infrastructure/docker/services/php/Dockerfile`:

```Dockerfile
RUN phpenmod blackfire
```

Here also, You can group this command with another one.

</details>

### How to add Chrome Headless

<details>

<summary>Read the cookbook</summary>

If you want to enable Headless Chrome in your project, run the following command (before `castor init`):

```bash
castor chrome
```

If your project is already initialized, you can add it to the `infrastructure/docker/docker-compose.project.yml` file:

```yaml
services:
  # ...
  chrome:
    image: ghcr.io/browserless/chromium
    ports:
      - "9222:3000"
```

</details>

### How to run workers?

<details>

<summary>Read the cookbook</summary>

In order to set up workers, you should define their services in the
`infrastructure/docker/docker-compose.project.yml` file. YAML anchors do not
cross files, so repeat there the settings of the `worker_base` template of
`docker-compose.yml`:

```yaml
services:
    worker_date:
        build:
            context: services/php
            target: worker
        user: "${USER_ID}:${USER_ID}"
        working_dir: "${APP_PATH}"
        environment:
            - APP_ENV
        volumes:
            - "../..:/var/www:cached"
            - "../../.home:/home/app:cached"
        command: watch -n 1 date
        profiles:
            - worker
```

</details>

### How to use PHP FPM status page?

<details>

<summary>Read the cookbook</summary>

If you want to use the [PHP FPM status
page](https://www.php.net/manual/en/fpm.status.php) you need to remove a
configuration line in the
`infrastructure/docker/services/php/nginx/conf.d/default.conf` file:

```diff
         # Remove this block if you want to access to PHP FPM monitoring
         # dashboard (on URL: /php-fpm-status). WARNING: on production, you must
         # secure this page (by user IP address, with a password, for example)
         location = /php-fpm-status {
-            deny all;
```

And if your application uses the front controller pattern, and you want to see
the real request URI, you also need to uncomment the following configuration
block:

```diff
-            # # Uncomment if you want to use /php-fpm-status endpoint **with**
-            # # real request URI. It may have some side effects, that's why it's
-            # # commented by default
-            # fastcgi_param SCRIPT_NAME $request_uri;
+            # Uncomment if you want to use /php-fpm-status endpoint **with**
+            # real request URI. It may have some side effects, that's why it's
+            # commented by default
+            fastcgi_param SCRIPT_NAME $request_uri;
```

</details>

### How to pg_activity for monitoring PostgreSQL

<details>

<summary>Read the cookbook</summary>

In order to install pg_activity, build your own PostgreSQL image: create the
`infrastructure/docker/services/postgres/Dockerfile` file, with the version of
PostgreSQL of your project:

```Dockerfile
FROM postgres:16

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        pg-activity \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* /usr/share/doc/*
```

Use it in `infrastructure/docker/extensions/postgres/docker-compose.version.yml`,
which belongs to your project: replace `image: postgres:16` with
`build: services/postgres`.

Then, you can add the following content to the `castor.php` file:

```php
#[AsTask(description: 'Monitor PostgreSQL', namespace: 'app:db')]
function pg_activity(): void
{
    docker_compose(['exec', 'postgres', 'pg_activity', '-U', 'app');
}
```

Finally you can use the following command:

```
castor app:db:pg-activity
```

</details>

### Docker For Windows support

<details>

<summary>Read the cookbook</summary>

This starter kit is compatible with Docker for Windows, so you can enjoy native Docker experience on Windows. You will have to keep in mind some differences:

- You will be prompted to run the env vars manually if you use PowerShell.
</details>

### How to access a container via hostname from another container

<details>

<summary>Read the cookbook</summary>

Let's say you have a container (`frontend`) that responds to many hostnames:
`app.test`, `api.app.test`, `admin.app.test`. And you have another container
(`builder`) that needs to call the `frontend` with a specific hostname - or with
HTTPS. This is usually the case when you have a functional test suite.

The root domain (`app.test`) already works: it is a network alias of the router,
which serves the application as for a browser. Add the other domains as aliases
too, in `infrastructure/docker/docker-compose.project.yml`:

```yaml
services:
    router:
        networks:
            default:
                aliases:
                    - "api.app.test"
                    - "admin.app.test"
```

The router serves them with the certificate of the dev stack: ignore the
certificate errors in these calls, or trust the certificate.

</details>

### How to connect networks of two projects

<details>

<summary>Read the cookbook</summary>

Let's say you have two projects `foo` and `bar`. You want to run both projects a
the same time. And containers from `foo` project should be able to dialog with
`bar` project via public network (host network).

In the `foo` project, you'll need to declare the `bar_default` network in
`infrastructure/docker/docker-compose.project.yml`:

```yaml
networks:
    bar_default:
        external: true
```

Then, attach it to the the `foo` router:

```yaml
services:
    router:
        networks:
            - default
            - bar_default
```

Finally, you must remove the constraints on the router so it'll be able to
discover containers from another docker compose project:

```diff
--- a/infrastructure/docker/services/router/traefik/traefik.yaml
+++ b/infrastructure/docker/services/router/traefik/traefik.yaml
 providers:
   docker:
     exposedByDefault: false
-    constraints: "Label(`project-name`,`{{ PROJECT_NAME }}`)"
   file:
```

Finally, you must :

1. build the project `foo`
1. build the project `bar`
1.  Create the network `bar_default` (first time only)
    ```
    docker network create bar_default
    ```
1. start the project `foo`
1. start the project `bar`

</details>

### How to use FrankenPHP

<details>

<summary>Read the cookbook</summary>

Migrating to FrankenPHP involves a lot of changes. You can take inspiration from
the following [repostory](https://github.com/lyrixx/async-messenger-mercure)
and specifically [this commit](https://github.com/lyrixx/async-messenger-mercure/commit/9ac8776253f3950a6c57d457b3742923f9e096a7).

</details>

### How to build production images

<details>

<summary>Read the cookbook</summary>

The application can ship as two self-contained Docker images, built from the
"Production stages" of `infrastructure/docker/services/php/Dockerfile`:

* `php`: php-fpm listening on the unix socket `/var/run/php/php-fpm.sock`,
  with the code and the vendors baked in, `APP_ENV=prod`, running as a
  non-root user. It is also the CLI image: database migrations, cron jobs and
  workers run with it;
* `nginx`: the official nginx image, the `public/` directory and the site
  configuration, forwarding PHP requests to that socket.

The application code comes from the `application/` directory
(`application/.dockerignore` lists what must not be baked in). Each build step
(`composer install`, `yarn build` / `npm run build`, AssetMapper compilation,
cache warmup) only runs when the application needs it: adapt the `app-build`
stage to your project.

Both images use the php-fpm and nginx configuration of the dev `frontend`
container (`services/php/php/` and `services/php/nginx/`); what production does
differently is in `services/php/php/mods-available/app-prod.ini`. Everything
else (secrets, database, ...) is provided through environment variables at
runtime.

The `prod` castor context runs the usual tasks on a dedicated compose stack
(`docker-compose.prod.yml`: postgres + the two images, no bind mount, no
router), independent from the development one:

```bash
castor build -c prod       # builds the php and nginx images
castor start -c prod       # starts the stack (and runs the migrations)
# -> http://127.0.0.1:8000 (HTTP_PORT=18000 castor start -c prod to change the port)
castor builder -c prod     # opens a shell in the php image
castor destroy -c prod     # removes the containers and the volumes
```

It uses dummy secrets: to test with real ones, put them in an
`application/.env.prod.local` file (ignored by git, loaded by the php
container).

To change this stack on your machine only (e.g. to mount a secret, or to
publish another port), add an `infrastructure/docker/docker-compose.prod.override.yml`
file (ignored by git): the `prod` context loads it after `docker-compose.prod.yml`,
like `docker-compose.override.yml` for the development stack.

`castor docker:push --tag=...` also pushes the images themselves (not only
their build cache). On every push to `main` (and on every git tag), the
`.github/workflows/build-push.yml` workflow pushes both images to
`ghcr.io/<repository>/php` and `ghcr.io/<repository>/nginx`, tagged with the
short commit sha, `latest` on `main`, and the tag name when there is one. This
workflow is disabled by default (and in docker-starter itself): set the
`PUSH_PRODUCTION_IMAGES` repository variable to `true` to enable it (Settings >
Secrets and variables > Actions > Variables). To push from your machine (you need to be logged in to the registry, and a buildx
builder able to export a registry cache, e.g. `docker buildx create --use`):

```bash
REGISTRY=ghcr.io/<org>/<repo> castor docker:push -c prod --tag=my-test
```

</details>

### How to use a docker registry to cache images layer

<details>

<summary>Read the cookbook</summary>

You can use a docker registry to cache images layer, it can be useful to speed
up the build process during the CI and local development.

First you need a docker registry, in following examples we will use the GitHub
registry (ghcr.io).

Then add the registry to the context variable of the `castor.php` file:

```php
function create_default_variables(): Context
{
    return [
        // [...]
        // The CI workflows override it with the REGISTRY environment variable
        'registry' => strtolower($_SERVER['REGISTRY'] ?? 'ghcr.io/your-organization/your-project'),
    ];
}
```

Once you have the registry, you can push the images to the registry:

```bash
castor docker:push
```

> Pushing image cache from a dev environment to a registry is not recommended,
> as cache may be sensitive to the environment and may not be compatible with
> other environments. It happens, for example, when you add some build args
> depending on your environment. It is recommended to push the cache from the CI
> environment.

This command will generate a bake file with the images to push from the
`cache_from` directive of the `docker-compose.yml` file. If you want to add more
images to push, you can add the `cache_from` directive to them.

```yaml
services:
    my-service:
        build:
            cache_from:
                - "type=registry,ref=${REGISTRY:-}/my-service:cache"
```

#### How to use cached images in a GitHub action

##### Pushing images to the registry from a GitHub action

1. Ensure that the github token have the `write:packages` scope:

```yaml
permissions:
    contents: read
    packages: write
```

2. Install Docker buildx in the github action:

```yaml
    - name: Set up Docker Buildx
      uses: docker/setup-buildx-action@v2
```

3. Login to the registry:

```yaml
    - name: Log in to registry
      shell: bash
      run: echo "${{ secrets.GITHUB_TOKEN }}" | docker login ghcr.io -u "${{ github.actor }}" --password-stdin
```

##### Using the cached images in GitHub action

By default images are private in the GitHub registry, you will need to login to
the registry to pull the images:

```yaml
    - name: Log in to registry
      shell: bash
      run: echo "${{ secrets.GITHUB_TOKEN }}" | docker login ghcr.io -u "${{ github.actor }}" --password-stdin
```

</details>

## Credits

Docker-starter logo was created by [Caneco](https://twitter.com/caneco).

<br><br>
<div align="center">
<a href="https://jolicode.com/"><img src="https://jolicode.com/media/original/oss/footer-github.png?v3" alt="JoliCode is sponsoring this project"></a>
</div>
