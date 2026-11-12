<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../dbconnect.php';

// A database that is down must reach this screen as words, not a stack trace.
require_once __DIR__ . '/../includes/db_guard.php';
marle_db_guard($conn, 'page', 'staff');
require_once __DIR__ . '/auth_admin.php';
require_once __DIR__ . '/../includes/log_action.php';
require_once __DIR__ . '/../luna_sentiment_service.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$payload = json_decode(file_get_contents('php://input'), true) ?: [];
if (($payload['mode'] ?? '') === 'summary') {
    echo json_encode([
        'success' => true,
        'summary' => LunaSentimentService::summary($conn, $payload['from'] ?? null, $payload['to'] ?? null),
    ]);
    exit();
}

$apiKey = '';
$configFile = __DIR__ . '/../groq_config.php';
if (file_exists($configFile)) {
    include $configFile;
}

if ($apiKey === '') {
    http_response_code(500);
    echo json_encode(['error' => 'Groq API key not configured.']);
    exit();
}

try {
    $limit = (int)($payload['limit'] ?? 100);
    $category = trim((string)($payload['category'] ?? '')) ?: null;
    $period = trim((string)($payload['period'] ?? '')) ?: null;
    $language = trim((string)($payload['language'] ?? '')) ?: null;
    $pipelineResult = LunaSentimentService::analyze($conn, $apiKey, $limit, $category, $period, $language);
    log_event($conn, 'sentiment_pipeline_run', 'sentiment', null, 'success', 'limit=' . $limit . ($category ? ', category=' . $category : '') . ($period ? ', period=' . $period : ''));
    echo json_encode(['success' => true, 'pipeline' => $pipelineResult]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $error->getMessage()]);
}