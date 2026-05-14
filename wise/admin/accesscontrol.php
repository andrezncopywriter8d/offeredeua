<?php
require_once __DIR__ . '/../bases/ipcountry.php';

function get_admin_access_error(array $server, array $settings): ?string
{
    $adminDomain = trim((string)($settings['adminDomain'] ?? ''));
    if ($adminDomain !== '') {
        $currentDomain = (string)($server['SERVER_NAME'] ?? '');
        if ($currentDomain !== $adminDomain) {
            return "Admin Domain $adminDomain is set, but your domain is $currentDomain. You are not allowed to access this page!";
        }
    }

    $adminIp = trim((string)($settings['adminIp'] ?? ''));
    if ($adminIp !== '') {
        $currentIp = getip($server);
        if ($currentIp !== $adminIp) {
            return "Admin IP $adminIp is set, but your IP is $currentIp. You are not allowed to access this page!";
        }
    }

    return null;
}
