<?php

declare(strict_types=1);

/**
 * Upsert private KB articles from this directory into local Hesk.
 *
 * Usage: php docs/hesk-kb/publish-to-hesk.php
 */

$heskSettings = 'C:/laragon/www/hesk/hesk_settings.inc.php';
if (!is_file($heskSettings)) {
    fwrite(STDERR, "Missing Hesk settings: {$heskSettings}\n");
    exit(1);
}

define('IN_SCRIPT', true);
require $heskSettings;

/** @var array<string, mixed> $hesk_settings */
$host = (string) $hesk_settings['db_host'];
$name = (string) $hesk_settings['db_name'];
$user = (string) $hesk_settings['db_user'];
$pass = (string) $hesk_settings['db_pass'];
$prefix = (string) $hesk_settings['db_pfix'];

$pdo = new PDO(
    "mysql:host={$host};dbname={$name};charset=utf8mb4",
    $user,
    $pass,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

$dir = __DIR__;
$today = (new DateTimeImmutable('now'))->format('Y-m-d');
$authorId = 1;

$catStmt = $pdo->query("SELECT `id`, `name` FROM `{$prefix}kb_categories`");
$categories = [];
foreach ($catStmt as $row) {
    $categories[(string) $row['name']] = (int) $row['id'];
}

$files = glob($dir . '/*.md') ?: [];
sort($files);

$published = 0;
foreach ($files as $path) {
    $base = basename($path);
    if ($base === 'README.md') {
        continue;
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        fwrite(STDERR, "Could not read {$path}\n");
        exit(1);
    }

    $parsed = parseSeedMarkdown($raw);
    $title = $parsed['title'];
    $category = $parsed['category'];
    $bodyMd = $parsed['body'];

    if ($title === '' || $category === '') {
        fwrite(STDERR, "Missing title/category in {$base}\n");
        exit(1);
    }

    if (!isset($categories[$category])) {
        fwrite(STDERR, "Unknown category '{$category}' in {$base}\n");
        exit(1);
    }

    $catId = $categories[$category];
    $html = markdownToHtml($bodyMd);

    $find = $pdo->prepare(
        "SELECT `id`, `type` FROM `{$prefix}kb_articles` WHERE `subject` = ? AND `catid` = ? LIMIT 1"
    );
    $find->execute([$title, $catId]);
    $existing = $find->fetch();

    $history = sprintf(
        '<li class="smaller">%s | published from docs/hesk-kb/%s</li>',
        htmlspecialchars($today, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        htmlspecialchars($base, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
    );

    if ($existing !== false) {
        $artId = (int) $existing['id'];
        $keywords = keywordsForArticle($title, $category, $bodyMd);
        $upd = $pdo->prepare(
            "UPDATE `{$prefix}kb_articles`
             SET `content` = ?, `type` = '0', `html` = '1', `keywords` = ?, `history` = CONCAT(COALESCE(`history`,''), ?)
             WHERE `id` = ?"
        );
        $upd->execute([$html, $keywords, $history, $artId]);
        echo "Updated #{$artId}: {$title}\n";
    } else {
        $orderStmt = $pdo->prepare(
            "SELECT `art_order` FROM `{$prefix}kb_articles` WHERE `catid` = ? AND `sticky` = '0' ORDER BY `art_order` DESC LIMIT 1"
        );
        $orderStmt->execute([$catId]);
        $lastOrder = $orderStmt->fetchColumn();
        $artOrder = $lastOrder === false ? 10 : ((int) $lastOrder + 10);

        $keywords = keywordsForArticle($title, $category, $bodyMd);
        $ins = $pdo->prepare(
            "INSERT INTO `{$prefix}kb_articles`
            (`catid`, `dt`, `author`, `subject`, `content`, `keywords`, `type`, `html`, `sticky`, `art_order`, `history`, `attachments`)
            VALUES (?, NOW(), ?, ?, ?, ?, '0', '1', '0', ?, ?, '')"
        );
        $ins->execute([
            $catId,
            $authorId,
            $title,
            $html,
            $keywords,
            $artOrder,
            $history,
        ]);
        echo "Inserted: {$title}\n";
    }

    $newRaw = preg_replace(
        '/^hesk:\s*.*$/m',
        'hesk: published ' . $today,
        $raw,
        1
    );
    if (!is_string($newRaw) || file_put_contents($path, $newRaw) === false) {
        fwrite(STDERR, "Could not mark published in {$base}\n");
        exit(1);
    }

    $published++;
}

recountPrivateArticles($pdo, $prefix);

$cache = 'C:/laragon/www/hesk/cache/kb.cache.php';
if (is_file($cache) && !unlink($cache)) {
    fwrite(STDERR, "Could not clear {$cache}\n");
    exit(1);
}

echo "Published {$published} articles. KB cache cleared.\n";

/**
 * @return array{title: string, category: string, body: string}
 */
function parseSeedMarkdown(string $raw): array
{
    if (!preg_match('/\A---\r?\n(.*?)\r?\n---\r?\n(.*)\z/s', $raw, $m)) {
        return ['title' => '', 'category' => '', 'body' => $raw];
    }

    $title = '';
    $category = '';
    foreach (preg_split('/\r?\n/', $m[1]) ?: [] as $line) {
        if (preg_match('/^title:\s*(.+)$/', $line, $tm)) {
            $title = trim($tm[1]);
        }
        if (preg_match('/^category:\s*(.+)$/', $line, $cm)) {
            $category = trim($cm[1]);
        }
    }

    return ['title' => $title, 'category' => $category, 'body' => ltrim($m[2])];
}

function keywordsForArticle(string $title, string $category, string $body): string
{
    $blob = strtolower($title . ' ' . $category . ' ' . $body);
    $words = preg_split('/\W+/', $blob) ?: [];
    $words = array_values(array_unique(array_filter(
        $words,
        static fn(string $w): bool => strlen($w) > 2
    )));

    return implode(',', array_slice($words, 0, 40));
}

function markdownToHtml(string $md): string
{
    $lines = preg_split('/\r?\n/', $md) ?: [];
    $html = [];
    $inList = false;
    $para = [];

    $flushPara = static function () use (&$para, &$html): void {
        if ($para === []) {
            return;
        }
        $text = trim(implode(' ', $para));
        $para = [];
        if ($text === '') {
            return;
        }
        $html[] = '<p>' . inlineMd($text) . '</p>';
    };

    $closeList = static function () use (&$inList, &$html): void {
        if ($inList) {
            $html[] = '</ul>';
            $inList = false;
        }
    };

    $firstHeadingSkipped = false;
    foreach ($lines as $line) {
        if (!$firstHeadingSkipped && preg_match('/^#\s+/', $line) === 1) {
            $firstHeadingSkipped = true;
            continue;
        }

        if (preg_match('/^##\s+(.+)$/', $line, $hm) === 1) {
            $flushPara();
            $closeList();
            $html[] = '<h2>' . inlineMd(trim($hm[1])) . '</h2>';
            continue;
        }

        if (preg_match('/^-\s+(.+)$/', $line, $lm) === 1) {
            $flushPara();
            if (!$inList) {
                $html[] = '<ul>';
                $inList = true;
            }
            $html[] = '<li>' . inlineMd(trim($lm[1])) . '</li>';
            continue;
        }

        if (trim($line) === '') {
            $flushPara();
            $closeList();
            continue;
        }

        $closeList();
        $para[] = trim($line);
    }

    $flushPara();
    $closeList();

    return implode("\n", $html);
}

function inlineMd(string $text): string
{
    $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $escaped = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
    $escaped = preg_replace('/`([^`]+)`/', '<code>$1</code>', $escaped) ?? $escaped;

    return $escaped;
}

function recountPrivateArticles(PDO $pdo, string $prefix): void
{
    $pdo->exec(
        "UPDATE `{$prefix}kb_categories` SET `articles` = 0, `articles_private` = 0, `articles_draft` = 0"
    );

    $counts = $pdo->query(
        "SELECT `catid`, `type`, COUNT(*) AS `num`
         FROM `{$prefix}kb_articles`
         GROUP BY `catid`, `type`"
    );

    $byCat = [];
    foreach ($counts as $row) {
        $catId = (int) $row['catid'];
        if (!isset($byCat[$catId])) {
            $byCat[$catId] = ['0' => 0, '1' => 0, '2' => 0];
        }
        $byCat[$catId][(string) $row['type']] = (int) $row['num'];
    }

    $upd = $pdo->prepare(
        "UPDATE `{$prefix}kb_categories`
         SET `articles` = ?, `articles_private` = ?, `articles_draft` = ?
         WHERE `id` = ?"
    );
    foreach ($byCat as $catId => $nums) {
        $upd->execute([$nums['0'], $nums['1'], $nums['2'], $catId]);
    }
}
