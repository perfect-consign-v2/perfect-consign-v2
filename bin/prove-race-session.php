<?php

declare(strict_types=1);

require_once __DIR__ . '/load-env.php';

loadStallEnv(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

$sql = stream_get_contents(STDIN);
if (!is_string($sql) || trim($sql) === '') {
    fwrite(STDERR, "race session sql is missing\n");
    exit(1);
}

$host = getenv('STALL_DB_HOST');
$port = getenv('STALL_DB_PORT');
$name = getenv('STALL_DB_NAME');
$user = getenv('STALL_DB_USER');
$password = getenv('STALL_DB_PASSWORD');
if (!is_string($host) || $host === '') {
    $host = '127.0.0.1';
}
if (!is_string($port) || $port === '') {
    $port = '5436';
}
if (!is_string($name) || $name === '') {
    $name = 'consign';
}
if (!is_string($user) || $user === '') {
    $user = 'stall';
}
if (!is_string($password) || $password === '') {
    $password = 'stall';
}

try {
    $pdo = new PDO(
        'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $name,
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $statements = sqlStatements($sql);
    $last = count($statements) - 1;
    foreach ($statements as $index => $statement) {
        $result = $pdo->query($statement);
        if ($result === false) {
            fwrite(STDERR, "race session query failed\n");
            exit(1);
        }
        if ($index !== $last || $result->columnCount() < 1) {
            continue;
        }
        $value = $result->fetchColumn();
        if (is_string($value) || is_int($value) || is_float($value)) {
            fwrite(STDOUT, (string) $value . "\n");
        }
    }
} catch (PDOException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}

/**
 * @return list<string>
 */
function sqlStatements(string $sql): array
{
    $lines = preg_split("/\\r\\n|\\n|\\r/", $sql) ?: [];
    $kept = [];
    foreach ($lines as $line) {
        if (str_starts_with(ltrim($line), '--')) {
            continue;
        }
        $kept[] = $line;
    }
    $source = implode("\n", $kept);
    $parts = [];
    $current = '';
    $quote = false;
    $length = strlen($source);
    for ($i = 0; $i < $length; $i++) {
        $char = $source[$i];
        if ($char === "'") {
            $current .= $char;
            if ($quote && ($source[$i + 1] ?? '') === "'") {
                $current .= "'";
                $i++;
                continue;
            }
            $quote = !$quote;
            continue;
        }
        if ($char === ';' && !$quote) {
            $statement = trim($current);
            if ($statement !== '') {
                $parts[] = $statement;
            }
            $current = '';
            continue;
        }
        $current .= $char;
    }
    $tail = trim($current);
    if ($tail !== '') {
        $parts[] = $tail;
    }

    return $parts;
}
