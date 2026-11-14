<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Shared;

use InvalidArgumentException;
use PerfectConsign\Shared\Http\View;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ViewAndErrorTest extends TestCase
{
    public function testAMissingTemplateIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing template');
        (new View(dirname(__DIR__, 2) . '/src'))->render('Shared/Web/does-not-exist');
    }

    public function testAJournalLinePartialShowsASubledger(): void
    {
        $view = new View(dirname(__DIR__, 2) . '/src');
        ob_start();
        $view->render('Shared/Web/partials/line', [
            'name' => 'line',
            'slot' => 1,
            'accounts' => [['id' => 'a1', 'code' => '1000', 'name' => 'Cash']],
            'subledger' => true,
        ]);
        $html = ob_get_clean();
        self::assertIsString($html);
        self::assertStringContainsString('line_subledger_type_1', $html);
        ob_start();
        $view->render('Shared/Web/partials/line', [
            'name' => 'line',
            'slot' => 2,
            'accounts' => [],
            'subledger' => false,
        ]);
        $plain = ob_get_clean();
        self::assertIsString($plain);
        self::assertStringNotContainsString('line_subledger_type_2', $plain);
    }

    public function testAssetUrlAddsFilemtimeQuery(): void
    {
        $path = dirname(__DIR__, 2) . '/public/assets/js/register-ui.js';
        self::assertFileExists($path);
        $mtime = filemtime($path);
        self::assertIsInt($mtime);
        self::assertSame('/assets/js/register-ui.js?v=' . $mtime, View::asset('/assets/js/register-ui.js'));
        self::assertSame('../escape', View::asset('../escape'));
    }

    public function testPasswordFieldRendersShowToggle(): void
    {
        ob_start();
        View::requiredPasswordField(
            id: 'password',
            name: 'password',
            minlength: 8,
            autocomplete: 'current-password',
        );
        $html = ob_get_clean();
        self::assertIsString($html);
        self::assertStringContainsString('class="password-field"', $html);
        self::assertStringContainsString('type="password"', $html);
        self::assertStringContainsString('id="password"', $html);
        self::assertStringContainsString('name="password"', $html);
        self::assertStringContainsString('autocomplete="current-password"', $html);
        self::assertStringContainsString('minlength="8"', $html);
        self::assertStringContainsString(' required', $html);
        self::assertStringContainsString('aria-label="Show password"', $html);
        self::assertStringContainsString('password-field__toggle', $html);
        self::assertStringContainsString('password-field__icon--show', $html);
        self::assertStringContainsString('password-field__icon--hide', $html);
    }

    public function testOptionalPasswordFieldOmitsEmptyAttributes(): void
    {
        ob_start();
        View::passwordField(
            id: 'pw',
            name: 'pw',
            class: '',
            minlength: null,
            autocomplete: '',
            value: null,
        );
        $html = ob_get_clean();
        self::assertIsString($html);
        self::assertStringNotContainsString('class="input"', $html);
        self::assertStringNotContainsString('minlength=', $html);
        self::assertStringNotContainsString('autocomplete=', $html);
        self::assertStringNotContainsString(' value=', $html);
        self::assertStringNotContainsString(' required', $html);
    }

    public function testPasswordFieldKeepsPresetValue(): void
    {
        ob_start();
        View::passwordField(
            id: 'pw',
            name: 'pw',
            value: 'keep',
        );
        $html = ob_get_clean();
        self::assertIsString($html);
        self::assertStringContainsString('value="keep"', $html);
    }

    public function testAssetVersionUsesZeroWhenFileMissing(): void
    {
        self::assertSame('/assets/js/missing.js?v=0', View::asset('/assets/js/missing.js'));
    }

    public function testTextAndPhoneRefuseANonScalar(): void
    {
        $calls = [
            static fn () => View::text(['nope']),
            static fn () => View::phone(['nope']),
            static fn () => View::phoneTel(['nope']),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                self::fail('a non-scalar was accepted');
            } catch (InvalidArgumentException $error) {
                self::assertSame('expected text', $error->getMessage());
            }
        }
    }

    public function testABadTemplateVariableIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid template variable');
        (new View(dirname(__DIR__, 2) . '/src'))->render('Shared/Web/partials/line', [
            'name' => 'line',
            '1bad' => 'nope',
        ]);
    }

}
