<?php

namespace qa;

use Castor\Attribute\AsOption;
use Castor\Attribute\AsRawTokens;
use Castor\Attribute\AsTask;

use function Castor\io;
use function Castor\variable;
use function docker\app_dir;
use function docker\docker_compose_run;
use function docker\docker_exit_code;

#[AsTask(description: 'Runs all QA tasks', aliases: ['qa'])]
function all(): int
{
    $cs = cs();
    $phpstan = phpstan();
    $twigCs = twig_cs();
    $lint = lint();
    $phpunit = phpunit();

    return max($cs, $phpstan, $twigCs, $lint, $phpunit);
}

#[AsTask(description: 'Installs tooling')]
function install(): void
{
    io()->title('Installing QA tooling');

    foreach (get_tools() as $tool) {
        docker_compose_run(['composer', 'install', '-o'], workDir: "/var/www/tools/{$tool}");
    }
}

#[AsTask(description: 'Updates tooling')]
function update(): void
{
    io()->title('Updating QA tooling');

    foreach (get_tools() as $tool) {
        docker_compose_run(['composer', 'update', '-o'], workDir: "/var/www/tools/{$tool}");
    }
}

/**
 * The tools of tools/, those of docker-starter and those of the project (e.g. a
 * tools/rector/composer.json): each one is a Composer project.
 *
 * @return list<string>
 */
function get_tools(): array
{
    $files = glob(variable('root_dir') . '/tools/*/composer.json') ?: [];

    return array_map(static fn (string $file) => basename(\dirname($file)), $files);
}

/**
 * @param list<string> $rawTokens
 */
#[AsTask(description: 'Runs PHPUnit', aliases: ['phpunit'])]
function phpunit(#[AsRawTokens] array $rawTokens = []): int
{
    $binary = phpunit_binary();

    if (null === $binary) {
        return 0;
    }

    io()->section('Running PHPUnit...');

    return docker_exit_code([$binary, ...$rawTokens]);
}

/**
 * The PHPUnit binary of the application, relative to its directory: the "bin/phpunit"
 * wrapper of the Symfony recipe when there is one, the Composer one otherwise.
 */
function phpunit_binary(): ?string
{
    foreach (['bin/phpunit', 'vendor/bin/phpunit'] as $binary) {
        if (is_file(app_dir() . '/' . $binary)) {
            return $binary;
        }
    }

    return null;
}

#[AsTask(description: 'Runs PHPStan', aliases: ['phpstan'])]
function phpstan(
    #[AsOption(description: 'Generate baseline file', shortcut: 'b')]
    bool $baseline = false,
): int {
    if (!is_dir(variable('root_dir') . '/tools/phpstan/vendor')) {
        install();
    }

    io()->section('Running PHPStan...');

    $command = ['phpstan', 'analyse', '--memory-limit=-1', '-v'];
    if ($baseline) {
        $command = [...$command, '--generate-baseline', '--allow-empty-baseline'];
    }

    return docker_exit_code($command, workDir: '/var/www');
}

/**
 * The linters of Symfony and Doctrine, when the application has them: they check what
 * the static analysis does not see (the syntax of the configuration and of the
 * templates, the mapping of the entities against the database).
 */
#[AsTask(description: 'Lints the YAML configuration and the Twig templates, and validates the Doctrine mapping', aliases: ['lint'])]
function lint(): int
{
    $base = app_dir();

    if (!is_file("{$base}/bin/console")) {
        return 0;
    }

    $linters = [];
    if (is_dir("{$base}/config") && is_dir("{$base}/vendor/symfony/yaml")) {
        $linters['YAML'] = ['bin/console', 'lint:yaml', '--parse-tags', 'config/'];
    }
    if (is_dir("{$base}/templates") && is_dir("{$base}/vendor/symfony/twig-bundle")) {
        $linters['Twig'] = ['bin/console', 'lint:twig', 'templates/'];
    }
    // Needs the database, migrated: it also checks that the mapping matches it
    if (is_dir("{$base}/vendor/doctrine/doctrine-bundle")) {
        $linters['Doctrine mapping'] = ['bin/console', 'doctrine:schema:validate'];
    }

    $exitCode = 0;
    foreach ($linters as $name => $command) {
        io()->section("Running the {$name} linter...");
        $exitCode = max($exitCode, docker_exit_code($command));
    }

    return $exitCode;
}

#[AsTask(description: 'Runs Security audit')]
function security_audit(): int
{
    $basePath = app_dir();

    if (is_file("{$basePath}/composer.lock")) {
        io()->text('Running Composer audit...');

        $exitCode = docker_exit_code(['composer', 'audit']);

        if (0 !== $exitCode) {
            return $exitCode;
        }
    }

    if (is_file("{$basePath}/yarn.lock")) {
        io()->text('Running Yarn audit...');

        // "yarn audit" only exists in Yarn 1: Yarn 2+ (the one of the builder) has "yarn
        // npm audit", which only audits the direct dependencies by default
        $yarnClassic = str_contains((string) file_get_contents("{$basePath}/yarn.lock"), '# yarn lockfile v1');
        $exitCode = docker_exit_code($yarnClassic ? ['yarn', 'audit'] : ['yarn', 'npm', 'audit', '--all', '--recursive']);

        if (0 !== $exitCode) {
            return $exitCode;
        }
    }

    if (is_file("{$basePath}/package-lock.json")) {
        io()->text('Running NPM audit...');

        return docker_exit_code(['npm', 'audit']);
    }

    return 0;
}

#[AsTask(description: 'Fixes Coding Style', aliases: ['cs'])]
function cs(bool $dryRun = false): int
{
    if (!is_dir(variable('root_dir') . '/tools/php-cs-fixer/vendor')) {
        install();
    }

    io()->section('Running PHP CS Fixer...');

    if ($dryRun) {
        return docker_exit_code(['php-cs-fixer', 'fix', '--dry-run', '--diff'], workDir: '/var/www');
    }

    return docker_exit_code(['php-cs-fixer', 'fix', '-v'], workDir: '/var/www');
}

#[AsTask(description: 'Fixes Twig Coding Style', aliases: ['twig-cs'])]
function twig_cs(bool $dryRun = false): int
{
    if (!is_dir(variable('root_dir') . '/tools/twig-cs-fixer/vendor')) {
        install();
    }

    io()->section('Running Twig CS Fixer...');

    if ($dryRun) {
        return docker_exit_code(['twig-cs-fixer'], workDir: '/var/www');
    }

    return docker_exit_code(['twig-cs-fixer', '--fix'], workDir: '/var/www');
}
