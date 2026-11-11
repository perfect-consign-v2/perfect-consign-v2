<?php

declare(strict_types=1);

namespace PerfectConsign\Schema;

use InvalidArgumentException;
use RuntimeException;

final class SchemaCatalog
{
    /** @var list<string> */
    public const PLATFORM = [
        '000_platform.sql',
    ];

    /** @var list<string> */
    public const PLATFORM_AFTER = [
        '055_store_signup.sql',
        '056_wipe_email_verifications.sql',
        '057_mail_outbox.sql',
        '058_worker_runs.sql',
        '059_worker_heartbeat.sql',
        '060_worker_runs_delete.sql',
        '061_admin_app_log_audit.sql',
        '062_wipe_password_resets.sql',
        '063_sign_in_failure_stale.sql',
        '065_stall_app_vendor_grant.sql',
        '066_co_owners.sql',
        '068_owner_can_sign_in.sql',
        '070_wipe_tenant_no_superuser.sql',
        '071_admin_sign_in_lockout.sql',
        '073_vendor_entry_role.sql',
        '075_password_reset_client.sql',
        '076_password_reset_delete.sql',
        '086_owner_duplicate_username.sql',
    ];

    /**
     * Post-baseline mall files only. Do not glob: leftover deleted migrations
     * (e.g. 034_piece_return.sql) on a deploy host must not be applied.
     *
     * @var list<string>
     */
    public const TENANT = [
        '001_tenant.sql',
        '064_staff_manager_role.sql',
        '067_staff_can_sign_in.sql',
        '069_snap_authorize.sql',
        '072_snap_discounts.sql',
        '074_vendor_entry_grants.sql',
        '077_second_signature_toggle.sql',
        '078_vendor_entry_revoke.sql',
        '079_override_client_address.sql',
        '080_party_model.sql',
        '081_seed_vendor_party_id.sql',
        '082_wait_list_open_email_assert.sql',
        '083_wait_list_email_var.sql',
        '084_payout_pack_grants.sql',
        '085_english_unique_refusals.sql',
        '087_person_place.sql',
        '088_party_contact.sql',
        '089_wait_list_party_contact.sql',
        '090_wait_list_contact_assert.sql',
        '091_wait_list_contact_assert_before_insert.sql',
    ];

    public function __construct(private readonly string $directory)
    {
    }

    /**
     * @return list<string>
     */
    public function tenantFiles(): array
    {
        return self::TENANT;
    }

    /**
     * @return list<string>
     */
    public function platformFiles(): array
    {
        return self::PLATFORM;
    }

    /**
     * @return list<string>
     */
    public function platformAfterFiles(): array
    {
        return self::PLATFORM_AFTER;
    }

    /**
     * @return array{tables: list<string>, routines: list<string>}
     */
    public function baselineObjects(): array
    {
        $tables = [];
        $routines = [];
        foreach ($this->tenantFiles() as $file) {
            $objects = $this->fileObjects($file);
            foreach ($objects['tables'] as $name) {
                $tables[$name] = $name;
            }
            foreach ($objects['routines'] as $name) {
                $routines[$name] = $name;
            }
        }
        $tableNames = array_values($tables);
        $routineNames = array_values($routines);
        sort($tableNames);
        sort($routineNames);

        return ['tables' => $tableNames, 'routines' => $routineNames];
    }

    /**
     * @return array{
     *     tables: list<string>,
     *     routines: list<string>,
     *     columns: list<array{0: string, 1: string}>,
     *     triggers: list<string>,
     *     constraints: list<string>
     * }
     */
    public function fileObjects(string $file): array
    {
        return (new SqlObjects())->from($this->read($file));
    }

    public function checksum(string $file): string
    {
        return hash('sha256', $this->read($file));
    }

    public function read(string $file): string
    {
        if (preg_match('/^[0-9]{3}_[a-z0-9_]+\.sql$/', $file) !== 1) {
            throw new InvalidArgumentException('schema file name is invalid');
        }
        $path = $this->directory . DIRECTORY_SEPARATOR . $file;
        if (!is_file($path)) {
            throw new RuntimeException('could not read ' . $file);
        }
        $bytes = file_get_contents($path);
        if ($bytes === false) {
            throw new RuntimeException('could not read ' . $file);
        }

        return $bytes;
    }

    public function rewrite(string $sql, string $schema, string $role): string
    {
        if (preg_match('/^tenant_[0-9a-f]{32}$/', $schema) !== 1) {
            throw new InvalidArgumentException('schema name is not a tenant schema');
        }
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $role) !== 1) {
            throw new InvalidArgumentException('poster role name is invalid');
        }

        return str_replace(
            ["'public'", 'public.', 'SCHEMA public', 'stall_poster'],
            ["'" . $schema . "'", $schema . '.', 'SCHEMA ' . $schema, $role],
            $sql
        );
    }
}
