<?php
require_once __DIR__ . '/bases/ipcountry.php';
require_once __DIR__ . '/debug.php';
function add_log(string $subdir, string $msg, bool $logIp = false)
{
    if ($subdir ==='trace' && !DebugMethods::on()) return;
    $dir = __DIR__ . "/logs/$subdir";
    if (!file_exists($dir)) 
        mkdir($dir, 0777, true);
    $datetime = explode(' ', date("d.m.y H:i:s"));
    $date = $datetime[0];  // "d.m.y"
    $time = $datetime[1];  // "H:i:s"
    $fileName = "$dir/$date.log";
    if ($logIp) {
        $ip = getip();
        $time .= " $ip";
    }
    $msg = "$time $msg\n";
    file_put_contents($fileName, $msg, FILE_APPEND | LOCK_EX);
}

function add_error_log(string $msg, bool $logIp = false,bool $die = false)
{
    add_log('error',$msg,$logIp);
    if ($die) die($msg);
}