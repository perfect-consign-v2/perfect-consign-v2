<?php

declare(strict_types=1);

namespace PerfectConsign\Bootstrap;

use PerfectApp\Session\Session;
use PerfectConsign\Feature\Mall\MallClock;
use PerfectConsign\Feature\Mall\MallSettings;
use PDO;

final class ActiveRegister
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Session $session,
        private readonly MallSettings $mall,
        private readonly MallClock $clock,
    ) {
    }

    public function ownerName(): string
    {
        $name = $this->session->get('owner_name');
        if (!is_string($name)) {
            return '';
        }

        return $name;
    }

    public function use(string $slug, string $sessionId, string $name): void
    {
        $this->session->set('register:' . $slug, $sessionId);
        $this->session->set('register_name:' . $slug, $name);
    }

    public function clear(string $slug, string $sessionId): void
    {
        $current = $this->session->get('register:' . $slug);
        if ($current !== $sessionId) {
            return;
        }
        $this->session->delete('register:' . $slug);
        $this->session->delete('register_name:' . $slug);
    }

    public function current(string $slug, bool $roled): string
    {
        return $this->chosen($slug, $roled)['id'];
    }

    public function posted(string $slug, string $requested, bool $roled): string
    {
        if ($requested !== '') {
            return $requested;
        }

        return $this->current($slug, $roled);
    }

    /**
     * @return array{id: string, name: string, choose: bool}
     */
    public function chosen(string $slug, bool $roled): array
    {
        $open = $this->openDrawers($roled);
        if ($open === null) {
            return ['id' => '', 'name' => '', 'choose' => false];
        }
        $match = $this->matchingDrawer($slug, $open);
        if ($match !== null) {
            return $match;
        }
        $storedId = $this->storedId($slug);
        if ($storedId !== '') {
            $this->clear($slug, $storedId);
        }

        return ['id' => '', 'name' => '', 'choose' => count($open) > 1];
    }

    /**
     * @return list<array{id: string, name: string, opened: string}>
     */
    public function leftOpen(bool $roled): array
    {
        $rows = $this->openRows($roled);
        if ($rows === null) {
            return [];
        }
        $today = $this->mall->today();
        $left = [];
        foreach ($rows as $row) {
            $openedOn = $this->clock->localDate($row['opened_at']);
            if ($openedOn === '' || $openedOn >= $today) {
                continue;
            }
            $left[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'opened' => $this->clock->localTime($row['opened_at']),
            ];
        }

        return $left;
    }

    /**
     * @return list<array{id: string, name: string}>|null
     */
    private function openDrawers(bool $roled): ?array
    {
        $rows = $this->fetchRows(
            $roled,
            'SELECT session.id, register.name
             FROM register_sessions AS session
             JOIN registers AS register ON register.id = session.register_id
             WHERE session.closed_at IS NULL
             ORDER BY lower(register.name), register.name'
        );
        if ($rows === null) {
            return null;
        }
        $drawers = [];
        foreach ($rows as $row) {
            $drawer = $this->namedDrawer($row);
            if ($drawer === null) {
                continue;
            }
            $drawers[] = $drawer;
        }

        return $drawers;
    }

    /**
     * @param list<array{id: string, name: string}> $open
     * @return array{id: string, name: string, choose: bool}|null
     */
    private function matchingDrawer(string $slug, array $open): ?array
    {
        $storedId = $this->storedId($slug);
        foreach ($open as $drawer) {
            if (count($open) === 1 || $drawer['id'] === $storedId) {
                $this->use($slug, $drawer['id'], $drawer['name']);

                return ['id' => $drawer['id'], 'name' => $drawer['name'], 'choose' => false];
            }
        }

        return null;
    }

    /**
     * @return list<array{id: string, name: string, opened_at: mixed}>|null
     */
    private function openRows(bool $roled): ?array
    {
        $rows = $this->fetchRows(
            $roled,
            'SELECT register.id, register.name, session.opened_at
             FROM register_sessions AS session
             JOIN registers AS register ON register.id = session.register_id
             WHERE session.closed_at IS NULL
             ORDER BY session.opened_at'
        );
        if ($rows === null) {
            return null;
        }
        $open = [];
        foreach ($rows as $row) {
            $drawer = $this->namedDrawer($row);
            if ($drawer === null) {
                continue;
            }
            $open[] = [
                'id' => $drawer['id'],
                'name' => $drawer['name'],
                'opened_at' => $row['opened_at'] ?? null,
            ];
        }

        return $open;
    }

    /**
     * @return list<array<mixed>>|null
     */
    private function fetchRows(bool $roled, string $sql): ?array
    {
        if (!$roled) {
            return null;
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute();
        $rows = $statement->fetchAll();
        if (!is_array($rows)) {
            return null;
        }
        $listed = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $listed[] = $row;
            }
        }

        return $listed;
    }

    /**
     * @return array{id: string, name: string}|null
     */
    private function namedDrawer(mixed $row): ?array
    {
        if (!is_array($row)) {
            return null;
        }
        $id = $row['id'] ?? null;
        $name = $row['name'] ?? null;
        if (!is_string($id) || !is_string($name)) {
            return null;
        }

        return ['id' => $id, 'name' => $name];
    }

    private function storedId(string $slug): string
    {
        $stored = $this->session->get('register:' . $slug);

        return is_string($stored) ? $stored : '';
    }
}
