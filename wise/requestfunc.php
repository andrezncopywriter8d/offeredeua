<?php
require_once __DIR__ . '/bases/ipcountry.php';
require_once __DIR__ . '/paths.php';

function send_access_control_headers()
{
    if (isset($_SERVER['HTTP_REFERER'])) {
        $parsed_url = parse_url($_SERVER['HTTP_REFERER']);
        $origin = $parsed_url['scheme'] . '://' . $parsed_url['host'];
        if (!empty($parsed_url['port']))
            $origin .= ':' . $parsed_url['port'];
        header('Access-Control-Allow-Origin: ' . $origin);
    }
    header('Access-Control-Allow-Credentials: true');
}

function get_abs_from_rel(string $url): string
{
    $fullpath = get_cloaker_path();
    $fullpath .= $url;
    if (!str_ends_with($url, '.php'))
        $fullpath = $fullpath . '/';
    return $fullpath;
}

function get_request_headers(bool $ispost = false): array
{
    $ip = getip();
    $headers = array(
    'X-YWBCLO-UIP: ' . $ip,
    'X-FORWARDED-FOR: ' . $ip,
    'CF-CONNECTING-IP: ' . $ip,
    'FORWARDED-FOR: ' . $ip,
    'X-COMING-FROM: ' . $ip,
    'COMING-FROM: ' . $ip,
    'FORWARDED-FOR-IP: ' . $ip,
    'CLIENT-IP: ' . $ip,
    'X-REAL-IP: ' . $ip,
    'REMOTE-ADDR: ' . $ip
    );
    if ($ispost)
        $headers[] = "Content-Type: application/x-www-form-urlencoded";
    return $headers;
}

function get(string $url): array
{
    $curl = curl_init();
    $optArray = array(
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_HTTPHEADER => get_request_headers(false),
    CURLOPT_TIMEOUT => 0,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_REFERER => $_SERVER['REQUEST_URI'],
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36'
    );
    curl_setopt_array($curl, $optArray);
    $content = curl_exec($curl);
    $info = curl_getinfo($curl);
    $error = curl_error($curl);
    curl_close($curl);
    return ["content" => $content, "info" => $info, "error" => $error];
}

function post(string $url, array $postfields): array
{
    $curl = curl_init();
    curl_setopt_array(
    $curl,
    array(
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST => "POST",
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_VERBOSE => true,
    CURLOPT_POSTFIELDS => $postfields,
    CURLOPT_REFERER => $_SERVER['REQUEST_URI'],
    CURLOPT_HTTPHEADER => get_request_headers(true),
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36'
    )
    );

    $content = curl_exec($curl);
    $info = curl_getinfo($curl);
    $error = curl_error($curl);
    curl_close($curl);
    return ["content" => $content, "info" => $info, "error" => $error];
}

