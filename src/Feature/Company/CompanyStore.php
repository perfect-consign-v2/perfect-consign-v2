<?php

declare(strict_types=1);

namespace PerfectConsign\Feature\Company;

use PerfectConsign\Bootstrap\DatabaseRole;
use PerfectConsign\Shared\Database\Statements;
use PerfectConsign\Shared\Scalar\RowValue;
use PerfectConsign\Shared\Tenant\TenantRecord;
use PDO;

final class CompanyStore
{
    public function __construct(
        private readonly Statements $statements,
        private readonly DatabaseRole $roles,
        private readonly PDO $pdo,
        private readonly CompanyLines $lines,
    ) {
    }

    /**
     * @return array{name: string, street: string, street2: string, city: string, state: string, postal: string, phone: string, email: string}
     */
    public function profile(TenantRecord $tenant): array
    {
        $contact = $this->contact();

        return [
            'name' => $tenant->displayName,
            'street' => $contact['street'],
            'street2' => $contact['street2'],
            'city' => $contact['city'],
            'state' => $contact['state'],
            'postal' => $contact['postal'],
            'phone' => $contact['phone'],
            'email' => $contact['email'],
        ];
    }

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        return $this->lines->printed($this->contact());
    }

    public function save(TenantRecord $tenant, CompanyDraft $draft): void
    {
        $this->statements->transaction(function () use ($tenant, $draft): void {
            $this->writeContact($draft);
            $this->rename($tenant, $draft->name());
        });
    }

    private function writeContact(CompanyDraft $draft): void
    {
        $count = $this->statements->affected(
            'UPDATE mall SET street = ?, street2 = ?, city = ?, state = ?, postal = ?, phone = ?, email = ?',
            $draft->contact()
        );
        if ($count !== 1) {
            throw new \RuntimeException('the mall has no company row');
        }
    }

    private function rename(TenantRecord $tenant, string $name): void
    {
        $this->command('SAVEPOINT company_name');
        $this->roles->reset();
        try {
            $this->writeName($tenant->id, $name);
            $this->command('RELEASE SAVEPOINT company_name');
        } catch (\Throwable $error) {
            $this->command('ROLLBACK TO SAVEPOINT company_name');
            throw $error;
        } finally {
            $this->roles->assume($tenant->role);
        }
    }

    private function writeName(string $tenantId, string $name): void
    {
        $rows = $this->statements->bound(
            'UPDATE platform.tenants SET display_name = ? WHERE id = ?::uuid RETURNING id',
            [$name, $tenantId]
        );
        if ($rows === []) {
            throw new \InvalidArgumentException('That mall was not found.');
        }
    }

    private function command(string $sql): void
    {
        if ($this->pdo->exec($sql) === false) {
            throw new \RuntimeException('the company save could not finish');
        }
    }

    /**
     * @return array{street: string, street2: string, city: string, state: string, postal: string, phone: string, email: string}
     */
    private function contact(): array
    {
        $rows = $this->statements->rows(
            'SELECT street, street2, city, state, postal, phone, email FROM mall'
        );
        $row = $rows[0] ?? null;
        if (!is_array($row)) {
            throw new \RuntimeException('the mall has no company row');
        }

        return [
            'street' => $this->text($row['street'] ?? null),
            'street2' => $this->text($row['street2'] ?? null),
            'city' => $this->text($row['city'] ?? null),
            'state' => $this->text($row['state'] ?? null),
            'postal' => $this->text($row['postal'] ?? null),
            'phone' => $this->text($row['phone'] ?? null),
            'email' => $this->text($row['email'] ?? null),
        ];
    }

    private function text(mixed $value): string
    {
        return RowValue::optionalString($value) ?? '';
    }
}
