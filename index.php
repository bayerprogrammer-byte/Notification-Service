<?php
require __DIR__ . '/vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

// === CONFIG ===
$config = [
    'db' => [
        'dsn' => 'mysql:host=mysql;dbname=notifications_db;charset=utf8mb4',
        'user' => 'app',
        'pass' => 'apass',
    ],
    'rabbit' => [
        'host' => 'rabbitmq',
        'port' => 5672,
        'user' => 'guest',
        'pass' => 'guest',
        'queue' => 'notifications_queue'
    ]
];

// === DB ===
$pdo = new PDO($config['db']['dsn'], $config['db']['user'], $config['db']['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// === FUNCTIONS ===
function validate($data): array {
    $errors = [];
    if (empty($data['recipient']) || !filter_var($data['recipient'], FILTER_VALIDATE_EMAIL)) $errors[] = 'recipient invalid';
    if (empty($data['sender']) || !filter_var($data['sender'], FILTER_VALIDATE_EMAIL)) $errors[] = 'sender invalid';
    if (!isset($data['message']) || trim($data['message']) === '') $errors[] = 'message empty';
    return $errors;
}

function insert($pdo, $data, $via): int {
    $stmt = $pdo->prepare('INSERT INTO notifications (recipient, sender, message, via) VALUES (?,?,?,?)');
    $stmt->execute([$data['recipient'],$data['sender'],$data['message'],$via]);
    return (int)$pdo->lastInsertId();
}

function listAll($pdo, $limit=50, $offset=0): array {
    $stmt = $pdo->prepare('SELECT * FROM notifications ORDER BY id DESC LIMIT ? OFFSET ?');
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function publishRabbit($config, $data) {
    $conn = new AMQPStreamConnection($config['host'],$config['port'],$config['user'],$config['pass']);
    $ch = $conn->channel();
    $ch->queue_declare($config['queue'], false, true, false, false);
    $msg = new AMQPMessage(json_encode($data), ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT]);
    $ch->basic_publish($msg, '', $config['queue']);
    $ch->close();
    $conn->close();
}

// === ROUTER ===
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($method === 'POST' && $path === '/notifications') {
    header('Content-Type: application/json');
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) { http_response_code(400); echo json_encode(['error'=>'invalid json']); exit; }
    $errors = validate($body);
    if ($errors) { http_response_code(422); echo json_encode(['errors'=>$errors]); exit; }
    $id = insert($pdo,$body,'http');
    echo json_encode(['id'=>$id]);
    exit;
}

if ($method === 'GET' && $path === '/notifications') {
    header('Content-Type: application/json');
    $limit = isset($_GET['limit'])?(int)$_GET['limit']:50;
    $offset = isset($_GET['offset'])?(int)$_GET['offset']:0;
    echo json_encode(listAll($pdo,$limit,$offset));
    exit;
}

// === SIMPLE UI ===
if ($path === '/' && $method === 'GET') {
    $items = listAll($pdo,20,0);
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <title>Notifications</title>
        <style>
            body { font-family: sans-serif; margin: 2rem; background:#f5f5f5; }
            form { margin-bottom: 2rem; }
            input,textarea { display:block; margin:.5rem 0; padding:.5rem; width:300px; }
            button { padding:.5rem 1rem; }
            table { border-collapse: collapse; width:100%; background:white; }
            th,td { border:1px solid #ccc; padding:.5rem; }
        </style>
    </head>
    <body>
        <h1>Send Notification</h1>
        <form method="post" action="/notifications" onsubmit="send(event)">
            <input name="recipient" type="email" placeholder="Recipient email" required>
            <input name="sender" type="email" placeholder="Sender email" required>
            <textarea name="message" placeholder="Message" required></textarea>
            <button type="submit">Send</button>
        </form>

        <h2>Recent Notifications</h2>
        <table>
            <tr><th>ID</th><th>Recipient</th><th>Sender</th><th>Message</th><th>Via</th><th>Created</th></tr>
            <?php foreach ($items as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['id']) ?></td>
                <td><?= htmlspecialchars($row['recipient']) ?></td>
                <td><?= htmlspecialchars($row['sender']) ?></td>
                <td><?= htmlspecialchars($row['message']) ?></td>
                <td><?= htmlspecialchars($row['via']) ?></td>
                <td><?= htmlspecialchars($row['created_at']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>

        <script>
        async function send(e){
            e.preventDefault();
            const form = e.target;
            const data = Object.fromEntries(new FormData(form).entries());
            const res = await fetch('/notifications',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)});
            if(res.ok){ location.reload(); } else { alert('Error: '+(await res.text())); }
        }
        </script>
    </body>
    </html>
    <?php
    exit;
}
die('Тут ничего нет...Вот так вот');