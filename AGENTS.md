# AGENTS.md

Instructions for AI agents working on this project.

## Fundamental rule: never run PHP/Node/Composer on the host machine

Everything goes through the `builder` container, via Castor:

```bash
castor builder -- bin/console cache:clear    # one-off command
castor builder -- bin/console make:migration # one-off command
```

Host prerequisites only: Docker, Bash, Castor.

## Essential commands

```bash
castor start                                 # build + install + up + migrate
castor stop                                  # stop the stack
castor logs [--service=service]              # logs (frontend, postgres, ...)
castor app:install                           # composer install + yarn/npm ci + importmap + qa:install
castor app:db:migrate                        # Doctrine migrations (alias: castor migrate)
castor app:db:fixtures                       # fixtures (alias: castor fixtures)
castor postgres -- select 1 from foobar      # one-off command database query
```

Docker / workers:

```bash
castor docker:build [--service=service]
castor docker:up [--service=service]
castor start-workers                         # start workers (worker profile)
castor stop-workers
```

## Castor contexts

The context changes how tasks are executed (`APP_ENV`, compose files, etc.):

```bash
castor --context=test qa:phpunit             # APP_ENV=test, for tests
castor --context=ci ...                      # like test, tuned for CI
castor --context=prod ...                    # production images on a dedicated local stack (docker-compose.prod.yml)
```

Always run tests and anything touching the database with `--context=test`.
Without option, the `default` context applies.

## Stack

- Symfony in `application/` (docroot = `application/public`), see the `app_dir` variable in `castor.php`
- Services (PostgreSQL, Redis...) are extensions, in `infrastructure/docker/extensions/<name>/`
  (see the `README.md` of each one): PostgreSQL 16 by default, user/pass/db = `app`/`app`.
  Add or remove one with `castor starter:add <name>` / `castor starter:remove <name>`
- nginx + php-fpm (service `frontend`), or FrankenPHP with `'php_server' => 'frankenphp'` in
  `castor.php`; Traefik router, HTTPS on `<root_domain>` (see `castor.php`)
- Node/yarn only inside the `builder` container
- Production ships as two images (`php` and `nginx`), built from the "Production stages" of
  `infrastructure/docker/services/php/Dockerfile` and pushed by `.github/workflows/build-push.yml`.
  php-fpm and nginx configuration (`services/php/php/`, `services/php/nginx/`) is shared with
  the dev `frontend` container

## QA — before considering a task done

Tools run inside the builder.

```bash
castor qa                                    # everything: cs + phpstan + twig-cs + lint + phpunit
castor qa:cs [--dry-run]                     # PHP-CS-Fixer (.php-cs-fixer.php)
castor qa:phpstan [-b]                       # PHPStan level 8 (phpstan.neon)
castor qa:twig-cs                            # Twig-CS-Fixer
castor qa:lint                               # lint:yaml, lint:twig, doctrine:schema:validate
castor qa:phpunit                            # PHPUnit (if present in application/)
```

After any PHP/Twig code change: `castor qa:cs --dry-run`,
`castor qa:phpstan`, then `castor qa:phpunit`.

## Conventions

1. **Never invoke `docker compose` by hand**: use the `docker_compose()` /
   `docker_compose_run()` functions from `.castor/starter/docker.php` to write
   new tasks.
2. **Never hardcode ports or project names**: git worktree support automatically
   isolates project/volumes/ports. Use `variable('project_name')` etc.
3. New recurring task? Make it a Castor task (`castor.php` or `.castor/*.php`),
   not a shell script.
4. **In a project derived from docker-starter, never edit `.castor/starter/`**:
   it is read-only, like a vendor directory, and `castor starter:sync` updates
   it (with the other files coming from docker-starter). Configure these tasks
   with the variables of `create_default_variables()` in `castor.php`, add tasks
   in `castor.php` or in other files of `.castor/`, and change a shared task
   with a Castor listener. In docker-starter itself, where these files are the
   source, run `castor test:starter` after changing them.
5. **In a project derived from docker-starter, change the `Dockerfile` and the
   compose files coming from docker-starter as little as possible**: each
   changed line can conflict on the next `castor starter:sync`. Use the
   variables of `castor.php` (`php_extensions`, `system_packages`, `nodejs`,
   `writable_dirs`...), the extensions of docker-starter (`castor starter:add`),
   the PHP settings of `services/php/php/mods-available/app-project.ini` and the
   nginx directives of `services/php/nginx/project.conf`, put the services of
   the project in `infrastructure/docker/docker-compose.project.yml` (and
   `docker-compose.prod.project.yml`), and its own images in their own
   directory of `infrastructure/docker/services/`. Never change the version of a
   database in `docker-compose.version.yml` without a migration of its data.
6. QA tool dependencies live in `tools/<tool>/composer.json`
   (not in `application/composer.json`).
