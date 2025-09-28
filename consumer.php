<?php
// обработчик MySQL и RabbitMQ

require __DIR__ . '/vendor/autoload.php';

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

$pdo = new PDO($config['db']['dsn'],$config['db']['user'],$config['db']['pass'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);

function validate($d){ return (empty($d['recipient'])||!filter_var($d['recipient'],FILTER_VALIDATE_EMAIL)||empty($d['sender'])||!filter_var($d['sender'],FILTER_VALIDATE_EMAIL)||!isset($d['message'])||trim($d['message'])===''); }

function insert($pdo,$d,$via){ $s=$pdo->prepare('INSERT INTO notifications (recipient,sender,message,via) VALUES (?,?,?,?)'); $s->execute([$d['recipient'],$d['sender'],$d['message'],$via]); }

use PhpAmqpLib\Connection\AMQPStreamConnection;

$c=$config['rabbit'];
$conn=new AMQPStreamConnection($c['host'],$c['port'],$c['user'],$c['pass']);
$ch=$conn->channel();
$ch->queue_declare($c['queue'],false,true,false,false);

echo "[consumer] waiting...\n";

$ch->basic_consume($c['queue'],'',false,false,false,false,function($msg)use($pdo){
    echo "got: ".$msg->body."\n";
    $d=json_decode($msg->body,true);
    if(!$d||validate($d)){ echo "invalid\n"; $msg->ack(); return; }
    insert($pdo,$d,'rabbit');
    echo "saved!\n";
    $msg->ack();
});

while($ch->is_consuming()) $ch->wait();