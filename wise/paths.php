<?php

function get_cloaker_path(bool $withPrefix = true, bool $withSlashEnd = true): string
{
    $domain = get_request_host();
    if ($withPrefix) {
        $prefix = is_https() ? 'https://' : 'http://';
        $fullpath = $prefix . $domain . '/';
    } else {
        $fullpath = $domain . '/';
    }
    $script_path = array_values(array_filter(explode("/", $_SERVER['SCRIPT_NAME']), 'strlen'));
    array_pop($script_path);

    if (count($script_path) > 0) {
        // Dirty hack for alternate entrypoint folders.
        if (in_array($script_path[count($script_path) - 1], ['js', 'api'], true)) {
            array_pop($script_path);
        }
        if (count($script_path) > 0) {
            $fullpath .= implode('/', $script_path);
        }
    }

    if ($withSlashEnd && !str_ends_with($fullpath, '/')) {
        $fullpath .= '/';
    } elseif (!$withSlashEnd && str_ends_with($fullpath, '/')) {
        $fullpath = substr($fullpath, 0, -1);
    }

    return $fullpath;
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    $host = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
    if (is_local_host($host)) {
        // On localhost/127.0.0.1 we should not trust forwarded headers,
        // otherwise dev setups can accidentally force https redirects.
        return false;
    }

    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) {
        return true;
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
        return true;
    }

    return false;
}

function get_request_host(): string
{
    $host = get_forwarded_host();
    if ($host === '') {
        $host = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
    }
    if ($host === '') {
        $host = 'localhost';
    }

    if (str_contains($host, ':')) {
        return $host;
    }

    $port = get_request_port();
    $https = is_https();
    $isDefaultPort = ($https && $port === 443) || (!$https && $port === 80);
    if ($port > 0 && !$isDefaultPort) {
        return $host . ':' . $port;
    }

    return $host;
}

function get_forwarded_host(): string
{
    $raw = trim((string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
    if ($raw === '') {
        return '';
    }
    $parts = explode(',', $raw);
    return trim($parts[0]);
}

function get_request_port(): int
{
    $forwardedPort = trim((string)($_SERVER['HTTP_X_FORWARDED_PORT'] ?? ''));
    if ($forwardedPort !== '' && ctype_digit($forwardedPort)) {
        return (int)$forwardedPort;
    }
    return (int)($_SERVER['SERVER_PORT'] ?? 0);
}

function is_local_host(string $host): bool
{
    $host = trim($host);
    if ($host === '') {
        return false;
    }

    if (str_contains($host, ':')) {
        $host = explode(':', $host, 2)[0];
    }

    $host = strtolower($host);
    return $host === 'localhost' || $host === '127.0.0.1' || $host === '::1';
}
