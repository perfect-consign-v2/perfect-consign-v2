<?php

declare(strict_types=1);

$mode = $argv[1] ?? '';
$port = (int) ($argv[2] ?? 0);
$server = stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $error);
if ($server === false) {
    fwrite(STDERR, $error . "\n");
    exit(1);
}
$client = stream_socket_accept($server, 10);
if (!is_resource($client)) {
    exit(1);
}

$say = static function (string $line) use ($client): void {
    if (fwrite($client, $line . "\r\n") === false) {
        exit(1);
    }
};
$hear = static function () use ($client): string {
    $text = fgets($client, 515);

    return is_string($text) ? $text : '';
};

if ($mode === 'greeting') {
    $say('400 no');
    exit(0);
}
$say('220 ready');
$hear();
$say('250 hello');
$hear();
$say('220 go');
$hear();
$say('250 hello');
$hear();
$say('334 user');
$hear();
$say('334 pass');
$hear();
if ($mode === 'auth') {
    $say('535 Username and Password not accepted');
    exit(0);
}
$say('235 ok');
$hear();
$say('250 ok');
$hear();
if ($mode === 'rcpt') {
    $say('550 no such user');
    exit(0);
}
if ($mode === 'later') {
    $say('421 try later');
    exit(0);
}
$say('250 ok');
