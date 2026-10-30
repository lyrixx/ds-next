<?php

use Castor\Attribute\AsTask;

use function Castor\context;
use function Castor\finder;
use function Castor\fs;
use function Castor\io;
use function Castor\run;
use function Castor\variable;
use function context\get_extensions;
use function docker\app_dir;
use function docker\build;
use function docker\docker_compose_run;
use function starter\has_docker_starter_only_parts;
use function starter\strip_docker_starter_only;

use const starter\EXTENSIONS_DIR;
use const starter\SYNC_CONFIG_FILE;
use const starter\SYNC_DEFAULT_REPOSITORY;

// The extensions of a new project: `castor starter:add` adds the others
const INIT_DEFAULT_EXTENSIONS = ['postgres'];

#[AsTask(description: 'Initialize the project')]
function init(): void
{
    foreach (get_extensions(variable('root_dir')) as $extension) {
        if (!in_array($extension, INIT_DEFAULT_EXTENSIONS, true)) {
            fs()->remove(EXTENSIONS_DIR . "/{$extension}");
        }
    }

    // The docker-starter version of the project, for `castor starter:sync` and `castor
    // starter:add`: only known in a clone of docker-starter, not in a copy of its files
    // (e.g. a repository created from the GitHub template)
    $origin = run(['git', 'remote', 'get-url', 'origin'], context: context()->withQuiet()->withAllowFailure());
    if ($origin->isSuccessful() && str_contains($origin->getOutput(), 'jolicode/docker-starter')) {
        $ref = trim(run(['git', 'rev-parse', 'HEAD'], context: context()->withQuiet())->getOutput());
        fs()->dumpFile(SYNC_CONFIG_FILE, json_encode(['repository' => SYNC_DEFAULT_REPOSITORY, 'exclude' => [], 'ref' => $ref], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n");
    } else {
        io()->note(sprintf('Run "castor starter:sync --from=<commit>" with the docker-starter commit this project is created from, to record it in %s: "castor starter:sync" and "castor starter:add" need it.', SYNC_CONFIG_FILE));
    }

    // The CI is kept for the project, without what only tests docker-starter itself (as
    // `castor starter:sync` will compare it, see .castor/starter/sync.php)
    foreach (finder()->files()->in('.github') as $file) {
        $path = '.github/' . $file->getRelativePathname();

        if (has_docker_starter_only_parts($path)) {
            fs()->dumpFile($path, strip_docker_starter_only($file->getContents()));
        }
    }

    fs()->remove([
        // `castor starter:sync` reads them from docker-starter, never from the project
        '.castor/starter/sync-default-exclude.json',
        '.castor/starter/sync-project-files.json',
        '.castor/test',
        'doc/',
        'README.md',
        'CHANGELOG.md',
        'CONTRIBUTING.md',
        'LICENSE',
        __FILE__,
    ]);
    fs()->rename('README.dist.md', 'README.md');

    // The manifest of the docker-starter package (see .castor/starter/), unless the
    // application lives at the root of the repository and has its own
    $composerJson = json_decode((string) @file_get_contents('composer.json'), true);
    if ('jolicode/docker-starter' === ($composerJson['name'] ?? null)) {
        fs()->remove('composer.json');
    }

    $readMeContent = file_get_contents('README.md');

    if (false === $readMeContent) {
        return;
    }

    $urls = [variable('root_domain'), ...variable('extra_domains')];
    $readMeContent = str_replace('<your hostnames>', implode(' ', $urls), $readMeContent);
    file_put_contents('README.md', $readMeContent);
}

#[AsTask(description: 'Install Symfony')]
function symfony(bool $webApp = false): void
{
    $base = app_dir();

    $gitIgnore = $base . '/.gitignore';
    $gitIgnoreContent = '';
    if (file_exists($gitIgnore)) {
        $gitIgnoreContent = file_get_contents($gitIgnore);
    }

    build();
    docker_compose_run(['composer', 'create-project', 'symfony/skeleton', 'sf']);

    fs()->mirror($base . '/sf/', $base, options: ['override' => true]);
    fs()->remove([$base . '/sf', $base . '/var']);

    if ($webApp) {
        docker_compose_run(['composer', 'require', 'webapp']);
    }

    docker_compose_run(['sed', '-i', 's#^DATABASE_URL.*#DATABASE_URL=postgresql://app:app@postgres:5432/app\?serverVersion=16\&charset=utf8#', '.env']);
    file_put_contents($gitIgnore, $gitIgnoreContent, \FILE_APPEND);
}

#[AsTask(description: 'Install Sylius')]
function sylius(): void
{
    $base = app_dir();

    $gitIgnore = $base . '/.gitignore';
    $gitIgnoreContent = '';
    if (file_exists($gitIgnore)) {
        $gitIgnoreContent = file_get_contents($gitIgnore);
    }

    build();
    docker_compose_run(['composer', 'create-project', 'sylius/sylius-standard', 'sylius']);

    fs()->mirror($base . '/sylius/', $base, options: ['override' => true]);
    fs()->remove([$base . '/sylius', $base . '/var']);

    docker_compose_run(['sed', '-i', 's#^DATABASE_URL.*#DATABASE_URL=postgresql://app:app@postgres:5432/app\?serverVersion=16\&charset=utf8#', '.env']);
    file_put_contents($gitIgnore, $gitIgnoreContent, \FILE_APPEND);

    io()->note('The tests of Sylius need a headless Chrome: run "castor starter:add chrome" after "castor init".');
}
