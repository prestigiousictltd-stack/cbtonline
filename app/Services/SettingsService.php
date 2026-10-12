<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Settings;

final class SettingsService
{
    public function __construct(
        private readonly AuditService $audit = new AuditService()
    ) {}

    /** @return array<string, mixed> */
    public function all(): array
    {
        return Settings::all();
    }

    /** @return array<string, array<string, mixed>> */
    public function allByGroup(): array
    {
        return [
            'Site'       => $this->pick(['site_name','site_tagline','site_email','site_phone','site_address','currency','currency_symbol','timezone']),
            'Submission' => $this->pick(['tracking_id_prefix','default_task_duration_minutes','max_concurrent_claims']),
            'Finance'    => $this->pick(['minimum_withdrawal','maximum_withdrawal','platform_commission_percent','payments_enabled_online','payments_enabled_bank']),
            'Uploads'    => $this->pick(['maximum_upload_size_kb','allowed_file_types']),
            'Security'   => $this->pick(['login_max_attempts','login_lockout_minutes','session_timeout_minutes']),
        ];
    }

    /**
     * Save multiple settings.
     *
     * @param  array<string, mixed>  $input   key => raw value (booleans may be absent)
     * @param  array<string, string> $schema  key => value_type
     * @return array{ok:bool,saved:int,errors:array<string,string>}
     */
    public function saveMany(int $superAdminId, array $input, array $schema): array
    {
        $errors = [];
        $saved  = 0;

        foreach ($schema as $key => $type) {
            $present = array_key_exists($key, $input);
            $raw     = $present ? $input[$key] : null;

            // Missing boolean checkbox means the user turned it off.
            if ($type === 'boolean' && !$present) {
                $raw     = '0';
                $present = true;
            }
            if (!$present) {
                continue;
            }

            $coerced = $this->coerce($type, $raw);
            $value   = $coerced[0];
            $err     = $coerced[1];

            if ($err !== null) {
                $errors[$key] = $err;
                continue;
            }

            $previous   = Settings::get($key);
            $prevStored = $this->storeForm($type, $previous);
            $nextStored = $this->storeForm($type, $value);

            if ($prevStored === $nextStored) {
                continue;
            }

            Settings::set($key, $value, $superAdminId);
            $saved++;

            $this->audit->log(
                'SETTING_UPDATED',
                'setting',
                null,
                'Setting updated: ' . $key,
                oldValues: ['key' => $key, 'value' => $prevStored],
                newValues: ['key' => $key, 'value' => $nextStored],
                userType: 'super_admin',
                userId: $superAdminId
            );
        }

        return ['ok' => empty($errors), 'saved' => $saved, 'errors' => $errors];
    }

    /**
     * @return array{0:mixed,1:?string}
     */
    private function coerce(string $type, mixed $value): array
    {
        switch ($type) {
            case 'integer':
                if ($value === '' || !is_numeric($value)) {
                    return [null, 'Must be a whole number.'];
                }
                return [(int) $value, null];

            case 'decimal':
                if ($value === '' || !is_numeric($value)) {
                    return [null, 'Must be a number.'];
                }
                try {
                    return [money_normalize((string) $value), null];
                } catch (\Throwable $e) {
                    return [null, 'Invalid amount.'];
                }

            case 'boolean':
                $on = !empty($value) && $value !== '0' && $value !== 0 && $value !== false;
                return [$on, null];

            case 'json':
                if (is_array($value)) {
                    return [$value, null];
                }
                if (is_string($value) && $value !== '') {
                    $decoded = json_decode($value, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        return [null, 'Invalid JSON.'];
                    }
                    return [$decoded, null];
                }
                return [null, null];

            case 'text':
            case 'string':
            default:
                $str = is_scalar($value) ? trim((string) $value) : '';
                if (strlen($str) > 5000) {
                    return [null, 'Value too long.'];
                }
                return [$str, null];
        }
    }

    private function storeForm(string $type, mixed $value): string
    {
        switch ($type) {
            case 'boolean':
                return !empty($value) ? '1' : '0';

            case 'integer':
                return (string) (int) $value;

            case 'decimal':
                return number_format((float) $value, 2, '.', '');

            case 'json':
                return is_array($value) ? (string) json_encode($value) : (string) $value;

            default:
                return (string) $value;
        }
    }

    /**
     * @param  string[] $keys
     * @return array<string, mixed>
     */
    private function pick(array $keys): array
    {
        $all = $this->all();
        $out = [];
        foreach ($keys as $k) {
            if (array_key_exists($k, $all)) {
                $out[$k] = $all[$k];
            }
        }
        return $out;
    }
}