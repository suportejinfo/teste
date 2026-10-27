<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    echo json_encode(['error' => 'Webhook não configurado']);
    exit;
}
$config = require $configFile;
$secret = (string)($config['client_secret'] ?? '');
if ($secret === '' || $secret === 'COLOQUE_AQUI_O_CLIENT_SECRET_DO_IFOOD') {
    http_response_code(503);
    echo json_encode(['error' => 'Client Secret não configurado']);
    exit;
}

$queueFile = __DIR__ . '/storage/events.json';
$action = (string)($_GET['action'] ?? 'receive');

function respond(int $status, array $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function withQueue(string $file, callable $callback) {
    $handle = fopen($file . '.lock', 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) fclose($handle);
        throw new RuntimeException('Não foi possível bloquear a fila.');
    }
    try {
        $queue = [];
        if (is_file($file)) {
            $contents = file_get_contents($file);
            $stored = is_string($contents) ? json_decode($contents, true) : null;
            if (!is_array($stored)) {
                $backup = $file . '.corrupt-' . gmdate('Ymd-His');
                if (is_string($contents)) @file_put_contents($backup, $contents, LOCK_EX);
                throw new RuntimeException('A fila existente está corrompida; foi preservada para recuperação.');
            }
            $queue = $stored;
        }
        $result = $callback($queue);
        $temporary = $file . '.tmp';
        if (file_put_contents($temporary, json_encode($queue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false
            || !rename($temporary, $file)) {
            @unlink($temporary);
            throw new RuntimeException('Não foi possível salvar a fila.');
        }
        return $result;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

if ($action === 'pull') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') respond(405, ['error' => 'Método não permitido']);
    $authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)
        || !hash_equals($secret, trim($matches[1]))) respond(401, ['error' => 'Não autorizado']);
    try {
        $events = withQueue($queueFile, static function (&$queue) { return array_values($queue); });
        respond(200, $events);
    } catch (Throwable $error) { respond(503, ['error' => 'Fila indisponível']); }
}

if ($action === 'ack') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(405, ['error' => 'Método não permitido']);
    $authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)
        || !hash_equals($secret, trim($matches[1]))) respond(401, ['error' => 'Não autorizado']);
    $request = json_decode((string)file_get_contents('php://input'), true);
    $ids = is_array($request['ids'] ?? null) ? array_fill_keys(array_map('strval', $request['ids']), true) : [];
    try {
        withQueue($queueFile, static function (&$queue) use ($ids) {
            $queue = array_values(array_filter($queue, static fn($event) => !isset($ids[(string)($event['id'] ?? '')])));
        });
        respond(200, ['status' => 'acknowledged']);
    } catch (Throwable $error) { respond(503, ['error' => 'Não foi possível confirmar a fila']); }
}

if ($action !== 'receive' || $_SERVER['REQUEST_METHOD'] !== 'POST') respond(405, ['error' => 'Método não permitido']);
$body = file_get_contents('php://input');
$signature = trim((string)($_SERVER['HTTP_X_IFOOD_SIGNATURE'] ?? ''));
$expected = hash_hmac('sha256', $body, $secret);
if ($signature === '' || !hash_equals($expected, strtolower($signature))) {
    respond(401, ['error' => 'Assinatura iFood inválida']);
}
$event = json_decode($body, true);
if (!is_array($event)) respond(400, ['error' => 'Evento JSON inválido']);
// O botão de teste de conexão do Portal pode enviar presença sem ID de pedido/evento.
if (empty($event['id'])) respond(200, ['status' => 'presence received']);
$now = gmdate('c');
$record = [
    'id' => (string)$event['id'],
    'merchantId' => (string)($event['merchantId'] ?? ''),
    'orderId' => (string)($event['orderId'] ?? ''),
    'code' => (string)($event['code'] ?? ''),
    'fullCode' => (string)($event['fullCode'] ?? ''),
    'createdAt' => $event['createdAt'] ?? null,
    'receivedAt' => $now,
    'rawJson' => $body,
    // Re-assinatura do corpo original já validado para a conferência do cliente PDV.
    'signature' => $expected,
];
try {
    withQueue($queueFile, static function (&$queue) use ($record) {
        foreach ($queue as $existing) if (($existing['id'] ?? '') === $record['id']) return;
        if (count($queue) >= 5000) throw new RuntimeException('Fila cheia');
        $queue[] = $record;
    });
    // Retorno 200 atende ao checklist específico de homologação Logistics.
    respond(200, ['status' => 'received']);
} catch (Throwable $error) {
    error_log('iFood webhook queue error: ' . $error->getMessage());
    respond(503, ['error' => 'Evento não foi persistido']);
}
