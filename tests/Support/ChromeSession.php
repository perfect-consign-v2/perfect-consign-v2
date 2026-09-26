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
        $this->page->callFunction(self::SUBMIT, [
            $action,
            json_encode($fields, JSON_THROW_ON_ERROR),
        ])->waitForPageReload(Page::LOAD, 15000);

        return $this->html();
    }

    public function signIn(string $slug, string $username, string $password): string
    {
        $this->open('/' . $slug . '/login');

        return $this->submit('/' . $slug . '/login', [
            'username' => $username,
            'password' => $password,
        ]);
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
