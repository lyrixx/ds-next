<?php

// This file tests the castor tasks shared with the projects (context variables, paths...),
// against throwaway projects created in a temporary directory: it needs neither Docker
// nor PHP on the host, only Castor. It is entirely self-contained: to drop it from a
// project generated from this template, just delete this file (`castor init` does it).

namespace test;

use Castor\Attribute\AsTask;

use function Castor\capture;
use function Castor\context;
use function Castor\fs;
use function Castor\io;
use function Castor\variable;

#[AsTask(description: 'Tests the castor tasks shared with the projects')]
function starter(): void
{
    io()->title('Testing the castor tasks shared with the projects');

    $failures = 0;

    foreach (get_starter_test_cases() as $name => $case) {
        $project = create_test_project();

        try {
            $case($project);
            io()->writeln("<info>✔</info> {$name}");
        } catch (TestFailure $e) {
            ++$failures;
            io()->writeln("<error>✘</error> {$name}");
            io()->writeln('  ' . str_replace("\n", "\n  ", $e->getMessage()));
        } finally {
            fs()->remove($project);
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

        'The prod context runs a dedicated stack' => static function (string $project): void {
            $result = dump_project($project, context: 'prod');

            assert_same('prod', $result['context'], 'context');
            assert_same('app-prod', $result['data']['project_name'], 'project_name');
            assert_same(['docker-compose.prod.yml'], $result['data']['docker_compose_files'], 'docker_compose_files');
            assert_same('php', $result['data']['docker_compose_run_service'], 'docker_compose_run_service');
        },

        'The prod context picks the optional override file' => static function (string $project): void {
            fs()->dumpFile("{$project}/infrastructure/docker/docker-compose.prod.override.yml", '');

            $result = dump_project($project, context: 'prod');

            assert_same(['docker-compose.prod.yml', 'docker-compose.prod.override.yml'], $result['data']['docker_compose_files'], 'docker_compose_files');
        },

        'The test context sets APP_ENV' => static function (string $project): void {
            assert_same('test', dump_project($project, context: 'test')['environment']['APP_ENV'] ?? null, 'APP_ENV');
        },
    ];
}

/**
 * Creates a throwaway project using the shared castor tasks, with a "test-dump" task printing
 * what the tests check, as JSON.
 */
function create_test_project(): string
{
    $project = fs()->tempnam(sys_get_temp_dir(), 'docker-starter-test-');
    fs()->remove($project);
    fs()->mkdir($project);
    $project = (string) realpath($project);

    $imports = implode("\n", array_map(
        static fn (string $file) => \sprintf('import(%s);', var_export(variable('root_dir') . '/.castor/' . $file, true)),
        ['context.php', 'docker.php', 'postgres.php', 'qa.php', 'worktree.php'],
    ));

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

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new TestFailure(\sprintf("%s:\nexpected: %s\nactual:   %s", $message, var_export($expected, true), var_export($actual, true)));
    }
}
