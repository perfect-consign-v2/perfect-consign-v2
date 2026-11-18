<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Catalog;

final class NameBoard
{
    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, true> $held
     * @param array<string, int> $counts
     * @return list<array<string, mixed>>
     */
    public static function mark(array $rows, array $held, array $counts): array
    {
        $marked = [];
        foreach ($rows as $row) {
            $marked[] = self::used($row, $held, $counts);
        }

        return $marked;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public static function confirming(array $rows, string $confirm): string
    {
        if (!self::uuid($confirm)) {
            return '';
        }

        return self::deletableId($rows, strtolower($confirm));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{id: string, sentence: string, choices: list<array<string, mixed>>}
     */
    public static function merge(array $rows, string $requested): array
    {
        if (!self::uuid($requested)) {
            return self::none();
        }

        return self::opened($rows, strtolower($requested));
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, true> $held
     * @param array<string, int> $counts
     * @return array<string, mixed>
     */
    private static function used(array $row, array $held, array $counts): array
    {
        $id = $row['id'] ?? null;
        $row['active'] = CatalogFlag::on($row['active'] ?? true);
        $row['deletable'] = is_string($id) && !isset($held[$id]);
        $row['use_count'] = is_string($id) ? ($counts[$id] ?? 0) : 0;

        return $row;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function deletableId(array $rows, string $needle): string
    {
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            if (!is_string($id) || strtolower($id) !== $needle || ($row['deletable'] ?? false) !== true) {
                continue;
            }

            return $id;
        }

        return '';
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{id: string, sentence: string, choices: list<array<string, mixed>>}
     */
    private static function opened(array $rows, string $needle): array
    {
        $split = self::split($rows, $needle);
        $source = $split['source'];
        $choices = $split['choices'];
        if ($source === null || $choices === []) {
            return self::none();
        }

        return self::form($source, $choices);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{source: array<string, mixed>|null, choices: list<array<string, mixed>>}
     */
    private static function split(array $rows, string $needle): array
    {
        $source = null;
        $choices = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            if (!is_string($id)) {
                continue;
            }
            if (strtolower($id) === $needle) {
                $source = $row;
                continue;
            }
            $choices[] = $row;
        }

        return ['source' => $source, 'choices' => $choices];
    }

    /**
     * @param array<string, mixed> $source
     * @param list<array<string, mixed>> $choices
     * @return array{id: string, sentence: string, choices: list<array<string, mixed>>}
     */
    private static function form(array $source, array $choices): array
    {
        $id = $source['id'] ?? null;

        return [
            'id' => is_string($id) ? $id : '',
            'sentence' => MergePrompt::sentence(self::count($source), self::label($source), self::keeper($choices)),
            'choices' => $choices,
        ];
    }

    /**
     * @param array<string, mixed> $source
     */
    private static function count(array $source): int
    {
        $count = $source['use_count'] ?? null;

        return is_int($count) ? $count : 0;
    }

    /**
     * @param array<string, mixed> $source
     */
    private static function label(array $source): string
    {
        $name = $source['name'] ?? null;

        return is_string($name) ? $name : '';
    }

    /**
     * @param list<array<string, mixed>> $choices
     */
    private static function keeper(array $choices): ?string
    {
        if (count($choices) !== 1) {
            return null;
        }
        $name = $choices[0]['name'] ?? null;

        return is_string($name) ? $name : null;
    }

    /**
     * @return array{id: string, sentence: string, choices: list<array<string, mixed>>}
     */
    private static function none(): array
    {
        return ['id' => '', 'sentence' => '', 'choices' => []];
    }

    private static function uuid(string $value): bool
    {
        return preg_match('/^[0-9a-fA-F-]{36}$/', $value) === 1;
    }
}
