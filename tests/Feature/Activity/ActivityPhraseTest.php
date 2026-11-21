<?php

declare(strict_types=1);

namespace PerfectConsign\Tests\Feature\Activity;

use PerfectConsign\Feature\Activity\ActivityPhrase;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ActivityPhraseTest extends TestCase
{
    public function testEveryRecordedActionHasAPhrase(): void
    {
        $missing = [];
        foreach ($this->actions(dirname(__DIR__, 3)) as $action) {
            if (!ActivityPhrase::has($action)) {
                $missing[] = $action;
            }
        }

        self::assertSame([], $missing);
    }

    public function testAnUnknownActionStaysTheStoredCode(): void
    {
        self::assertSame('not.a.real.action', ActivityPhrase::text('not.a.real.action'));
        self::assertSame('Changed a vendor', ActivityPhrase::text('vendor.edit'));
        self::assertSame('Changed a consignor', ActivityPhrase::text('vendor.edit', 'consignor'));
        self::assertSame('Saved company settings', ActivityPhrase::text('settings.edit', 'consignor'));
    }

    public function testChoicesFollowThePhrase(): void
    {
        $phrases = array_column(ActivityPhrase::choices(), 'phrase');
        $sorted = $phrases;
        sort($sorted, SORT_FLAG_CASE | SORT_STRING);

        self::assertSame($sorted, $phrases);
        self::assertContains('Saved company settings', $phrases);
    }

    /**
     * @return list<string>
     */
    private function actions(string $root): array
    {
        $found = [];
        $source = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src'));
        foreach ($source as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $code = file_get_contents($file->getPathname());
            if (!is_string($code)) {
                continue;
            }
            foreach ($this->calls($code) as $action) {
                $found[$action] = true;
            }
        }
        $sql = file_get_contents($root . '/sql/103_audit_subject.sql');
        if (is_string($sql) && str_contains($sql, "'markdown-floor'")) {
            $found['markdown-floor'] = true;
        }
        $actions = array_keys($found);
        sort($actions);

        return $actions;
    }

    /**
     * @return list<string>
     */
    private function calls(string $code): array
    {
        $tokens = token_get_all($code);
        $actions = [];
        $count = count($tokens);
        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            if (!is_array($token) || !$this->objectOperator($token[0])) {
                continue;
            }
            $nameAt = $this->next($tokens, $index + 1);
            if ($nameAt === null || !is_array($tokens[$nameAt]) || $tokens[$nameAt][0] !== T_STRING) {
                continue;
            }
            if (!in_array($tokens[$nameAt][1], ['record', 'on', 'note', 'second', 'kept', 'created', 'post'], true)) {
                continue;
            }
            $paren = $this->next($tokens, $nameAt + 1);
            if ($paren === null || $tokens[$paren] !== '(') {
                continue;
            }
            foreach ($this->arguments($tokens, $paren) as $literal) {
                $action = $this->action($literal);
                if ($action !== null) {
                    $actions[$action] = true;
                }
            }
        }

        return array_keys($actions);
    }

    private function objectOperator(int $kind): bool
    {
        if ($kind === T_OBJECT_OPERATOR) {
            return true;
        }

        return defined('T_NULLSAFE_OBJECT_OPERATOR') && $kind === T_NULLSAFE_OBJECT_OPERATOR;
    }

    /**
     * @param list<mixed> $tokens
     * @return list<string>
     */
    private function arguments(array $tokens, int $open): array
    {
        $strings = [];
        $depth = 0;
        $count = count($tokens);
        for ($index = $open; $index < $count; $index++) {
            $token = $tokens[$index];
            if ($token === '(') {
                $depth++;
                continue;
            }
            if ($token === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
                continue;
            }
            if ($depth !== 1 || !is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $strings[] = $this->literal($token[1]);
        }

        return $strings;
    }

    /**
     * @param list<mixed> $tokens
     */
    private function next(array $tokens, int $index): ?int
    {
        $count = count($tokens);
        while ($index < $count) {
            $token = $tokens[$index];
            if (is_array($token) && ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                $index++;
                continue;
            }

            return $index;
        }

        return null;
    }

    private function literal(string $token): string
    {
        $body = substr($token, 1, -1);
        if ($token !== '' && $token[0] === "'") {
            return str_replace(["\\'", '\\\\'], ["'", '\\'], $body);
        }

        return stripcslashes($body);
    }

    private function action(string $value): ?string
    {
        if (preg_match('/^(admin|tenant|store|app)\./', $value) === 1) {
            return null;
        }
        if (preg_match('/^[a-z][a-z0-9_-]*\.[a-z0-9_.-]+$/', $value) === 1) {
            return $value;
        }
        if ($value === 'booth_charge' || $value === 'booth_deposit') {
            return $value;
        }

        return null;
    }
}
