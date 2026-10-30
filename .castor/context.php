<?php

namespace context;

use Castor\Attribute\AsContext;
use Castor\Context;
use Castor\Helper\PathHelper;
use Symfony\Component\Process\Process;

use function Castor\log;
use function worktree\get_worktree_name;

#[AsContext(default: true)]
function create_default_context(): Context
{
    $data = create_default_variables() + [
        'project_name' => 'app',
        'root_domain' => 'app.test',
        'extra_domains' => [],
        'php_version' => '8.5',
        // Directory of the application, relative to the repository root ('' when the
        // application lives at the root of the repository)
        'app_dir' => 'application',
        // Port of the nginx container, published when there is no router (prod context).
        // The HTTP_PORT environment variable takes precedence.
        'http_port' => 8000,
        'registry' => null,
        // Extensions installed in every image on top of those of docker-starter, by their
        // name in the Debian packages: e.g. 'imagick' for php8.5-imagick
        'php_extensions' => [],
        // Debian packages installed in every image (e.g. 'imagemagick'), and only in the
        // builder (e.g. 'mariadb-client')
        'system_packages' => [],
        'builder_system_packages' => [],
        // Node.js and Yarn in the builder: true for the version of the Dockerfile, a version
        // (e.g. '22' or '22.x'), or false for none (e.g. with the Symfony AssetMapper)
        'nodejs' => true,
        // Directories of the application written at runtime in production, usually the
        // mount points of volumes: owned by the application user in the "php" image
        'writable_dirs' => [],
        'docker_compose_files' => [
            'docker-compose.yml',
            'docker-compose.dev.yml',
        ],
        'docker_compose_run_environment' => [],
        // Service in which `docker_compose_run()` executes commands (composer, bin/console...)
        'docker_compose_run_service' => 'builder',
        'macos' => false,
        'power_shell' => false,
        // check if posix_geteuid is available, if not, use getmyuid (windows)
        'user_id' => \function_exists('posix_geteuid') ? posix_geteuid() : getmyuid(),
        'root_dir' => PathHelper::getRoot(),
    ];

    $data['docker_compose_files'] = [
        ...$data['docker_compose_files'],
        ...get_existing_docker_compose_files($data['root_dir'], ['docker-compose.project.yml', 'docker-compose.override.yml']),
    ];

    $data['app_dir'] = trim($data['app_dir'], '/');

    $platform = strtolower(php_uname('s'));
    if (str_contains($platform, 'darwin')) {
        $data['macos'] = true;
    } elseif (\in_array($platform, ['win32', 'win64', 'windows nt'], true)) {
        $data['power_shell'] = true;
    }

    //                                                   2³² - 1
    if (false === $data['user_id'] || $data['user_id'] > 4294967295) {
        $data['user_id'] = 1000;
    }

    if (0 === $data['user_id']) {
        log('Running as root? Fallback to fake user id.', 'warning');
        $data['user_id'] = 1000;
    }

    $worktreeName = get_worktree_name($data['root_dir']);
    if (null !== $worktreeName) {
        $data['project_name'] .= '-wt-' . $worktreeName;
    }

    // Only when someone can answer the prompts of the commands: stdin is a terminal, outside of
    // a CI (as for Castor). Otherwise (CI, pipes, AI agents...), a PTY would give them a terminal
    // nobody types in, and a prompt (composer, bin/console...) would wait forever. Unlike
    // Context::supportsInteraction(), stdout may be piped (e.g. `castor init | tee init.log`).
    $canAnswerPrompts = false === getenv('CI') && stream_isatty(STDIN);

    return new Context(
        $data,
        pty: Process::isPtySupported() && $canAnswerPrompts,
        environment: [
            'BUILDKIT_PROGRESS' => 'plain',
        ]
    );
}

/**
 * Production images (see the "Production stages" of the Dockerfile): the same tasks as in
 * dev, on a dedicated compose stack. E.g. `castor start -c prod`, `castor docker:push -c prod`.
 */
#[AsContext(name: 'prod')]
function create_prod_context(): Context
{
    $c = create_default_context();

    $files = [
        'docker-compose.prod.yml',
        ...get_existing_docker_compose_files($c['root_dir'], ['docker-compose.prod.project.yml', 'docker-compose.prod.override.yml']),
    ];

    return $c->withData(
        [
            // Dedicated compose project: never collides with the dev stack (containers, volumes)
            'project_name' => $c['project_name'] . '-prod',
            'docker_compose_files' => $files,
            // There is no builder service in this stack: commands run in the application image
            'docker_compose_run_service' => 'php',
        ],
        recursive: false,
    );
}

#[AsContext(name: 'test')]
function create_test_context(): Context
{
    $c = create_default_context();

    return $c
        ->withEnvironment([
            'APP_ENV' => 'test',
        ])
    ;
}

#[AsContext(name: 'ci')]
function create_ci_context(): Context
{
    $c = create_test_context();

    return $c
        ->withEnvironment([
            'COMPOSE_ANSI' => 'never',
        ])
    ;
}

/**
 * The optional compose files of a stack, among $files (relative to infrastructure/docker/),
 * e.g. those of the project (*.project.yml, committed), then the local tweaks of a
 * developer (*.override.yml, gitignored, e.g. to mount a secret).
 *
 * @param list<string> $files
 *
 * @return list<string>
 */
function get_existing_docker_compose_files(string $rootDir, array $files): array
{
    return array_values(array_filter($files, static fn (string $file) => file_exists("{$rootDir}/infrastructure/docker/{$file}")));
}
