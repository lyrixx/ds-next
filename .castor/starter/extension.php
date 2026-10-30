<?php

// Shared by docker-starter with its projects: change it in docker-starter, never in a project (see index.php).

namespace starter;

use Castor\Attribute\AsArgument;
use Castor\Attribute\AsTask;

use function Castor\context;
use function Castor\fs;
use function Castor\io;
use function Castor\variable;
use function docker\docker_compose;

/*
 * Adds an extension of docker-starter to the project, or removes one (see the README of
 * the demo extension): the extensions of a project are the directories of
 * infrastructure/docker/extensions/, which `castor starter:sync` then updates.
 */

#[AsTask(description: 'Adds an extension of docker-starter to the project (Redis, RabbitMQ...)', namespace: 'starter', name: 'add')]
function add_extension(
    #[AsArgument(description: 'The name of the extension, e.g. redis')]
    string $name,
): int {
    io()->title(\sprintf('Adding the "%s" extension', $name));

    $root = variable('root_dir');
    $dir = EXTENSIONS_DIR . "/{$name}";

    if (!is_valid_extension_name($name)) {
        io()->error(\sprintf('"%s" is not the name of an extension, e.g. "redis".', $name));

        return 1;
    }

    if (is_dir("{$root}/{$dir}")) {
        io()->error(\sprintf('The project already has the "%s" extension, in %s/.', $name, $dir));

        return 1;
    }

    $configFile = "{$root}/" . SYNC_CONFIG_FILE;
    if (!is_file($configFile)) {
        io()->error(\sprintf('The "%s" file does not exist: run "castor starter:sync --from=<ref>" first, to record the docker-starter version the project is based on. Its extensions come from this version.', SYNC_CONFIG_FILE));

        return 1;
    }

    /** @var array{repository?: string, ref?: string} $config */
    $config = json_decode((string) file_get_contents($configFile), true, flags: JSON_THROW_ON_ERROR);
    if (!isset($config['ref'])) {
        io()->error(\sprintf('The "%s" file has no "ref": run "castor starter:sync --from=<ref>" first.', SYNC_CONFIG_FILE));

        return 1;
    }

    $clone = sync_clone($config['repository'] ?? SYNC_DEFAULT_REPOSITORY);

    try {
        $sha = sync_resolve($clone, $config['ref']);
        $files = list_starter_files($clone, $sha, "{$dir}/");

        if (!$files) {
            $extensions = array_map(basename(...), list_starter_files($clone, $sha, EXTENSIONS_DIR . '/', recursive: false));
            io()->error(\sprintf('docker-starter has no "%s" extension. Its extensions: %s.', $name, implode(', ', $extensions)));

            return 1;
        }

        // The files which belong to the project too (e.g. docker-compose.version.yml): the
        // update excludes them, but they are the starting point of the project
        foreach ($files as $path) {
            sync_write("{$root}/{$path}", sync_read_blob($clone, $sha, $path), sync_is_executable($clone, $sha, $path));
        }
    } finally {
        fs()->remove($clone);
    }

    io()->listing($files);
    io()->success([
        \sprintf('The "%s" extension is added (see %s/README.md): run "castor start" to rebuild and restart the stack, then commit.', $name, $dir),
    ]);

    return 0;
}

#[AsTask(description: 'Removes an extension from the project', namespace: 'starter', name: 'remove')]
function remove_extension(
    #[AsArgument(description: 'The name of the extension, e.g. redis')]
    string $name,
): int {
    io()->title(\sprintf('Removing the "%s" extension', $name));

    $root = variable('root_dir');
    $dir = EXTENSIONS_DIR . "/{$name}";

    if (!is_valid_extension_name($name) || !is_dir("{$root}/{$dir}")) {
        io()->error(\sprintf('The project has no "%s" extension in %s/.', $name, EXTENSIONS_DIR));

        return 1;
    }

    // While its compose files are still there: Compose would not know these containers anymore
    $services = get_extension_services($name);
    if (null === $services) {
        io()->warning('The stack cannot be read (is Docker running?), so the containers of the extension are not removed: "castor start" will list them as orphans.');
    } elseif ($services) {
        $process = docker_compose(['rm', '--stop', '--force', ...$services], context()->withAllowFailure(), profiles: ['*']);

        if (!$process->isSuccessful()) {
            io()->warning(\sprintf('The containers of the extension (%s) are not removed: "castor start" will list them as orphans.', implode(', ', $services)));
        }
    }

    fs()->remove("{$root}/{$dir}");

    io()->success(\sprintf('The "%s" extension is removed. Its volumes, and their data, are kept: see "docker volume ls". Run "castor start" to rebuild and restart the stack, then commit.', $name));

    return 0;
}

/**
 * Also rejects "..", "/"... which would point outside of the extensions directory.
 */
function is_valid_extension_name(string $name): bool
{
    return 1 === preg_match('{^[a-z0-9][a-z0-9_-]*$}', $name);
}

/**
 * The services the compose files of the extension add to the stack.
 *
 * @return list<string>|null null when Compose cannot read the stack
 */
function get_extension_services(string $name): ?array
{
    $c = context()->withQuiet()->withAllowFailure();
    $withoutExtension = $c->withData([
        'docker_compose_files' => array_values(array_filter(
            $c['docker_compose_files'],
            static fn (string $file) => !str_starts_with($file, "extensions/{$name}/"),
        )),
    ], recursive: false);

    $services = [];
    foreach ([$c, $withoutExtension] as $stack) {
        $process = docker_compose(['config', '--services'], $stack, profiles: ['*']);
        if (!$process->isSuccessful()) {
            return null;
        }

        $services[] = array_filter(explode("\n", $process->getOutput()));
    }

    return array_values(array_diff(...$services));
}

/**
 * The files under $prefix (a directory, with a trailing slash) at the given version.
 *
 * @return list<string>
 */
function list_starter_files(string $clone, string $sha, string $prefix, bool $recursive = true): array
{
    $args = ['ls-tree', '-z', '--name-only', $sha, '--', $prefix];
    if ($recursive) {
        array_splice($args, 1, 0, ['-r']);
    }

    return array_values(array_filter(explode("\0", sync_git($args, $clone))));
}
