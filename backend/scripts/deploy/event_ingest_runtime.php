<?php

/** Release-bound EVENT delivery. Private data never enters arguments or receipts. */
final class EventIngestRuntime
{
    private const SCHEMA = 'fermatmind.event-ingest-input.v1';

    public static function binding(string $revision, string $environment): void
    {
        if (! preg_match('/^[a-f0-9]{40}$/D', $revision) || ! in_array($environment, ['staging', 'production'], true)) {
            throw new RuntimeException('EVENT_BINDING_INVALID');
        }
    }

    public static function safe(string $path, bool $directory, int $allowed): array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (! $stat || is_link($path) || ($directory ? ! is_dir($path) : ! is_file($path))
            || $stat['uid'] !== posix_geteuid() || (($stat['mode'] & 0777) & ~$allowed) !== 0) {
            throw new RuntimeException('EVENT_PRIVATE_PATH_UNSAFE');
        }

        return $stat;
    }

    public static function parents(string $backend, string $anchor): void
    {
        if (realpath($anchor) !== $anchor || realpath($backend) !== $backend
            || preg_match('#^'.preg_quote($anchor, '#').'/releases/[A-Za-z0-9._-]+/backend$#D', $backend) !== 1) {
            throw new RuntimeException('EVENT_RELEASE_PATH_INVALID');
        }
        $path = $backend;
        while (true) {
            self::safe($path, true, 0755);
            if ($path === $anchor) {
                break;
            }
            $path = dirname($path);
        }
    }

    public static function prepare(string $backend, string $anchor): void
    {
        if (realpath($backend) !== $backend || realpath($anchor.'/current/backend') === $backend
            || preg_match('#^'.preg_quote($anchor, '#').'/releases/[A-Za-z0-9._-]+/backend$#D', $backend) !== 1) {
            throw new RuntimeException('EVENT_INACTIVE_CANDIDATE_REQUIRED');
        }
        self::safe($anchor, true, 0755);
        self::safe($anchor.'/releases', true, 0755);
        foreach ([dirname($backend), $backend, $backend.'/bootstrap', $backend.'/bootstrap/cache'] as $path) {
            self::safe($path, true, 0775);
        }
    }

    public static function init(string $backend, string $anchor): void
    {
        self::prepare($backend, $anchor);
        // Deployer prepares these inactive directories using its existing chmod
        // permission. An unprivileged chmod can silently clear setgid when the
        // deploy identity is not a member of the inherited www-data group.
        foreach ([dirname($backend), $backend, $backend.'/bootstrap', $backend.'/bootstrap/cache'] as $path) {
            $stat = self::safe($path, true, 0755);
            if (($stat['mode'] & 07777) !== 02755) {
                throw new RuntimeException('EVENT_CANDIDATE_PERMISSION_FAILED');
            }
        }
        self::parents($backend, $anchor);
        $private = $backend.'/.event-ingest';
        if (! file_exists($private)) {
            // mkdir inherits both the group and setgid bit from cache. Do not
            // chmod afterwards: on Linux that would silently drop setgid.
            $temporary = $backend.'/bootstrap/cache/.event-ingest-'.bin2hex(random_bytes(8));
            if (! mkdir($temporary, 0700) || ! rename($temporary, $private)) {
                throw new RuntimeException('EVENT_PRIVATE_DIRECTORY_FAILED');
            }
        }
        $privateStat = self::safe($private, true, 0700);
        $cacheStat = self::safe($backend.'/bootstrap/cache', true, 0755);
        if (($privateStat['mode'] & 07777) !== 02700 || $privateStat['gid'] !== $cacheStat['gid']) {
            throw new RuntimeException('EVENT_PRIVATE_DIRECTORY_FAILED');
        }
        if (file_exists($private.'/incoming.json')) {
            throw new RuntimeException('EVENT_INPUT_ALREADY_EXISTS');
        }
        self::write($private.'/incoming.json', []);
    }

    public static function managed(string $backend): bool
    {
        // Either durable public marker or private-directory trace requires
        // authority verification. Losing input is never legacy compatibility.
        return @lstat($backend.'/.event-ingest-managed.json') !== false
            || @lstat($backend.'/.event-ingest') !== false;
    }

    public static function mark(string $backend, string $revision, string $environment): void
    {
        self::binding($revision, $environment);
        self::safe($backend, true, 0755);
        $path = $backend.'/.event-ingest-managed.json';
        $handle = fopen($path, 'x');
        if (! $handle) {
            throw new RuntimeException('EVENT_MARKER_WRITE_FAILED');
        }
        try {
            $marker = ['schema_version' => 'fermatmind.event-ingest-managed.v1', 'revision' => $revision, 'environment' => $environment];
            if (! chmod($path, 0644) || fwrite($handle, json_encode($marker, JSON_THROW_ON_ERROR)) === false || ! fflush($handle)) {
                throw new RuntimeException('EVENT_MARKER_WRITE_FAILED');
            }
        } finally {
            fclose($handle);
        }
    }

    public static function authority(string $backend, string $revision, string $environment): array
    {
        $marker = self::read($backend.'/.event-ingest-managed.json', 0644);
        $keys = array_keys($marker);
        sort($keys);
        if ($keys !== ['environment', 'revision', 'schema_version']
            || ($marker['schema_version'] ?? null) !== 'fermatmind.event-ingest-managed.v1'
            || ($marker['revision'] ?? null) !== $revision || ($marker['environment'] ?? null) !== $environment) {
            throw new RuntimeException('EVENT_MARKER_INVALID');
        }
        self::safe($backend.'/.event-ingest', true, 0700);

        return self::input(self::read($backend.'/.event-ingest/input.json'), $revision, $environment);
    }

    public static function write(string $path, array $data): void
    {
        self::safe(dirname($path), true, 0700);
        $handle = fopen($path, 'x');
        if (! $handle) {
            throw new RuntimeException('EVENT_PRIVATE_WRITE_FAILED');
        }
        try {
            if (! chmod($path, 0600) || fwrite($handle, json_encode($data, JSON_THROW_ON_ERROR)) === false || ! fflush($handle)) {
                throw new RuntimeException('EVENT_PRIVATE_WRITE_FAILED');
            }
        } finally {
            fclose($handle);
        }
        self::safe($path, false, 0600);
    }

    public static function read(string $path, int $allowed = 0600): array
    {
        $before = self::safe($path, false, $allowed);
        $value = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        $after = self::safe($path, false, $allowed);
        if ($before['ino'] !== $after['ino'] || ! is_array($value)) {
            throw new RuntimeException('EVENT_INPUT_INVALID');
        }

        return $value;
    }

    public static function input(array $input, string $revision, string $environment): array
    {
        self::binding($revision, $environment);
        $keys = array_keys($input);
        sort($keys);
        if ($keys !== ['enabled', 'environment', 'revision', 'schema_version', 'token']
            || ($input['schema_version'] ?? null) !== self::SCHEMA
            || ($input['revision'] ?? null) !== $revision || ($input['environment'] ?? null) !== $environment
            || ! is_bool($input['enabled']) || ! is_string($input['token'])
            || ($input['enabled'] ? preg_match('/^[A-Za-z0-9_-]{32,512}$/D', $input['token']) !== 1 : $input['token'] !== '')) {
            throw new RuntimeException('EVENT_INPUT_INVALID');
        }

        return $input;
    }

    public static function disabled(string $revision, string $environment): array
    {
        return ['schema_version' => self::SCHEMA, 'revision' => $revision, 'environment' => $environment, 'enabled' => false, 'token' => ''];
    }

    public static function config(string $backend, array $input): void
    {
        $path = $backend.'/bootstrap/cache/config.php';
        $stat = self::safe($path, false, 0640);
        $directory = self::safe(dirname($path), true, 0755);
        if (($stat['mode'] & 0777) !== 0640 || $stat['gid'] !== $directory['gid']) {
            throw new RuntimeException('EVENT_CONFIG_PERMISSION_INVALID');
        }
        $config = require $path;
        if (! is_array($config) || ($config['app']['env'] ?? null) !== $input['environment']
            || ! is_string($config['fap']['events']['ingest_token'] ?? null)
            || ! hash_equals($input['token'], $config['fap']['events']['ingest_token'])) {
            throw new RuntimeException('EVENT_CONFIG_MISMATCH');
        }
    }

    public static function previous(string $backend, string $environment, string $anchor): array
    {
        self::parents($backend, $anchor);
        $revision = trim(file_get_contents(dirname($backend).'/REVISION'));
        self::binding($revision, $environment);
        if (self::managed($backend)) {
            $input = self::authority($backend, $revision, $environment);
        } else {
            // Adopt only a safely cached, disabled legacy baseline. Never copy an
            // unknown shared credential into the managed release authority.
            $input = self::disabled($revision, $environment);
        }
        self::config($backend, $input);

        return $input;
    }

    public static function install(string $backend, string $revision, string $environment, string $current, string $anchor, string $rollback): bool
    {
        self::binding($revision, $environment);
        self::parents($backend, $anchor);
        $private = $backend.'/.event-ingest';
        self::safe($private, true, 0700);
        $wire = self::read($private.'/incoming.json');
        try {
            $keys = array_keys($wire);
            sort($keys);
            if ($keys !== ['intent', 'token'] || ! in_array($wire['intent'], ['', '0', '1'], true) || ! is_string($wire['token'])
                || ($wire['intent'] !== '1' && $wire['token'] !== '')) {
                throw new RuntimeException('EVENT_INTENT_INVALID');
            }
            if ($rollback !== '') {
                if (! str_ends_with($rollback, '/backend/.event-ingest/lkg.json')) {
                    throw new RuntimeException('EVENT_LKG_INVALID');
                }
                self::parents(dirname(dirname($rollback)), $anchor);
                self::safe(dirname($rollback), true, 0700);
                $input = self::input(self::read($rollback), $revision, $environment);
                self::mark($backend, $revision, $environment);
                self::write($private.'/input.json', $input);

                return true;
            }
            if ($wire['intent'] === '' && ! self::managed($current)) {
                return false;
            }
            $previous = self::previous($current, $environment, $anchor);
            $input = $previous;
            $input['revision'] = $revision;
            if ($wire['intent'] !== '') {
                $input['enabled'] = $wire['intent'] === '1';
                $input['token'] = $wire['token'];
            }
            $input = self::input($input, $revision, $environment);
            self::mark($backend, $revision, $environment);
            self::write($private.'/lkg.json', $previous);
            self::write($private.'/input.json', $input);

            return true;
        } finally {
            if (! unlink($private.'/incoming.json')) {
                throw new RuntimeException('EVENT_INPUT_CLEANUP_FAILED');
            }
        }
    }

    public static function compile(string $backend, string $revision, string $environment, string $anchor): bool
    {
        self::parents($backend, $anchor);
        $private = $backend.'/.event-ingest';
        self::safe($private, true, 0700);
        if (! self::managed($backend)) {
            return false;
        }
        $input = self::authority($backend, $revision, $environment);
        $candidate = $private.'/config-candidate.php';
        if (file_exists($candidate)) {
            throw new RuntimeException('EVENT_CACHE_CANDIDATE_EXISTS');
        }
        $environmentVariables = getenv();
        $environmentVariables['EVENT_INGEST_TOKEN'] = $input['token'];
        $environmentVariables['APP_CONFIG_CACHE'] = $candidate;
        // Child output is discarded; neither artisan errors nor fixture failures
        // may echo environment values into SSH/CI logs.
        $process = proc_open([PHP_BINARY, $backend.'/artisan', 'config:cache', '--no-ansi'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $backend, $environmentVariables);
        if (! is_resource($process)) {
            throw new RuntimeException('EVENT_CACHE_BUILD_FAILED');
        }
        $deadline = microtime(true) + 120;
        do {
            $status = proc_get_status($process);
            if (! $status['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                proc_close($process);
                throw new RuntimeException('EVENT_CACHE_BUILD_FAILED');
            }
            usleep(20000);
        } while (true);
        proc_close($process);
        if ($status['exitcode'] !== 0) {
            throw new RuntimeException('EVENT_CACHE_BUILD_FAILED');
        }
        self::safe($candidate, false, 0664);
        if (! chmod($candidate, 0640)) {
            throw new RuntimeException('EVENT_CACHE_PERMISSION_FAILED');
        }
        $config = require $candidate;
        if (! is_array($config) || ($config['app']['env'] ?? null) !== $environment
            || ! is_string($config['fap']['events']['ingest_token'] ?? null) || ! hash_equals($input['token'], $config['fap']['events']['ingest_token'])) {
            throw new RuntimeException('EVENT_CONFIG_MISMATCH');
        }
        $cacheDirectory = self::safe($backend.'/bootstrap/cache', true, 0755);
        $candidateStat = self::safe($candidate, false, 0640);
        if ($candidateStat['gid'] !== $cacheDirectory['gid'] || ! rename($candidate, $backend.'/bootstrap/cache/config.php')) {
            throw new RuntimeException('EVENT_CACHE_ACTIVATION_FAILED');
        }
        self::config($backend, $input);

        return true;
    }

    public static function verify(string $backend, string $revision, string $environment, string $anchor, bool $required = false, ?bool $expectedEnabled = null): ?array
    {
        self::binding($revision, $environment);
        if (trim(file_get_contents(dirname($backend).'/REVISION')) !== $revision) {
            throw new RuntimeException('EVENT_REVISION_MISMATCH');
        }
        if (! self::managed($backend)) {
            if ($required || $expectedEnabled !== null) {
                throw new RuntimeException('EVENT_MANAGEMENT_REQUIRED');
            }

            return null;
        }
        self::parents($backend, $anchor);
        $input = self::authority($backend, $revision, $environment);
        if ($expectedEnabled !== null && $input['enabled'] !== $expectedEnabled) {
            throw new RuntimeException('EVENT_ENABLED_MISMATCH');
        }
        self::config($backend, $input);

        return $input;
    }

    public static function probe(array $input, callable $request): array
    {
        [$status, $body] = $request($input['token']);
        $response = json_decode($body, true);
        $expected = $input['enabled'] ? 422 : 503;
        $error = $input['enabled'] ? 'VALIDATION_FAILED' : 'INGEST_DISABLED';
        if ($status !== $expected || ! is_array($response) || ($response['ok'] ?? null) !== false
            || ($response['error_code'] ?? null) !== $error
            || ($input['enabled'] && (! is_array($response['details']['eventName'] ?? null) || $response['details']['eventName'] === []))) {
            throw new RuntimeException('EVENT_HTTP_CONTRACT_FAILED');
        }

        return ['schema_version' => 'fermatmind.event-ingest-runtime.v1', 'revision' => $input['revision'], 'environment' => $input['environment'],
            'enabled' => $input['enabled'], 'config_cached' => true, 'release_bound' => true, 'empty_envelope_status' => $status, 'status' => 'verified'];
    }
}

if (defined('FAP_EVENT_RUNTIME_CLI')) {
    try {
        [$command, $backend, $revision, $environment, $anchor] = array_slice($argv, 1, 5);
        EventIngestRuntime::binding($revision, $environment);
        $result = null;
        if ($command === 'prepare') {
            EventIngestRuntime::prepare($backend, $anchor);
        } elseif ($command === 'init') {
            if (! function_exists('curl_init')) {
                throw new RuntimeException('EVENT_HTTP_RUNTIME_UNAVAILABLE');
            }
            EventIngestRuntime::init($backend, $anchor);
        } elseif ($command === 'install') {
            if (! EventIngestRuntime::install($backend, $revision, $environment, $argv[6], $anchor, $argv[7] ?? '')) {
                throw new RuntimeException('EVENT_MANAGEMENT_REQUIRED');
            }
        } elseif ($command === 'compile') {
            if (! EventIngestRuntime::compile($backend, $revision, $environment, $anchor)) {
                throw new RuntimeException('EVENT_MANAGEMENT_REQUIRED');
            }
        } elseif ($command === 'verify') {
            $enabled = $argv[6] ?? '';
            if (! in_array($enabled, ['', '0', '1'], true)) {
                throw new RuntimeException('EVENT_EXPECTATION_INVALID');
            }
            if (EventIngestRuntime::verify($backend, $revision, $environment, $anchor, true, $enabled === '' ? null : $enabled === '1') === null) {
                throw new RuntimeException('EVENT_MANAGEMENT_REQUIRED');
            }
        } elseif ($command === 'probe') {
            $required = $argv[6] ?? '0';
            $enabled = $argv[7] ?? '';
            if (! in_array($required, ['0', '1'], true) || ! in_array($enabled, ['', '0', '1'], true)) {
                throw new RuntimeException('EVENT_EXPECTATION_INVALID');
            }
            $input = EventIngestRuntime::verify($backend, $revision, $environment, $anchor, $required === '1', $enabled === '' ? null : $enabled === '1');
            if ($input === null) {
                $result = ['schema_version' => 'fermatmind.event-ingest-runtime.v1', 'revision' => $revision, 'environment' => $environment, 'status' => 'unmanaged'];
            } else {
                $host = $environment === 'production' ? 'api.fermatmind.com' : 'staging-api.fermatmind.com';
                $result = EventIngestRuntime::probe($input, static function (string $token) use ($host): array {
                    $handle = curl_init('https://'.$host.'/api/v0.5/seo/attribution/events');
                    curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{}', CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'X-Track-Ingest-Token: '.$token],
                        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
                        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_RESOLVE => [$host.':443:127.0.0.1'], CURLOPT_PROXY => '']);
                    $body = curl_exec($handle);
                    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                    curl_close($handle);

                    return [$status, is_string($body) ? $body : ''];
                });
            }
        } else {
            throw new RuntimeException('EVENT_COMMAND_INVALID');
        }
        if ($result !== null) {
            echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
        }
    } catch (Throwable $error) {
        // Only fixed error codes are public, never exception details/paths.
        fwrite(STDERR, "EVENT_RUNTIME_FAILED\n");
        exit(1);
    }
}
