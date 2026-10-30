<?php

// This file tests the castor tasks shared with the projects (.castor/starter/: context
// variables, paths, starter:sync...), against throwaway projects created in a temporary
// directory, which import them either from a copy of the directory, or as a Castor remote
// package. It needs neither Docker nor PHP on the host, only Castor and git. It is entirely self-contained: to drop
// it from a project generated from this template, just delete this file (`castor init`
// does it).

namespace test;

use Castor\Attribute\AsTask;

use function Castor\capture;
use function Castor\context;
use function Castor\fs;
use function Castor\io;
use function Castor\run;
use function Castor\variable;
use function starter\has_docker_starter_only_parts;
use function starter\strip_docker_starter_only;

use const starter\EXTENSIONS_DIR;
use const starter\SYNC_DEFAULT_EXCLUDE_FILE;
use const starter\SYNC_PROJECT_FILES_FILE;

const IMPORT_LOCAL = 'local';
const IMPORT_PACKAGE = 'package';

#[AsTask(description: 'Tests the castor tasks shared with the projects')]
function starter(): void
{
    io()->title('Testing the castor tasks shared with the projects');

    $failures = 0;

    $suites = [
        ['Imported from a copy of .castor/starter/', IMPORT_LOCAL, get_starter_test_cases()],
        ['Imported as a Castor remote package', IMPORT_PACKAGE, get_starter_test_cases()],
        ['starter:sync', IMPORT_LOCAL, get_sync_test_cases()],
    ];

    foreach ($suites as [$title, $import, $cases]) {
        io()->section($title);

        foreach ($cases as $name => $case) {
            $project = create_test_project($import);

            try {
                $case($project);
                io()->writeln("<info>✔</info> {$name}");
            } catch (TestFailure $e) {
                ++$failures;
                io()->writeln("<error>✘</error> {$name}");
                io()->writeln('  ' . str_replace("\n", "\n  ", $e->getMessage()));
            } finally {
                // The project, and the fake docker-starter repositories of the test
                fs()->remove(get_test_temp_dirs()->getArrayCopy());
                get_test_temp_dirs()->exchangeArray([]);
            }
        }
    }

    if ($failures) {
        throw new \RuntimeException(\sprintf('%d test(s) failed.', $failures));
    }

    io()->success('All the tests passed.');
}

/**
 * @return array<string, \Closure(string): void>
 */
function get_starter_test_cases(): array
{
    return [
        'The shared tasks are available' => static function (string $project): void {
            $commands = list_castor_commands($project);

            foreach (['docker:build', 'docker:push', 'qa:all', 'qa:phpunit', 'docker:ports', 'starter:sync', 'starter:add', 'starter:remove'] as $command) {
                assert_same(true, \in_array($command, $commands, true), "{$command} command");
            }
        },

        'The application lives in "application/" by default' => static function (string $project): void {
            $result = dump_project($project);

            assert_same(realpath($project), $result['data']['root_dir'], 'root_dir');
            assert_same('application', $result['data']['app_dir'], 'app_dir variable');
            assert_same("{$project}/application", $result['app_dir'], 'app_dir()');
            assert_same('/var/www/application', $result['container_app_dir'], 'container_app_dir()');
            assert_same('application', $result['compose_environment']['APP_DIR'], 'APP_DIR');
            assert_same('/var/www/application', $result['compose_environment']['APP_PATH'], 'APP_PATH');
        },

        'The application can live at the root of the repository' => static function (string $project): void {
            $result = dump_project($project, ['app_dir' => '']);

            assert_same($project, $result['app_dir'], 'app_dir()');
            assert_same('/var/www', $result['container_app_dir'], 'container_app_dir()');
            assert_same('', $result['compose_environment']['APP_DIR'], 'APP_DIR');
            assert_same('/var/www', $result['compose_environment']['APP_PATH'], 'APP_PATH');
        },

        'The application directory is normalized' => static function (string $project): void {
            $result = dump_project($project, ['app_dir' => '/app/']);

            assert_same('app', $result['data']['app_dir'], 'app_dir variable');
            assert_same("{$project}/app", $result['app_dir'], 'app_dir()');
            assert_same('/var/www/app', $result['container_app_dir'], 'container_app_dir()');
        },

        'PHPUnit is looked for in the application directory' => static function (string $project): void {
            assert_same(null, dump_project($project, ['app_dir' => ''])['phpunit_binary'], 'without PHPUnit');

            fs()->dumpFile("{$project}/vendor/bin/phpunit", '');
            assert_same('vendor/bin/phpunit', dump_project($project, ['app_dir' => ''])['phpunit_binary'], 'Composer binary');
            assert_same(null, dump_project($project)['phpunit_binary'], 'not in the application directory');

            fs()->dumpFile("{$project}/bin/phpunit", '');
            assert_same('bin/phpunit', dump_project($project, ['app_dir' => ''])['phpunit_binary'], 'Symfony wrapper');
        },

        'The HTTP port is configurable' => static function (string $project): void {
            assert_same('8000', dump_project($project)['compose_environment']['HTTP_PORT'], 'default');
            assert_same('8080', dump_project($project, ['http_port' => 8080])['compose_environment']['HTTP_PORT'], 'variable');
            assert_same('9000', dump_project($project, ['http_port' => 8080], environment: ['HTTP_PORT' => '9000'])['compose_environment']['HTTP_PORT'], 'environment variable');
        },

        'The images are configured with the variables' => static function (string $project): void {
            $default = [
                'APP_PATH' => '/var/www/application',
                'BUILDER_SYSTEM_PACKAGES' => '',
                'PHP_EXTENSIONS' => '',
                'PHP_SERVER' => 'fpm',
                'PHP_VERSION' => '8.5',
                'PROJECT_NAME' => 'app',
                'SYSTEM_PACKAGES' => '',
                'WRITABLE_DIRS' => '',
            ];
            assert_same($default, dump_project($project)['build_args'], 'default');

            $result = dump_project($project, [
                'php_extensions' => ['imagick', 'redis'],
                'system_packages' => ['imagemagick', 'ghostscript'],
                'builder_system_packages' => ['mariadb-client'],
                'nodejs' => false,
                'writable_dirs' => ['var/share', 'public/uploads'],
            ]);
            assert_same(array_replace($default, [
                'PHP_EXTENSIONS' => 'imagick redis',
                'SYSTEM_PACKAGES' => 'imagemagick ghostscript',
                'BUILDER_SYSTEM_PACKAGES' => 'mariadb-client',
                'WRITABLE_DIRS' => 'var/share public/uploads',
                'NODEJS_VERSION' => '',
            ]), $result['build_args'], 'variables');
        },

        'The version of Node.js is configurable' => static function (string $project): void {
            assert_same('22.x', dump_project($project, ['nodejs' => '22'])['build_args']['NODEJS_VERSION'], 'major version');
            assert_same('22.x', dump_project($project, ['nodejs' => 22])['build_args']['NODEJS_VERSION'], 'major version as an int');
            assert_same('22.x', dump_project($project, ['nodejs' => '22.x'])['build_args']['NODEJS_VERSION'], 'NodeSource version');
        },

        'The files of the project are part of the stack' => static function (string $project): void {
            $default = ['docker-compose.yml', 'docker-compose.dev.yml'];
            assert_same($default, dump_project($project)['data']['docker_compose_files'], 'default');
            assert_same($default, dump_project($project, context: 'ci')['data']['docker_compose_files'], 'ci context');

            fs()->dumpFile("{$project}/infrastructure/docker/docker-compose.override.yml", '');
            fs()->dumpFile("{$project}/infrastructure/docker/docker-compose.project.yml", '');
            assert_same([...$default, 'docker-compose.project.yml', 'docker-compose.override.yml'], dump_project($project)['data']['docker_compose_files'], 'files of the project, then local tweaks');
        },

        'The extensions are part of the stack' => static function (string $project): void {
            $extensions = "{$project}/infrastructure/docker/extensions";
            fs()->dumpFile("{$extensions}/a/docker-compose.yml", '');
            fs()->dumpFile("{$extensions}/a/docker-compose.prod.yml", '');
            fs()->dumpFile("{$extensions}/a/docker-compose.version.yml", '');
            fs()->dumpFile("{$extensions}/a/extension.json", '{"php_extensions": ["redis", "intl"]}');
            // No service in the production stack
            fs()->dumpFile("{$extensions}/b/docker-compose.yml", '');
            fs()->dumpFile("{$extensions}/b/extension.json", '{"php_extensions": ["redis"]}');
            fs()->mkdir("{$extensions}/c");
            fs()->dumpFile("{$project}/infrastructure/docker/docker-compose.project.yml", '');

            $result = dump_project($project, ['php_extensions' => ['imagick', 'redis']]);

            assert_same(['a', 'b', 'c'], $result['data']['extensions'], 'extensions');
            assert_same(['docker-compose.yml', 'docker-compose.dev.yml', 'extensions/a/docker-compose.yml', 'extensions/a/docker-compose.version.yml', 'extensions/b/docker-compose.yml', 'docker-compose.project.yml'], $result['data']['docker_compose_files'], 'compose files');
            assert_same('imagick redis intl', $result['build_args']['PHP_EXTENSIONS'], 'PHP extensions of the project, then of the extensions');
            assert_same(['docker-compose.prod.yml', 'extensions/a/docker-compose.prod.yml', 'extensions/a/docker-compose.version.yml'], dump_project($project, context: 'prod')['data']['docker_compose_files'], 'compose files of the production stack');
        },

        'An unknown key of extension.json is refused' => static function (string $project): void {
            fs()->dumpFile("{$project}/infrastructure/docker/extensions/a/extension.json", '{"php_extensions": [], "apt_packages": ["jq"]}');

            [$exitCode, $output] = run_castor($project, ['test-dump']);

            assert_same(true, 0 !== $exitCode, "exit code\n{$output}");
            assert_contains('unknown keys (apt_packages)', $output);
        },

        'The reverse proxies of the extensions are chained in front of the frontend' => static function (string $project): void {
            $environment = dump_project($project)['compose_environment'];
            assert_same('app-frontend', $environment['PROJECT_HTTP_SERVICE'], 'without reverse proxy');

            $extensions = "{$project}/infrastructure/docker/extensions";
            fs()->dumpFile("{$extensions}/a/extension.json", '{"reverse_proxy": {"service": "a-proxy", "port": 80}}');
            fs()->dumpFile("{$extensions}/b/extension.json", '{"reverse_proxy": {"service": "b", "port": 6081}}');
            fs()->mkdir("{$extensions}/c");

            $environment = dump_project($project)['compose_environment'];
            assert_same('app-a-proxy', $environment['PROJECT_HTTP_SERVICE'], 'the router sends the requests to the first one');
            assert_same('b:6081', $environment['A_PROXY_UPSTREAM'], 'which forwards them to the next one');
            assert_same('frontend:8080', $environment['B_UPSTREAM'], 'which forwards them to nginx');
        },

        'A reverse proxy needs a service and a port' => static function (string $project): void {
            fs()->dumpFile("{$project}/infrastructure/docker/extensions/a/extension.json", '{"reverse_proxy": {"service": "a"}}');

            [$exitCode, $output] = run_castor($project, ['test-dump']);

            assert_same(true, 0 !== $exitCode, "exit code\n{$output}");
            assert_contains('must be a service and its port', $output);
        },

        'The tasks of the extensions are available' => static function (string $project): void {
            fs()->dumpFile("{$project}/infrastructure/docker/extensions/a/tasks.php", <<<'PHP'
                <?php

                namespace a;

                use Castor\Attribute\AsTask;

                #[AsTask(namespace: 'a')]
                function hello(): void
                {
                }
                PHP);

            assert_same(true, \in_array('a:hello', list_castor_commands($project), true), 'a:hello command');
        },

        'The prod context runs a dedicated stack' => static function (string $project): void {
            $result = dump_project($project, context: 'prod');

            assert_same('prod', $result['context'], 'context');
            assert_same('app-prod', $result['data']['project_name'], 'project_name');
            assert_same(['docker-compose.prod.yml'], $result['data']['docker_compose_files'], 'docker_compose_files');
            assert_same('php', $result['data']['docker_compose_run_service'], 'docker_compose_run_service');
        },

        'The prod context picks the optional files' => static function (string $project): void {
            fs()->dumpFile("{$project}/infrastructure/docker/docker-compose.prod.override.yml", '');
            fs()->dumpFile("{$project}/infrastructure/docker/docker-compose.prod.project.yml", '');

            $result = dump_project($project, context: 'prod');

            assert_same(['docker-compose.prod.yml', 'docker-compose.prod.project.yml', 'docker-compose.prod.override.yml'], $result['data']['docker_compose_files'], 'docker_compose_files');
        },

        'FrankenPHP serves the application alone in the prod context' => static function (string $project): void {
            $result = dump_project($project, ['php_server' => 'frankenphp'], context: 'prod');

            assert_same('frankenphp', $result['build_args']['PHP_SERVER'], 'PHP_SERVER');
            assert_same(['docker-compose.prod.yml', 'docker-compose.prod.frankenphp.yml'], $result['data']['docker_compose_files'], 'docker_compose_files');
        },

        'An unknown PHP server is refused' => static function (string $project): void {
            [$exitCode, $output] = run_castor($project, ['test-dump'], ['php_server' => 'apache']);

            assert_same(true, 0 !== $exitCode, "exit code\n{$output}");
            assert_contains('must be "fpm" or "frankenphp", not "apache"', $output);
        },

        'The test context sets APP_ENV' => static function (string $project): void {
            assert_same('test', dump_project($project, context: 'test')['environment']['APP_ENV'] ?? null, 'APP_ENV');
        },
    ];
}

/**
 * @return array<string, \Closure(string): void>
 */
function get_sync_test_cases(): array
{
    return [
        'An untouched file is updated' => static function (string $project): void {
            [$starter, [$v1, $v2]] = create_fake_starter([['a.txt' => "1\n"], ['a.txt' => "2\n"]]);
            init_sync_project($project, $starter, $v1, ['a.txt' => "1\n"]);

            [$exitCode, $output] = run_sync($project);

            assert_same(0, $exitCode, "exit code\n{$output}");
            assert_same("2\n", file_get_contents("{$project}/a.txt"), 'a.txt');
            assert_same($v2, read_sync_config($project)['ref'], 'ref');
            assert_same($starter, read_sync_config($project)['repository'], 'repository');
            assert_contains('Review', $output);
        },

        'An update without changes says so' => static function (string $project): void {
            [$starter, [$v1, $v2]] = create_fake_starter([['a.txt' => "1\n", 'README.md' => "1\n"], ['a.txt' => "1\n", 'README.md' => "2\n"]]);
            init_sync_project($project, $starter, $v1, ['a.txt' => "1\n"], exclude: ['README.md']);

            [$exitCode, $output] = run_sync($project);

            assert_same(0, $exitCode, "exit code\n{$output}");
            assert_contains('The files of the project are already up to date', $output);
            assert_same(false, str_contains($output, 'Review'), "no review of the changes\n{$output}");
            assert_same($v2, read_sync_config($project)['ref'], 'ref');

            test_git(['commit', '--quiet', '--all', '-m', 'Update docker-starter'], $project);
            [$exitCode, $output] = run_sync($project);

            assert_same(0, $exitCode, "exit code when already up to date\n{$output}");
            assert_contains('The project is already up to date with docker-starter.', $output);
            assert_same('', test_git(['status', '--porcelain'], $project), 'git status');
        },

        'Changes of the project are kept' => static function (string $project): void {
            [$starter, [$v1]] = create_fake_starter([['a.txt' => "a\nb\nc\nd\ne\n"], ['a.txt' => "A\nb\nc\nd\ne\n"]]);
            init_sync_project($project, $starter, $v1, ['a.txt' => "a\nb\nc\nd\nE\n"]);

            [$exitCode, $output] = run_sync($project);

            assert_same(0, $exitCode, "exit code\n{$output}");
            assert_same("A\nb\nc\nd\nE\n", file_get_contents("{$project}/a.txt"), 'a.txt');
            assert_contains('merged with the changes of the project', $output);
        },

        'Conflicts are left with markers' => static function (string $project): void {
            [$starter, [$v1, $v2]] = create_fake_starter([['a.txt' => "a\n"], ['a.txt' => "b\n"]]);
            init_sync_project($project, $starter, $v1, ['a.txt' => "c\n"]);

            [$exitCode, $output] = run_sync($project);

            assert_same(1, $exitCode, "exit code\n{$output}");
            assert_contains('<<<<<<< project', (string) file_get_contents("{$project}/a.txt"));
            assert_contains('conflict', $output);
            assert_same($v2, read_sync_config($project)['ref'], 'ref');
        },

        'Unresolved conflicts block the next update' => static function (string $project): void {
            [$starter, [$v1, $v2]] = create_fake_starter([['a.txt' => "a\n"], ['a.txt' => "b\n"], ['a.txt' => "c\n"]]);
            init_sync_project($project, $starter, $v1, ['a.txt' => "x\n"]);

            [$exitCode, $output] = run_sync($project, ["--to={$v2}"]);
            assert_same(1, $exitCode, "exit code of the first update\n{$output}");
            assert_contains('Castor does not start', $output);

            [$exitCode, $output] = run_sync($project, ['--allow-dirty']);

            assert_same(1, $exitCode, "exit code with --allow-dirty\n{$output}");
            assert_contains('1 file(s) still have conflicts', $output);
            assert_contains('* a.txt', $output);
            assert_same($v2, read_sync_config($project)['ref'], 'ref');

            // Even once committed
            test_git(['commit', '--quiet', '--all', '-m', 'Update docker-starter'], $project);
            [$exitCode, $output] = run_sync($project);

            assert_same(1, $exitCode, "exit code with committed conflicts\n{$output}");

            fs()->dumpFile("{$project}/a.txt", "b\n");
            test_git(['commit', '--quiet', '--all', '-m', 'Resolve the conflicts'], $project);
            [$exitCode, $output] = run_sync($project);

            assert_same(0, $exitCode, "exit code once resolved\n{$output}");
            assert_same("c\n", file_get_contents("{$project}/a.txt"), 'a.txt');
        },

        'The docker-starter only parts of the GitHub files are not merged' => static function (string $project): void {
            $workflow = <<<'YAML'
                jobs:
                    # >>> docker-starter only
                    starter:
                        steps:
                            - uses: actions/checkout@v4
                    # <<< docker-starter only
                    ci:
                        name: CI with PHP ${{ matrix.php-version }}
                        steps:
                            - uses: actions/checkout@v4
                            - run: castor qa

                YAML;
            $onlyStarterChanged = str_replace('    starter:', '    starter-renamed:', $workflow);
            [$starter, [$v1, $v2]] = create_fake_starter([
                ['.github/workflows/ci.yml' => $workflow],
                ['.github/workflows/ci.yml' => str_replace('checkout@v4', 'checkout@v5', $workflow)],
                ['.github/workflows/ci.yml' => str_replace('checkout@v4', 'checkout@v5', $onlyStarterChanged)],
            ]);
            $projectJob = <<<'YAML'
                    translations:
                        steps:
                            - run: castor app:translations

                YAML;
            // As `castor init` leaves it, with a job of the project
            init_sync_project($project, $starter, $v1, ['.github/workflows/ci.yml' => <<<YAML
                jobs:
                    ci:
                        name: CI
                        steps:
                            - uses: actions/checkout@v4
                            - run: castor qa
                {$projectJob}
                YAML]);

            [$exitCode, $output] = run_sync($project, ["--to={$v2}"]);

            assert_same(0, $exitCode, "exit code\n{$output}");
            $expected = <<<YAML
                jobs:
                    ci:
                        name: CI
                        steps:
                            - uses: actions/checkout@v5
                            - run: castor qa
                {$projectJob}
                YAML;
            assert_same($expected, file_get_contents("{$project}/.github/workflows/ci.yml"), 'ci.yml');

            test_git(['commit', '--quiet', '--all', '-m', 'Update docker-starter'], $project);
            [$exitCode, $output] = run_sync($project);

            assert_same(0, $exitCode, "exit code when only the docker-starter only parts changed\n{$output}");
            assert_contains('already up to date', $output);
            assert_same($expected, file_get_contents("{$project}/.github/workflows/ci.yml"), 'ci.yml when only the docker-starter only parts changed');
        },

        'castor init prepares the files as starter:sync expects them' => static function (string $project): void {
            $root = variable('root_dir');
            assert_contains('# >>> docker-starter only', (string) file_get_contents("{$root}/.github/workflows/ci.yml"));

            // A new project, from the files of this repository
            fs()->remove($project);
            foreach (explode("\0", rtrim(test_git(['ls-files', '-z', '--cached', '--others', '--exclude-standard'], $root), "\0")) as $path) {
                if (is_file("{$root}/{$path}")) {
                    fs()->copy("{$root}/{$path}", "{$project}/{$path}");
                }
            }

            $process = run(['castor', '--no-ansi', 'init'], context: context()
                ->withWorkingDirectory($project)
                ->withEnvironment(['CASTOR_CONTEXT' => false])
                ->withPty(false)
                ->withTty(false)
                ->withQuiet()
                ->withAllowFailure());

            assert_same(0, $process->getExitCode(), "exit code\n{$process->getOutput()}{$process->getErrorOutput()}");

            foreach (explode("\0", rtrim(test_git(['ls-files', '-z', '.github'], $root), "\0")) as $path) {
                if (has_docker_starter_only_parts($path)) {
                    assert_same(strip_docker_starter_only((string) file_get_contents("{$root}/{$path}")), file_get_contents("{$project}/{$path}"), $path);
                }
            }

            assert_same(false, file_exists("{$project}/" . SYNC_DEFAULT_EXCLUDE_FILE), SYNC_DEFAULT_EXCLUDE_FILE . ' is removed');
            assert_same(['postgres'], array_map(basename(...), glob("{$project}/" . EXTENSIONS_DIR . '/*') ?: []), 'extensions of a new project');
            // A copy of the files, not a clone of docker-starter: its version is unknown
            assert_same(false, file_exists("{$project}/.docker-starter.json"), '.docker-starter.json');
            assert_contains('castor starter:sync --from=<commit>', $process->getOutput());
        },

        'New files are created, with their mode' => static function (string $project): void {
            [$starter, [$v1]] = create_fake_starter([[], ['bin/run.sh' => "#!/bin/sh\n", 'b.txt' => "b\n"]], executables: ['bin/run.sh']);
            init_sync_project($project, $starter, $v1, []);

            [$exitCode, $output] = run_sync($project);

            assert_same(0, $exitCode, "exit code\n{$output}");
            assert_same("b\n", file_get_contents("{$project}/b.txt"), 'b.txt');
            assert_same(true, is_executable("{$project}/bin/run.sh"), 'bin/run.sh is executable');
            assert_same(false, is_executable("{$project}/b.txt"), 'b.txt is not executable');
        },

        'Deleted files are removed, unless modified in the project' => static function (string $project): void {
            [$starter, [$v1]] = create_fake_starter([['x.txt' => "x\n", 'y.txt' => "y\n"], []]);
            init_sync_project($project, $starter, $v1, ['x.txt' => "x\n", 'y.txt' => "y2\n"]);

            [$exitCode, $output] = run_sync($project);

            assert_same(0, $exitCode, "exit code\n{$output}");
            assert_same(false, file_exists("{$project}/x.txt"), 'x.txt is deleted');
            assert_same("y2\n", file_get_contents("{$project}/y.txt"), 'y.txt');
            assert_contains('kept: deleted in docker-starter, but modified in the project', $output);
        },

        'Files removed from the project are not recreated' => static function (string $project): void {
            [$starter, [$v1]] = create_fake_starter([['z.txt' => "1\n"], ['z.txt' => "2\n"]]);
            init_sync_project($project, $starter, $v1, []);

            [$exitCode, $output] = run_sync($project);

            assert_same(0, $exitCode, "exit code\n{$output}");
            assert_same(false, file_exists("{$project}/z.txt"), 'z.txt is not created');
            assert_contains('skipped: not in the project', $output);
        },

        'Excluded files are left untouched' => static function (string $project): void {
            [$starter, [$v1]] = create_fake_starter([
                ['README.md' => "1\n", 'doc/a.md' => "1\n", 'tools/phpstan/composer.lock' => "1\n"],
                ['README.md' => "2\n", 'doc/a.md' => "2\n", 'tools/phpstan/composer.lock' => "2\n"],
            ]);
            init_sync_project($project, $starter, $v1, ['README.md' => "1\n", 'doc/a.md' => "1\n", 'tools/phpstan/composer.lock' => "1\n"], exclude: ['README.md', 'doc/', 'tools/*/composer.lock']);

            [$exitCode, $output] = run_sync($project);

            assert_same(0, $exitCode, "exit code\n{$output}");
            assert_same("1\n", file_get_contents("{$project}/README.md"), 'README.md');
            assert_same("1\n", file_get_contents("{$project}/doc/a.md"), 'doc/a.md');
            assert_same("1\n", file_get_contents("{$project}/tools/phpstan/composer.lock"), 'tools/phpstan/composer.lock');
        },

        'The exclusions of the project are added to the default ones' => static function (string $project): void {
            $defaults = [SYNC_DEFAULT_EXCLUDE_FILE => '["a.txt"]'];
            [$starter, [$v1]] = create_fake_starter([
                ['a.txt' => "1\n", 'b.txt' => "1\n", 'c.txt' => "1\n", ...$defaults],
                ['a.txt' => "2\n", 'b.txt' => "2\n", 'c.txt' => "2\n", ...$defaults],
            ]);
            init_sync_project($project, $starter, $v1, ['a.txt' => "1\n", 'b.txt' => "1\n", 'c.txt' => "1\n"], exclude: ['b.txt']);

            [$exitCode, $output] = run_sync($project);

            assert_same(0, $exitCode, "exit code\n{$output}");
            assert_same("1\n", file_get_contents("{$project}/a.txt"), 'a.txt, excluded by default');
            assert_same("1\n", file_get_contents("{$project}/b.txt"), 'b.txt, excluded by the project');
            assert_same("2\n", file_get_contents("{$project}/c.txt"), 'c.txt');
            assert_same(['b.txt'], read_sync_config($project)['exclude'], 'exclude');
        },

        'The default exclusions are those of the new version' => static function (string $project): void {
            [$starter, [$v1]] = create_fake_starter([
                ['x.txt' => "1\n", 'y.txt' => "1\n", SYNC_DEFAULT_EXCLUDE_FILE => '["x.txt"]'],
                ['x.txt' => "2\n", 'y.txt' => "2\n", SYNC_DEFAULT_EXCLUDE_FILE => '["y.txt"]'],
            ]);
            // The test project has its own copy of .castor/starter/
            init_sync_project($project, $starter, $v1, ['x.txt' => "1\n", 'y.txt' => "1\n"], exclude: ['.castor/']);

            [$exitCode, $output] = run_sync($project);

            assert_same(0, $exitCode, "exit code\n{$output}");
            assert_same("2\n", file_get_contents("{$project}/x.txt"), 'x.txt, no longer excluded');
            assert_same("1\n", file_get_contents("{$project}/y.txt"), 'y.txt, now excluded');
        },

        'The default exclusions file stays in docker-starter' => static function (string $project): void {
            $exclude = json_decode((string) file_get_contents(variable('root_dir') . '/' . SYNC_DEFAULT_EXCLUDE_FILE), true, flags: JSON_THROW_ON_ERROR);

            assert_same(true, \in_array(SYNC_DEFAULT_EXCLUDE_FILE, $exclude, true), 'it excludes itself');
        },

        'A docker-starter version without default exclusions is refused' => static function (string $project): void {
            [$starter, [$v1]] = create_fake_starter([['a.txt' => "1\n"], ['a.txt' => "2\n"]]);
            test_git(['rm', '--quiet', SYNC_DEFAULT_EXCLUDE_FILE], $starter);
            test_git(['commit', '--quiet', '-m', 'Version without default exclusions'], $starter);
            init_sync_project($project, $starter, $v1, ['a.txt' => "1\n"]);

            [$exitCode, $output] = run_sync($project);

            assert_same(1, $exitCode, "exit code\n{$output}");
            assert_contains('is too old to update to', $output);
            assert_same("1\n", file_get_contents("{$project}/a.txt"), 'a.txt');
            assert_same($v1, read_sync_config($project)['ref'], 'ref');
        },

        'The application directory is mapped to app_dir' => static function (string $project): void {
            [$starter, [$v1]] = create_fake_starter([['application/.dockerignore' => "1\n"], ['application/.dockerignore' => "2\n"]]);
            init_sync_project($project, $starter, $v1, ['.dockerignore' => "1\n"]);

            [$exitCode, $output] = run_sync($project, variables: ['app_dir' => '']);

            assert_same(0, $exitCode, "exit code\n{$output}");
            assert_same("2\n", file_get_contents("{$project}/.dockerignore"), '.dockerignore');
            assert_same(false, file_exists("{$project}/application"), 'no application/ directory');
        },

        'Renamed files are moved, with the changes of the project' => static function (string $project): void {
            [$starter, [$v1]] = create_fake_starter([['old.txt' => "a\nb\nc\nd\ne\nf\n"], ['new.txt' => "A\nb\nc\nd\ne\nf\n"]]);
            init_sync_project($project, $starter, $v1, ['old.txt' => "a\nb\nc\nd\ne\nF\n"]);

            [$exitCode, $output] = run_sync($project);

            assert_same(0, $exitCode, "exit code\n{$output}");
            assert_same(false, file_exists("{$project}/old.txt"), 'old.txt is removed');
            assert_same("A\nb\nc\nd\ne\nF\n", file_get_contents("{$project}/new.txt"), 'new.txt');
        },

        'Only the extensions of the project are updated' => static function (string $project): void {
            $a = EXTENSIONS_DIR . '/a';
            $b = EXTENSIONS_DIR . '/b';
            $c = EXTENSIONS_DIR . '/c';
            [$starter, [$v1]] = create_fake_starter([
                ["{$a}/x.yml" => "1\n", "{$b}/x.yml" => "1\n"],
                ["{$a}/x.yml" => "2\n", "{$a}/y.yml" => "2\n", "{$b}/x.yml" => "2\n", "{$c}/x.yml" => "2\n"],
            ]);
            init_sync_project($project, $starter, $v1, ["{$a}/x.yml" => "1\n"]);

            [$exitCode, $output] = run_sync($project);

            assert_same(0, $exitCode, "exit code\n{$output}");
            assert_same("2\n", file_get_contents("{$project}/{$a}/x.yml"), 'file of an extension of the project');
            assert_same("2\n", file_get_contents("{$project}/{$a}/y.yml"), 'new file of an extension of the project');
            assert_same(false, file_exists("{$project}/{$b}"), 'extension the project does not have');
            assert_same(false, file_exists("{$project}/{$c}"), 'new extension');
            assert_same(false, str_contains($output, 'extensions/b/'), "nothing to report about the other extensions\n{$output}");
        },

        'The versions of the services and the crontab belong to the project' => static function (string $project): void {
            // The real lists of docker-starter
            $lists = [
                SYNC_DEFAULT_EXCLUDE_FILE => (string) file_get_contents(variable('root_dir') . '/' . SYNC_DEFAULT_EXCLUDE_FILE),
                SYNC_PROJECT_FILES_FILE => (string) file_get_contents(variable('root_dir') . '/' . SYNC_PROJECT_FILES_FILE),
            ];
            $version = EXTENSIONS_DIR . '/a/docker-compose.version.yml';
            $crontab = EXTENSIONS_DIR . '/cron/crontab';
            [$starter, [$v1]] = create_fake_starter([
                [...$lists, $version => "image: a:1\n", $crontab => "# 1\n"],
                [...$lists, $version => "image: a:2\n", $crontab => "# 2\n"],
            ]);
            init_sync_project($project, $starter, $v1, [$version => "image: a:1\n", $crontab => "# 1\n"]);

            [$exitCode, $output] = run_sync($project);

            assert_same(0, $exitCode, "exit code\n{$output}");
            assert_same("image: a:1\n", file_get_contents("{$project}/{$version}"), 'version of the service');
            assert_same("# 1\n", file_get_contents("{$project}/{$crontab}"), 'crontab');
        },

        'The files of the project are created when docker-starter adds them, then never changed' => static function (string $project): void {
            $lists = [SYNC_PROJECT_FILES_FILE => '["a.ini", "b.ini"]'];
            [$starter, [$v1, $v2, $v3]] = create_fake_starter([
                [...$lists, 'b.ini' => "b 1\n"],
                [...$lists, 'a.ini' => "a 1\n", 'b.ini' => "b 1\n"],
                [...$lists, 'a.ini' => "a 2\n"],
            ]);
            init_sync_project($project, $starter, $v1, ['b.ini' => "b of the project\n"]);

            [$exitCode, $output] = run_sync($project, ["--to={$v2}"]);

            assert_same(0, $exitCode, "exit code\n{$output}");
            assert_same("a 1\n", file_get_contents("{$project}/a.ini"), 'new file of the project');
            test_git(['add', '--all'], $project);
            test_git(['commit', '--quiet', '-m', 'Update'], $project);

            [$exitCode, $output] = run_sync($project, ["--to={$v3}"]);

            assert_same(0, $exitCode, "exit code\n{$output}");
            assert_same("a 1\n", file_get_contents("{$project}/a.ini"), 'modified in docker-starter');
            assert_same("b of the project\n", file_get_contents("{$project}/b.ini"), 'deleted in docker-starter');
        },

        'starter:add copies an extension at the version of the project' => static function (string $project): void {
            $a = EXTENSIONS_DIR . '/a';
            [$starter, [$v1]] = create_fake_starter([
                ["{$a}/x.yml" => "1\n", "{$a}/run.sh" => "#!/bin/sh\n", EXTENSIONS_DIR . '/b/x.yml' => "1\n"],
                ["{$a}/x.yml" => "2\n", "{$a}/run.sh" => "#!/bin/sh\n"],
            ], executables: ["{$a}/run.sh"]);
            init_sync_project($project, $starter, $v1, []);

            [$exitCode, $output] = run_castor($project, ['starter:add', 'a']);

            assert_same(0, $exitCode, "exit code\n{$output}");
            assert_same("1\n", file_get_contents("{$project}/{$a}/x.yml"), 'the version of the project, not the last one');
            assert_same(true, is_executable("{$project}/{$a}/run.sh"), 'mode');
            assert_same(false, file_exists("{$project}/" . EXTENSIONS_DIR . '/b'), 'only this extension');

            [$exitCode, $output] = run_castor($project, ['starter:add', 'a']);

            assert_same(1, $exitCode, "exit code when the project already has it\n{$output}");
            assert_contains('already has the "a" extension', $output);
        },

        'starter:add refuses what is not an extension of docker-starter' => static function (string $project): void {
            [$starter, [$v1]] = create_fake_starter([[EXTENSIONS_DIR . '/a/x.yml' => "1\n", EXTENSIONS_DIR . '/b/x.yml' => "1\n"]]);
            init_sync_project($project, $starter, $v1, []);

            [$exitCode, $output] = run_castor($project, ['starter:add', 'z']);

            assert_same(1, $exitCode, "exit code\n{$output}");
            assert_contains('Its extensions: a, b.', $output);

            [$exitCode, $output] = run_castor($project, ['starter:add', '../a']);

            assert_same(1, $exitCode, "exit code of a path\n{$output}");
            assert_same(false, file_exists("{$project}/infrastructure/docker/a"), 'nothing outside of the extensions');
        },

        'starter:add needs the version of the project' => static function (string $project): void {
            [$starter] = create_fake_starter([[EXTENSIONS_DIR . '/a/x.yml' => "1\n"]]);
            init_sync_project($project, $starter, null, []);

            [$exitCode, $output] = run_castor($project, ['starter:add', 'a']);

            assert_same(1, $exitCode, "exit code\n{$output}");
            assert_contains('castor starter:sync --from=<ref>', $output);
        },

        'starter:remove removes an extension' => static function (string $project): void {
            init_sync_project($project, '', null, [EXTENSIONS_DIR . '/a/x.yml' => "1\n"]);

            [$exitCode, $output] = run_castor($project, ['starter:remove', 'a']);

            assert_same(0, $exitCode, "exit code\n{$output}");
            assert_same(false, file_exists("{$project}/" . EXTENSIONS_DIR . '/a'), 'directory of the extension');

            [$exitCode, $output] = run_castor($project, ['starter:remove', 'a']);

            assert_same(1, $exitCode, "exit code when the project does not have it\n{$output}");
        },

        'A dry run changes nothing' => static function (string $project): void {
            [$starter, [$v1]] = create_fake_starter([['a.txt' => "1\n"], ['a.txt' => "2\n"]]);
            init_sync_project($project, $starter, $v1, ['a.txt' => "1\n"]);

            [$exitCode, $output] = run_sync($project, ['--dry-run']);

            assert_same(0, $exitCode, "exit code\n{$output}");
            assert_same("1\n", file_get_contents("{$project}/a.txt"), 'a.txt');
            assert_same($v1, read_sync_config($project)['ref'], 'ref');
            assert_contains('updated', $output);
        },

        'Uncommitted changes are protected' => static function (string $project): void {
            [$starter, [$v1]] = create_fake_starter([['a.txt' => "1\n"], ['a.txt' => "2\n"]]);
            init_sync_project($project, $starter, $v1, ['a.txt' => "1\n", 'b.txt' => "b\n"]);
            fs()->dumpFile("{$project}/b.txt", "uncommitted\n");

            [$exitCode] = run_sync($project);

            assert_same(1, $exitCode, 'exit code');
            assert_same("1\n", file_get_contents("{$project}/a.txt"), 'a.txt');

            [$exitCode] = run_sync($project, ['--allow-dirty']);

            assert_same(0, $exitCode, 'exit code with --allow-dirty');
            assert_same("2\n", file_get_contents("{$project}/a.txt"), 'a.txt with --allow-dirty');
        },

        'The configuration file is created with --from' => static function (string $project): void {
            [$starter, [$v1, $v2]] = create_fake_starter([['a.txt' => "1\n"], ['a.txt' => "2\n"]]);
            init_sync_project($project, $starter, null, ['a.txt' => "1\n"]);

            [$exitCode, $output] = run_sync($project);
            assert_same(1, $exitCode, "exit code without --from\n{$output}");
            assert_contains('--from=<ref>', $output);

            [$exitCode, $output] = run_sync($project, ["--from={$v1}", "--repository={$starter}"]);

            assert_same(0, $exitCode, "exit code\n{$output}");
            assert_same("2\n", file_get_contents("{$project}/a.txt"), 'a.txt');
            $config = read_sync_config($project);
            assert_same($v2, $config['ref'], 'ref');
            assert_same($starter, $config['repository'], 'repository');
            assert_same([], $config['exclude'], 'exclude');
        },

        'The shared tasks are not synced when installed as a package' => static function (string $project): void {
            [$starter, [$v1]] = create_fake_starter([['.castor/starter/docker.php' => "1\n"], ['.castor/starter/docker.php' => "2\n"]]);
            fs()->dumpFile("{$project}/castor.composer.json", '{"require": {"jolicode/docker-starter": "dev-main"}}');
            init_sync_project($project, $starter, $v1, []);

            // The test project uses a copy of the shared tasks: do not install the package
            [$exitCode, $output] = run_sync($project, ['--no-remote']);

            assert_same(0, $exitCode, "exit code\n{$output}");
            assert_same(false, str_contains($output, 'docker.php'), "docker.php is not reported\n{$output}");
        },
    ];
}

/**
 * Creates a git repository standing for docker-starter, with a commit per version. Each
 * version has no default exclusions, unless it gives its own.
 *
 * @param list<array<string, string>> $versions    the files (path => content) of each version
 * @param list<string>                $executables
 *
 * @return array{string, list<string>} the repository and the commit of each version
 */
function create_fake_starter(array $versions, array $executables = []): array
{
    $starter = create_temp_dir('docker-starter-test-starter-');
    test_git(['init', '--quiet', '--initial-branch=main'], $starter);

    $commits = [];
    foreach ($versions as $i => $files) {
        foreach ((array) scandir($starter) as $file) {
            if (!\in_array($file, ['.', '..', '.git'], true)) {
                fs()->remove("{$starter}/{$file}");
            }
        }

        foreach ($files + [SYNC_DEFAULT_EXCLUDE_FILE => '[]'] as $path => $content) {
            fs()->dumpFile("{$starter}/{$path}", $content);
            fs()->chmod("{$starter}/{$path}", \in_array($path, $executables, true) ? 0o755 : 0o644);
        }

        test_git(['add', '--all'], $starter);
        test_git(['commit', '--quiet', '--allow-empty', '-m', "Version {$i}"], $starter);
        $commits[] = trim(test_git(['rev-parse', 'HEAD'], $starter));
    }

    return [$starter, $commits];
}

/**
 * Adds the files and the .docker-starter.json file to the project, and commits them.
 *
 * @param array<string, string> $files
 * @param list<string>|null     $exclude
 */
function init_sync_project(string $project, string $starter, ?string $ref, array $files, ?array $exclude = null): void
{
    foreach ($files as $path => $content) {
        fs()->dumpFile("{$project}/{$path}", $content);
    }

    if (null !== $ref) {
        $config = ['repository' => $starter, 'ref' => $ref];
        if (null !== $exclude) {
            $config['exclude'] = $exclude;
        }

        fs()->dumpFile("{$project}/.docker-starter.json", json_encode($config, JSON_THROW_ON_ERROR));
    }

    // Generated by Castor, and ignored by the projects
    fs()->dumpFile("{$project}/.gitignore", "/.castor.stub.php\n");

    test_git(['init', '--quiet'], $project);
    test_git(['add', '--all'], $project);
    test_git(['commit', '--quiet', '-m', 'Initial commit'], $project);
}

/**
 * @param list<string>         $args
 * @param array<string, mixed> $variables returned by create_default_variables()
 *
 * @return array{int, string} the exit code and the output
 */
function run_sync(string $project, array $args = [], array $variables = []): array
{
    return run_castor($project, ['starter:sync', ...$args], $variables);
}

/**
 * @param list<string>         $args      the task and its arguments
 * @param array<string, mixed> $variables returned by create_default_variables()
 *
 * @return array{int, string} the exit code and the output
 */
function run_castor(string $project, array $args, array $variables = []): array
{
    $process = run(['castor', '--no-ansi', ...$args], context: context()
        ->withWorkingDirectory($project)
        ->withEnvironment([
            'DS_TEST_VARIABLES' => json_encode($variables, JSON_THROW_ON_ERROR),
            'CASTOR_CONTEXT' => false,
            // Messages on one line, to check them
            'COLUMNS' => '1000',
        ])
        ->withPty(false)
        ->withTty(false)
        ->withQuiet()
        ->withAllowFailure());

    return [(int) $process->getExitCode(), $process->getOutput() . $process->getErrorOutput()];
}

/**
 * @return list<string>
 */
function list_castor_commands(string $project): array
{
    $output = capture(['castor', 'list', '--format=json'], context: context()->withWorkingDirectory($project)->withEnvironment(['CASTOR_CONTEXT' => false]));
    $lines = explode("\n", trim($output));

    return array_column(json_decode((string) end($lines), true, flags: JSON_THROW_ON_ERROR)['commands'], 'name');
}

/**
 * @return array<string, mixed>
 */
function read_sync_config(string $project): array
{
    return json_decode((string) file_get_contents("{$project}/.docker-starter.json"), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @param list<string> $args
 */
function test_git(array $args, string $cwd): string
{
    return run(['git', ...$args], context: context()
        ->withWorkingDirectory($cwd)
        ->withEnvironment([
            'GIT_AUTHOR_NAME' => 'docker-starter',
            'GIT_AUTHOR_EMAIL' => 'docker-starter@example.com',
            'GIT_COMMITTER_NAME' => 'docker-starter',
            'GIT_COMMITTER_EMAIL' => 'docker-starter@example.com',
        ])
        ->withPty(false)
        ->withTty(false)
        ->withQuiet())
        ->getOutput()
    ;
}

/**
 * Removed when the running test ends.
 */
function create_temp_dir(string $prefix): string
{
    $dir = fs()->tempnam(sys_get_temp_dir(), $prefix);
    fs()->remove($dir);
    fs()->mkdir($dir);
    $dir = (string) realpath($dir);
    get_test_temp_dirs()->append($dir);

    return $dir;
}

/**
 * The temporary directories created by the running test.
 *
 * @return \ArrayObject<int, string>
 */
function get_test_temp_dirs(): \ArrayObject
{
    static $dirs = new \ArrayObject();

    return $dirs;
}

/**
 * Creates a throwaway project using the shared castor tasks, with a "test-dump" task printing
 * what the tests check, as JSON.
 */
function create_test_project(string $import): string
{
    $project = create_temp_dir('docker-starter-test-');

    if (IMPORT_PACKAGE === $import) {
        // The package is installed from this repository, without any network access
        fs()->dumpFile("{$project}/castor.composer.json", json_encode([
            'repositories' => [
                [
                    'type' => 'path',
                    'url' => variable('root_dir'),
                    'options' => [
                        'versions' => ['jolicode/docker-starter' => 'dev-main'],
                    ],
                ],
                ['packagist.org' => false],
            ],
            'require' => [
                'jolicode/docker-starter' => 'dev-main',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $imports = "import('composer://jolicode/docker-starter', file: '.castor/starter/index.php');";
    } else {
        fs()->mirror(variable('root_dir') . '/.castor/starter', "{$project}/.castor/starter");

        $imports = "import(__DIR__ . '/.castor');";
    }

    fs()->dumpFile("{$project}/castor.php", <<<PHP
        <?php

        use Castor\\Attribute\\AsTask;

        use function Castor\\context;
        use function Castor\\import;
        use function Castor\\io;

        defined('CASTOR_USE_CHDIR') || define('CASTOR_USE_CHDIR', true);

        {$imports}

        function create_default_variables(): array
        {
            return ['project_name' => 'app'] + json_decode(getenv('DS_TEST_VARIABLES') ?: '[]', true);
        }

        #[AsTask()]
        function test_dump(): void
        {
            \$c = context();

            io()->writeln(json_encode([
                'context' => \$c->name,
                'data' => \$c->data,
                'environment' => \$c->environment,
                'app_dir' => docker\\app_dir(),
                'container_app_dir' => docker\\container_app_dir(),
                'compose_environment' => docker\\get_compose_environment(\$c),
                'build_args' => docker\\get_build_args(\$c),
                'phpunit_binary' => qa\\phpunit_binary(),
            ], \\JSON_THROW_ON_ERROR));
        }
        PHP);

    return $project;
}

/**
 * @param array<string, mixed>        $variables   returned by create_default_variables()
 * @param array<string, string|false> $environment
 *
 * @return array<string, mixed>
 */
function dump_project(string $project, array $variables = [], string $context = 'default', array $environment = []): array
{
    $output = capture(['castor', '--no-ansi', "--context={$context}", 'test-dump'], context: context()
        ->withWorkingDirectory($project)
        ->withEnvironment([
            'DS_TEST_VARIABLES' => json_encode($variables, JSON_THROW_ON_ERROR),
            // Do not let the environment of the tests leak in the projects
            'CASTOR_CONTEXT' => false,
            'HTTP_PORT' => false,
            ...$environment,
        ]));

    $lines = explode("\n", trim($output));

    return json_decode((string) end($lines), true, flags: JSON_THROW_ON_ERROR);
}

function assert_contains(string $needle, string $haystack): void
{
    if (!str_contains($haystack, $needle)) {
        throw new TestFailure(\sprintf("expected to contain: %s\nactual: %s", var_export($needle, true), $haystack));
    }
}

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new TestFailure(\sprintf("%s:\nexpected: %s\nactual:   %s", $message, var_export($expected, true), var_export($actual, true)));
    }
}
