<?php
require_once __DIR__ . '/../logging.php';
require_once __DIR__ . '/../db/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$clickid = trim((string)($_POST['clickid'] ?? ''));
$eventName = trim((string)($_POST['event'] ?? ''));
$valueRaw = $_POST['value'] ?? 1;

if ($clickid === '' || $eventName === '' || !preg_match('/^[a-z0-9_]+$/', $eventName) || !is_numeric((string)$valueRaw)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid event payload']);
    exit;
}

$value = (float)$valueRaw;
if (!is_finite($value) || $value == 0.0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid event value']);
    exit;
}

global $db;
$saved = $db->add_click_event($clickid, $eventName, $value);
if (!$saved) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save event']);
    exit;
}

echo json_encode(['ok' => true]);
