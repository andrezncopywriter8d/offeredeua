<?php

require_once __DIR__ . '/../logging.php';
require_once __DIR__ . '/../db/db.php';
require_once __DIR__ . '/../macros.php';
require_once __DIR__ . '/../paths.php';
require_once __DIR__ . '/../requestfunc.php';
require_once __DIR__ . '/../campaign.php';
require_once __DIR__ . '/../currency.php';
global $db;

$curLink = (is_https() ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
$clickid = $_REQUEST['clickid'] ?? '';
if ($clickid === '') {
    http_response_code(500);
    $msg = 'No clickid found! Url: ' . $curLink;
    add_log('postback', $msg);
    echo $msg;
    exit;
}
$status = $_REQUEST['status'] ?? '';
if ($status === '') {
    http_response_code(500);
    $msg = 'No status found! Url: ' . $curLink;
    add_log('postback', $msg);
    echo $msg;
    exit;
}
$payout = $_REQUEST['payout'] ?? '';
if ($payout === '') {
    http_response_code(500);
    $msg = 'No payout found! Url: ' . $curLink;
    add_log('postback', $msg);
    echo $msg;
    exit;
}

$click = $db->get_click_by_clickid($clickid);
if (empty($click)) {
    http_response_code(500);
    $msg = 'No click data for clickid ' . $clickid . ' found! Url: ' . $curLink;
    add_log('postback', $msg);
    echo $msg;
    exit;
}
$cs = $db->get_campaign_settings($click['campaign_id']);
$c = new Campaign($click['campaign_id'], $cs);

$inner_status = match (strtolower($status)) {
    strtolower($c->postback->leadStatusName) => 'Lead',
    strtolower($c->postback->purchaseStatusName) => 'Purchase',
    strtolower($c->postback->rejectStatusName) => 'Reject',
    strtolower($c->postback->trashStatusName) => 'Trash',
    default => ''
};

if ($inner_status === '') {
    http_response_code(500);
    $msg = 'Status ' . $status . ' is unknown! Url: ' . $curLink;
    add_log('postback', $msg);
    echo $msg;
    exit;
}

$currency = strtoupper($_REQUEST['currency'] ?? 'USD');
$payout = CurrencyConverter::convert($payout, $currency);

$updated = $db->update_status($clickid, $inner_status, $payout);

if ($updated) {
    process_s2s_posbacks($c->postback->s2sPostbacks, $inner_status, $click);
    http_response_code(200);
    $msg = 'Postback for clickid ' . $clickid . ' with status ' . $status . ' and payout ' . $payout . ' ' . $currency . ' accepted.';
    add_log('postback', $msg);
    echo $msg;
} else {
    http_response_code(404);
    $msg = 'Postback for clickid ' . $clickid . ' with status ' . $status . ' and payout ' . $payout . ' ' . $currency . ' NOT accepted! Clickid NOT FOUND.';
    add_log('postback', $msg);
    echo $msg;
}

function process_s2s_posbacks(array $s2s_postbacks, string $inner_status, array $click): void
{
    $clickid = (string)($click['clickid'] ?? '');
    $userid = (string)($click['userid'] ?? '');
    $mp = new MacrosProcessor(null, $click, $clickid, $userid);
    foreach ($s2s_postbacks as $s2s) {
        if (empty($s2s->url)) {
            continue;
        }
        if (!in_array($inner_status, $s2s->events, true)) {
            continue;
        }
        $final_url = str_replace('{status}', $inner_status, $s2s->url);
        $final_url = $mp->replace_url_macros($final_url);
        $s2s_res = '';
        switch ($s2s->method) {
            case 'GET':
                $s2s_res = get($final_url);
                break;
            case 'POST':
                $urlParts = explode('?', $final_url);
                $params = [];
                if (count($urlParts) > 1) {
                    parse_str($urlParts[1], $params);
                }
                $s2s_res = post($urlParts[0], $params);
                break;
        }
        add_log('postback', $s2s->method . ', ' . $final_url . ', ' . $inner_status . ', ' . $s2s_res['info']['http_code']);
    }
}
