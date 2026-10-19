<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Web;

use PerfectConsign\Shared\Http\View;
use PHPUnit\Framework\TestCase;

final class LayoutFoldTest extends TestCase
{
    public function testTheOwnerFoldsOnlyTheBooksGroup(): void
    {
        $html = $this->layout('owner');

        self::assertSame(1, substr_count($html, 'class="nav-books"'));
        self::assertStringContainsString('<summary class="nav-group-label">Books</summary>', $html);
        self::assertStringContainsString('<div class="nav-group-label">Counter</div>', $html);
    }

    public function testABooksLoginShowsBooksAsAPlainGroup(): void
    {
        $html = $this->layout('books');

        self::assertStringNotContainsString('class="nav-books"', $html);
        self::assertStringContainsString('<div class="nav-group-label">Books</div>', $html);
        self::assertStringContainsString('href="/demo/journals"', $html);
    }

    private function layout(string $role): string
    {
        $view = new View(dirname(__DIR__, 3) . '/src');
        ob_start();
        $view->render('Shared/Web/layout', [
            'title' => 'Demo',
            'displayName' => 'Demo Mall',
            'slug' => 'demo',
            'nav' => true,
            'csrf' => 'token',
            'error' => null,
            'notice' => null,
            'warning' => null,
            'body' => 'Shared/Web/not-found',
            'path' => '/demo/dashboard',
            'robots' => '',
            'activeRegister' => '',
            'chooseRegister' => false,
            'leftOpen' => [],
            'priorCloseStarted' => false,
            'priorPrompt' => false,
            'catalogNav' => [],
            'actorRole' => $role,
            'posShell' => false,
            'previewAs' => '',
        ]);
        $html = ob_get_clean();
        self::assertIsString($html);

        return $html;
    }
}
