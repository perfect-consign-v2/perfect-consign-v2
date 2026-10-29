<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Support;

use HeadlessChromium\Page;

final class ChromeSession
{
    private const SUBMIT = <<<'JS'
function (action, fieldsJson) {
    const fields = JSON.parse(fieldsJson);
    const forms = Array.from(document.querySelectorAll('form'));
    const form = forms.find((item) => item.getAttribute('action') === action && item.method.toLowerCase() === 'post');
    if (!form) {
        throw new Error('missing form ' + action);
    }
    Object.keys(fields).forEach((name) => {
        const input = form.querySelector('[name="' + name + '"]');
        if (!input) {
            throw new Error('missing ' + name);
        }
        input.value = fields[name];
    });
    form.submit();
}
JS;

    public function __construct(
        private readonly Page $page,
        private readonly string $baseUrl,
    ) {
    }

    public function open(string $path): string
    {
        $this->page->navigate($this->baseUrl . $path)->waitForNavigation(Page::LOAD, 15000);

        return $this->html();
    }

    /**
     * @param array<string, string> $fields
     */
    public function submit(string $action, array $fields): string
    {
        $evaluation = $this->page->callFunction(self::SUBMIT, [
            $action,
            json_encode($fields, JSON_THROW_ON_ERROR),
        ]);
        // Surface JS errors (missing form/field) instead of hanging on reload.
        $evaluation->waitForResponse(15000);
        $evaluation->waitForPageReload(Page::LOAD, 60000);

        return $this->html();
    }

    public function signIn(string $slug, string $username, string $password): string
    {
        $this->open('/');

        return $this->submit('/', [
            'email' => $this->emailForAccount($slug, $username),
            'password' => $password,
        ]);
    }

    private function emailForAccount(string $slug, string $username): string
    {
        if ($slug === ProbeMall::SLUG && $username === ProbeMall::USERNAME) {
            return ProbeMall::EMAIL;
        }
        $host = getenv('STALL_DB_HOST') ?: '127.0.0.1';
        $port = getenv('STALL_DB_PORT') ?: '5436';
        $name = getenv('STALL_DB_NAME') ?: 'consign';
        $user = getenv('STALL_DB_USER') ?: 'stall';
        $password = getenv('STALL_DB_PASSWORD') ?: 'stall';
        $pdo = new \PDO(
            'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $name,
            $user,
            $password,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
        $statement = $pdo->prepare(
            'SELECT directory.email
             FROM platform.sign_in_directory AS directory
             INNER JOIN platform.tenants AS tenants ON tenants.id = directory.tenant_id
             WHERE tenants.slug = ?
               AND directory.username = ?'
        );
        $statement->execute([$slug, strtolower($username)]);
        $email = $statement->fetchColumn();
        if (!is_string($email) || $email === '') {
            throw new \RuntimeException('no sign-in email for ' . $slug . '/' . $username);
        }

        return $email;
    }

    public function submitStickerJob(string $action, string $itemId): string
    {
        $js = <<<'JS'
function (action, itemId) {
    const forms = Array.from(document.querySelectorAll('form'));
    const form = forms.find((item) => item.getAttribute('action') === action && item.method.toLowerCase() === 'post');
    if (!form) {
        throw new Error('missing form ' + action);
    }
    const box = document.querySelector('input[name="item[]"][value="' + itemId + '"]');
    if (!box) {
        throw new Error('missing item ' + itemId);
    }
    box.checked = true;
    form.submit();
}
JS;
        $evaluation = $this->page->callFunction($js, [$action, $itemId]);
        $evaluation->waitForResponse(15000);
        $evaluation->waitForPageReload(Page::LOAD, 60000);

        return $this->html();
    }

    public function clickSelector(string $css, bool $waitsForNavigation = false): string
    {
        $js = <<<'JS'
function (selector) {
    window.print = function () {};
    const node = document.querySelector(selector);
    if (!node) {
        throw new Error('missing ' + selector);
    }
    node.click();
}
JS;
        $evaluation = $this->page->callFunction($js, [$css]);
        $evaluation->waitForResponse(15000);
        if ($waitsForNavigation) {
            $evaluation->waitForPageReload(Page::LOAD, 60000);
        } else {
            usleep(200_000);
        }

        return $this->html();
    }

    public function currentPath(): string
    {
        $url = $this->page->getCurrentUrl();
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['path']) || !is_string($parts['path'])) {
            return '';
        }
        $path = $parts['path'];
        if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
            $path .= '?' . $parts['query'];
        }

        return $path;
    }

    public function close(): void
    {
        $this->page->close();
    }

    public function html(): string
    {
        return $this->page->getHtml(15000);
    }
}
