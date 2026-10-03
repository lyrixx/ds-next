<?php

// Shared by docker-starter with its projects: change it in docker-starter, never in a project (see index.php).

namespace starter;

use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;
use Castor\Context;

use function Castor\context;
use function Castor\fs;
use function Castor\io;
use function Castor\run;
use function Castor\variable;

/*
 * Updates the files of a project that come from docker-starter (Dockerfile, compose
 * files, castor tasks...), with a 3-way merge between the docker-starter version the
 * project is based on, the new docker-starter version, and the project: local changes
 * are kept, and conflicts are left with the usual git markers.
 *
 * The docker-starter version the project is based on, and the files of the project to
 * never touch, are stored in the .docker-starter.json file, at the root of the project.
 *
 * This file is self-contained, so that a project created before this task existed can
 * fetch it alone to run its first update (see the README of docker-starter).
 */

const SYNC_CONFIG_FILE = '.docker-starter.json';
const SYNC_DEFAULT_REPOSITORY = 'https://github.com/jolicode/docker-starter';
// The application directory in docker-starter, mapped to the "app_dir" variable of the project
const SYNC_STARTER_APP_DIR = 'application';
// The label of the project side of the conflicts, and the first line of a conflict
const SYNC_PROJECT_LABEL = 'project';
const SYNC_CONFLICT_MARKER = '<<<<<<< ' . SYNC_PROJECT_LABEL;
// The files of docker-starter itself (this one included: the task reads it from
// docker-starter, never from the project), or meant to be adapted to each project, that
// the task never touches (added to the "exclude" of .docker-starter.json). A trailing
// slash excludes a whole directory, and shell wildcards are supported.
const SYNC_DEFAULT_EXCLUDE_FILE = '.castor/starter/sync-default-exclude.json';

// What the update of a file did to the project (or would do, in a dry run)
const SYNC_UNCHANGED = 'unchanged';
const SYNC_CHANGED = 'changed';
// Written with conflict markers, or left to update by hand
const SYNC_CONFLICT = 'conflict';

#[AsTask(description: 'Updates the files coming from docker-starter, with a 3-way merge', namespace: 'starter', name: 'sync')]
function sync(
    #[AsOption(description: 'The docker-starter version to update to (branch, tag or commit, default: the default branch)')]
    string $to = 'HEAD',
    #[AsOption(description: 'The docker-starter version the project is based on (default: the "ref" of .docker-starter.json)')]
    ?string $from = null,
    #[AsOption(description: 'The docker-starter git repository (default: the "repository" of .docker-starter.json)')]
    ?string $repository = null,
    #[AsOption(description: 'Show what would change, without writing anything')]
    bool $dryRun = false,
    #[AsOption(description: 'Run even if the git working tree has uncommitted changes')]
    bool $allowDirty = false,
): int {
    io()->title('Updating the files coming from docker-starter');

    $root = variable('root_dir');
    $configFile = $root . '/' . SYNC_CONFIG_FILE;

    if (is_file($configFile)) {
        /** @var array{repository?: string, ref?: string, exclude?: list<string>} $config */
        $config = json_decode((string) file_get_contents($configFile), true, flags: JSON_THROW_ON_ERROR);
    } elseif (null !== $from) {
        $config = ['repository' => SYNC_DEFAULT_REPOSITORY, 'exclude' => []];
    } else {
        io()->error(\sprintf('The "%s" file does not exist. Run this task with "--from=<ref>", the docker-starter commit (or tag) the project is based on, to create it.', SYNC_CONFIG_FILE));

        return 1;
    }

    $from ??= $config['ref'] ?? null;
    if (null === $from) {
        io()->error(\sprintf('The "%s" file has no "ref". Run this task with "--from=<ref>".', SYNC_CONFIG_FILE));

        return 1;
    }

    $config['repository'] = $repository ?? $config['repository'] ?? SYNC_DEFAULT_REPOSITORY;

    if (!$dryRun && !$allowDirty && sync_is_dirty($root)) {
        io()->error('The git working tree has uncommitted changes: commit or stash them first, so the update can be reviewed (or use --allow-dirty).');

        return 1;
    }

    $clone = sync_clone($config['repository']);

    try {
        $fromSha = sync_resolve($clone, $from);
        $toSha = sync_resolve($clone, $to);

        io()->comment(\sprintf('From %s to %s (%s)', substr($fromSha, 0, 7), substr($toSha, 0, 7), $config['repository']));

        $exclude = [...sync_read_default_exclude($clone, $toSha), ...($config['exclude'] ?? [])];
        if (sync_uses_package($root)) {
            // The shared castor tasks are updated with `castor composer update`
            $exclude[] = '.castor/starter/';
        }

        $appDir = trim((string) variable('app_dir', SYNC_STARTER_APP_DIR), '/');

        // Even with --allow-dirty: the conflicts of the previous update would be reported as
        // up to date, its "ref" being already the base of this one
        $unresolved = sync_get_unresolved_conflicts($clone, $fromSha, $exclude, $root, $appDir);
        if ($unresolved) {
            // The marker on its own line, so that it is never wrapped
            io()->error([
                \sprintf('%d file(s) still have conflicts of the previous update: resolve them, then commit. Their conflicts start with:', \count($unresolved)),
                SYNC_CONFLICT_MARKER,
            ]);
            io()->listing($unresolved);

            return 1;
        }

        $rows = [];
        $changes = 0;
        $conflicts = 0;
        $configChanged = false;

        foreach (sync_get_changes($clone, $fromSha, $toSha, $exclude) as [$status, $oldPath, $newPath]) {
            $result = sync_apply_change(
                $clone,
                $fromSha,
                $toSha,
                $status,
                $oldPath,
                $newPath,
                $root,
                sync_map_path($oldPath, $appDir),
                sync_map_path($newPath, $appDir),
                $dryRun,
            );

            if (null === $result) {
                continue;
            }

            [$message, $outcome] = $result;
            $changes += (int) (SYNC_CHANGED === $outcome);
            $conflicts += (int) (SYNC_CONFLICT === $outcome);
            $target = sync_map_path($newPath, $appDir);
            $rows[] = [SYNC_CONFLICT === $outcome ? "<error>{$target}</error>" : $target, $message];
        }

        if ($rows) {
            io()->table(['File', 'Result'], $rows);
        } else {
            io()->comment('No file of the project to update.');
        }

        if (!$dryRun) {
            $config['ref'] = $toSha;
            $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            $configChanged = !is_file($configFile) || file_get_contents($configFile) !== $json;
            fs()->dumpFile($configFile, $json);
        }
    } finally {
        fs()->remove($clone);
    }

    if ($dryRun) {
        io()->note('Dry run: nothing has been written.');
    }

    if ($conflicts) {
        io()->warning([
            \sprintf('%d file(s) with conflicts: resolve them, then commit. The conflicts start with:', $conflicts),
            SYNC_CONFLICT_MARKER,
            'Castor does not start while a file of .castor/starter/ has conflicts: resolve them first, with a text editor.',
        ]);

        return 1;
    }

    if (!$changes) {
        io()->success($configChanged
            ? \sprintf('The files of the project are already up to date: commit the new "ref" of %s.', SYNC_CONFIG_FILE)
            : 'The project is already up to date with docker-starter.');
    } elseif (!$dryRun) {
        io()->success('The project is up to date with docker-starter. Review the changes, then commit.');
    }

    return 0;
}

/**
 * Whether the file has parts which only test docker-starter itself, removed by
 * strip_docker_starter_only(): the GitHub files (workflows, Dependabot...).
 */
function has_docker_starter_only_parts(string $path): bool
{
    return str_starts_with($path, '.github/') && str_ends_with($path, '.yml');
}

/**
 * Removes from a GitHub file of docker-starter what only tests docker-starter itself:
 * the "docker-starter only" blocks, and the PHP versions matrix from the job names.
 * `castor init` applies it to the files of the new project, and `castor starter:sync`
 * to the docker-starter versions it merges into the project.
 */
function strip_docker_starter_only(string $content): string
{
    $content = (string) preg_replace('{^[ \t]*# >>> docker-starter only.*?^[ \t]*# <<< docker-starter only\n}ms', '', $content);
    // The PHP versions matrix is gone
    $content = str_replace(' with PHP ${{ matrix.php-version }}', '', $content);

    return rtrim($content) . "\n";
}

/**
 * @param list<string> $exclude
 *
 * @return list<array{string, string, string}> status (A, M, D, R...), old path, new path
 */
function sync_get_changes(string $clone, string $fromSha, string $toSha, array $exclude): array
{
    $output = sync_git(['diff', '--name-status', '-z', '-M', $fromSha, $toSha], $clone);
    $tokens = explode("\0", rtrim($output, "\0"));
    $changes = [];

    for ($i = 0; $i < \count($tokens) && '' !== $tokens[$i];) {
        $status = $tokens[$i++];
        $oldPath = $tokens[$i++];
        $newPath = \in_array($status[0], ['R', 'C'], true) ? $tokens[$i++] : $oldPath;

        if (sync_is_excluded($oldPath, $exclude) && sync_is_excluded($newPath, $exclude)) {
            continue;
        }

        $changes[] = [$status[0], $oldPath, $newPath];
    }

    return $changes;
}

/**
 * @return array{string, 'unchanged'|'changed'|'conflict'}|null a message and what the update did (SYNC_*), or null when there is nothing to report
 */
function sync_apply_change(
    string $clone,
    string $fromSha,
    string $toSha,
    string $status,
    string $oldPath,
    string $newPath,
    string $root,
    string $oldTarget,
    string $newTarget,
    bool $dryRun,
): ?array {
    $oldFile = "{$root}/{$oldTarget}";
    $newFile = "{$root}/{$newTarget}";

    if ('A' === $status) {
        $theirs = sync_read_starter_file($clone, $toSha, $newPath);

        if (!is_file($newFile)) {
            if (!$dryRun) {
                sync_write($newFile, $theirs, sync_is_executable($clone, $toSha, $newPath));
            }

            return ['created', SYNC_CHANGED];
        }

        return sync_merge_into((string) file_get_contents($newFile), '', $theirs, $newFile, $fromSha, $toSha, $dryRun, 'already exists in the project');
    }

    if ('D' === $status) {
        if (!is_file($oldFile)) {
            return null;
        }

        if (file_get_contents($oldFile) !== sync_read_starter_file($clone, $fromSha, $oldPath)) {
            return ['kept: deleted in docker-starter, but modified in the project', SYNC_UNCHANGED];
        }

        if (!$dryRun) {
            fs()->remove($oldFile);
        }

        return ['deleted', SYNC_CHANGED];
    }

    if (!\in_array($status, ['M', 'R', 'C'], true)) {
        return [\sprintf('skipped: unsupported change "%s", update it by hand', $status), SYNC_UNCHANGED];
    }

    // Already moved in the project
    if (!is_file($oldFile) && 'R' === $status && is_file($newFile)) {
        $oldFile = $newFile;
    }

    if (!is_file($oldFile)) {
        return ['skipped: not in the project', SYNC_UNCHANGED];
    }

    $base = sync_read_starter_file($clone, $fromSha, $oldPath);
    $theirs = sync_read_starter_file($clone, $toSha, $newPath);
    $ours = (string) file_get_contents($oldFile);
    $moved = 'C' !== $status && $oldFile !== $newFile;

    $result = sync_merge_into($ours, $base, $theirs, $newFile, $fromSha, $toSha, $dryRun);

    if ('C' !== $status && $moved && !$dryRun) {
        fs()->remove($oldFile);
    }

    if ($moved) {
        // Even when its content is already up to date
        return ["moved from {$oldTarget}, {$result[0]}", SYNC_CONFLICT === $result[1] ? SYNC_CONFLICT : SYNC_CHANGED];
    }

    return $result;
}

/**
 * Writes the 3-way merge of $ours (the project), $base (the old docker-starter) and
 * $theirs (the new docker-starter) into $file.
 *
 * @return array{string, 'unchanged'|'changed'|'conflict'} a message and what the update did (SYNC_*)
 */
function sync_merge_into(string $ours, string $base, string $theirs, string $file, string $fromSha, string $toSha, bool $dryRun, string $context = ''): array
{
    $prefix = $context ? "{$context}, " : '';

    if ($ours === $theirs) {
        if (!$dryRun && !is_file($file)) {
            sync_write($file, $theirs);
        }

        return ["{$prefix}already up to date", SYNC_UNCHANGED];
    }

    // Nothing changed for the project (e.g. only the docker-starter only parts of a workflow)
    if ($base === $theirs) {
        if (!$dryRun && !is_file($file)) {
            sync_write($file, $ours);
        }

        return ["{$prefix}already up to date", SYNC_UNCHANGED];
    }

    if ($ours === $base) {
        if (!$dryRun) {
            sync_write($file, $theirs);
        }

        return ["{$prefix}updated", SYNC_CHANGED];
    }

    if (str_contains($ours . $base . $theirs, "\0")) {
        return ["{$prefix}<error>conflict</error>: binary file modified in the project, update it by hand", SYNC_CONFLICT];
    }

    $dir = fs()->tempnam(sys_get_temp_dir(), 'docker-starter-merge-');
    fs()->remove($dir);
    fs()->mkdir($dir);

    try {
        fs()->dumpFile("{$dir}/ours", $ours);
        fs()->dumpFile("{$dir}/base", $base);
        fs()->dumpFile("{$dir}/theirs", $theirs);

        $process = run(
            ['git', 'merge-file', '-p', '-L', SYNC_PROJECT_LABEL, '-L', 'docker-starter ' . substr($fromSha, 0, 7), '-L', 'docker-starter ' . substr($toSha, 0, 7), "{$dir}/ours", "{$dir}/base", "{$dir}/theirs"],
            context: sync_context()->withAllowFailure(),
        );
    } finally {
        fs()->remove($dir);
    }

    // The exit code is the number of conflicts, or negative on error
    $exitCode = (int) $process->getExitCode();
    if ($exitCode > 127) {
        throw new \RuntimeException(\sprintf('Could not merge "%s": %s', $file, $process->getErrorOutput()));
    }

    if (!$dryRun) {
        sync_write($file, $process->getOutput());
    }

    if ($exitCode > 0) {
        return ["{$prefix}<error>conflict</error>", SYNC_CONFLICT];
    }

    return ["{$prefix}merged with the changes of the project", SYNC_CHANGED];
}

function sync_map_path(string $path, string $appDir): string
{
    if (!str_starts_with($path, SYNC_STARTER_APP_DIR . '/') || SYNC_STARTER_APP_DIR === $appDir) {
        return $path;
    }

    $path = substr($path, \strlen(SYNC_STARTER_APP_DIR) + 1);

    return '' === $appDir ? $path : "{$appDir}/{$path}";
}

/**
 * @param list<string> $exclude
 */
function sync_is_excluded(string $path, array $exclude): bool
{
    foreach ($exclude as $pattern) {
        if (str_ends_with($pattern, '/') ? str_starts_with($path, $pattern) : fnmatch($pattern, $path, FNM_PATHNAME)) {
            return true;
        }
    }

    return false;
}

function sync_uses_package(string $root): bool
{
    $file = "{$root}/castor.composer.json";

    return is_file($file) && str_contains((string) file_get_contents($file), '"jolicode/docker-starter"');
}

function sync_is_dirty(string $root): bool
{
    $process = run(['git', 'status', '--porcelain'], context: sync_context()->withWorkingDirectory($root)->withAllowFailure());

    // Not a git repository: nothing to protect
    return 0 === $process->getExitCode() && '' !== trim($process->getOutput());
}

function sync_clone(string $repository): string
{
    $clone = fs()->tempnam(sys_get_temp_dir(), 'docker-starter-sync-');
    fs()->remove($clone);

    $command = ['git', 'clone', '--quiet', '--bare'];
    // Only download the files that changed (partial clone), for remote repositories
    if (!is_dir($repository)) {
        $command[] = '--filter=blob:none';
    }

    run([...$command, $repository, $clone], context: sync_context());

    return $clone;
}

function sync_resolve(string $clone, string $ref): string
{
    $process = run(['git', 'rev-parse', '--verify', '--quiet', "{$ref}^{commit}"], context: sync_context()->withWorkingDirectory($clone)->withAllowFailure());

    if (0 !== $process->getExitCode()) {
        throw new \RuntimeException(\sprintf('The docker-starter version "%s" does not exist.', $ref));
    }

    return trim($process->getOutput());
}

function sync_read_blob(string $clone, string $sha, string $path): string
{
    return sync_git(['cat-file', 'blob', "{$sha}:{$path}"], $clone);
}

/**
 * The default exclusions of the docker-starter version the project is updated to, not
 * those of this file (the old version): a file this version stops excluding then gets
 * all the changes the project missed.
 *
 * @return list<string>
 */
function sync_read_default_exclude(string $clone, string $sha): array
{
    if ('' === sync_git(['ls-tree', $sha, '--', SYNC_DEFAULT_EXCLUDE_FILE], $clone)) {
        throw new \RuntimeException(\sprintf('The docker-starter version %s is too old to update to: it has no "%s" file.', substr($sha, 0, 7), SYNC_DEFAULT_EXCLUDE_FILE));
    }

    return json_decode(sync_read_blob($clone, $sha, SYNC_DEFAULT_EXCLUDE_FILE), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * A file of docker-starter, as `castor init` gives it to a new project: the merges then
 * only see the differences that matter for the project.
 */
function sync_read_starter_file(string $clone, string $sha, string $path): string
{
    $content = sync_read_blob($clone, $sha, $path);

    return has_docker_starter_only_parts($path) ? strip_docker_starter_only($content) : $content;
}

/**
 * The files of the project docker-starter manages (those of the given version, but the
 * excluded ones) which still have conflicts of a previous update.
 *
 * @param list<string> $exclude
 *
 * @return list<string>
 */
function sync_get_unresolved_conflicts(string $clone, string $sha, array $exclude, string $root, string $appDir): array
{
    $files = [];

    foreach (explode("\0", rtrim(sync_git(['ls-tree', '-r', '-z', '--name-only', $sha], $clone), "\0")) as $path) {
        if ('' === $path || sync_is_excluded($path, $exclude)) {
            continue;
        }

        $target = sync_map_path($path, $appDir);
        $file = "{$root}/{$target}";

        if (is_file($file) && preg_match('{^' . preg_quote(SYNC_CONFLICT_MARKER) . '\r?$}m', (string) file_get_contents($file))) {
            $files[] = $target;
        }
    }

    return $files;
}

function sync_is_executable(string $clone, string $sha, string $path): bool
{
    return str_starts_with(sync_git(['ls-tree', $sha, '--', $path], $clone), '100755');
}

function sync_write(string $file, string $content, bool $executable = false): void
{
    fs()->dumpFile($file, $content);

    if ($executable) {
        fs()->chmod($file, 0o755);
    }
}

/**
 * @param list<string> $args
 */
function sync_git(array $args, string $cwd): string
{
    return run(['git', ...$args], context: sync_context()->withWorkingDirectory($cwd))->getOutput();
}

/**
 * Raw output (file contents): no PTY, which would convert line endings.
 */
function sync_context(): Context
{
    return context()->withPty(false)->withTty(false)->withQuiet();
}
