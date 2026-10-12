<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\ServiceFieldRepository;
use App\Repositories\ServiceRepository;
use RuntimeException;

final class ServiceAdminService
{
    public const FIELD_TYPES = [
        'text', 'textarea', 'number', 'email', 'tel',
        'select', 'radio', 'checkbox', 'date',
    ];

    public function __construct(
        private readonly ServiceRepository      $services = new ServiceRepository(),
        private readonly ServiceFieldRepository $fields   = new ServiceFieldRepository(),
        private readonly AuditService           $audit    = new AuditService(),
    ) {}

    // ================================================================
    // SERVICES
    // ================================================================

    /**
     * @return array{ok:bool,id?:int,errors?:array<string,string>}
     */
    public function createService(int $superAdminId, array $input): array
    {
        $data = $this->normalizeServiceInput($input);
        $errors = $this->validateService($data, null);
        if ($errors) return ['ok' => false, 'errors' => $errors];

        $id = $this->services->create($data);

        $this->audit->log(
            'SERVICE_CREATED', 'service', $id,
            'Service created: ' . $data['name'],
            newValues: $data,
            userType: 'super_admin', userId: $superAdminId
        );
        return ['ok' => true, 'id' => $id];
    }

    /**
     * @return array{ok:bool,errors?:array<string,string>}
     */
    public function updateService(int $superAdminId, int $id, array $input): array
    {
        $existing = $this->services->findById($id);
        if (!$existing) return ['ok' => false, 'errors' => ['general' => 'Service not found.']];

        $data   = $this->normalizeServiceInput($input);
        $errors = $this->validateService($data, $id);
        if ($errors) return ['ok' => false, 'errors' => $errors];

        $this->services->update($id, $data);

        // Build a diff for the audit trail
        $old = [];
        $new = [];
        foreach (['name','slug','description','short_description','price','admin_commission',
                  'processing_minutes','duration_label','icon','sort_order','status'] as $k) {
            if (array_key_exists($k, $data) && (string) ($existing[$k] ?? '') !== (string) $data[$k]) {
                $old[$k] = $existing[$k] ?? null;
                $new[$k] = $data[$k];
            }
        }

        if ($old) {
            $this->audit->log(
                'SERVICE_UPDATED', 'service', $id,
                'Service updated: ' . $existing['name'],
                oldValues: $old,
                newValues: $new,
                userType: 'super_admin', userId: $superAdminId
            );
        }

        return ['ok' => true];
    }

    public function setServiceStatus(int $superAdminId, int $id, string $status): array
    {
        if (!in_array($status, ['active', 'inactive'], true)) {
            return ['ok' => false, 'errors' => ['general' => 'Invalid status.']];
        }
        $existing = $this->services->findById($id);
        if (!$existing) return ['ok' => false, 'errors' => ['general' => 'Service not found.']];
        if ($existing['status'] === $status) return ['ok' => true];

        $this->services->setStatus($id, $status);
        $this->audit->log(
            'SERVICE_STATUS_CHANGED', 'service', $id,
            'Service status changed: ' . $status,
            oldValues: ['status' => $existing['status']],
            newValues: ['status' => $status],
            userType: 'super_admin', userId: $superAdminId
        );
        return ['ok' => true];
    }

    // ================================================================
    // FIELDS
    // ================================================================

    public function createField(int $superAdminId, int $serviceId, array $input): array
    {
        $service = $this->services->findById($serviceId);
        if (!$service) return ['ok' => false, 'errors' => ['general' => 'Service not found.']];

        $data   = $this->normalizeFieldInput($serviceId, $input);
        $errors = $this->validateField($serviceId, $data, null);
        if ($errors) return ['ok' => false, 'errors' => $errors];

        // Auto-assign sort order if not provided
        if (empty($data['sort_order'])) {
            $data['sort_order'] = $this->fields->nextSortOrder($serviceId);
        }

        $id = $this->fields->create($data);

        $this->audit->log(
            'SERVICE_FIELD_CREATED', 'service_field', $id,
            'Field added: ' . $data['field_label'] . ' to service ' . $service['name'],
            newValues: $data,
            userType: 'super_admin', userId: $superAdminId
        );
        return ['ok' => true, 'id' => $id];
    }

    public function updateField(int $superAdminId, int $fieldId, array $input): array
    {
        $existing = $this->fields->findById($fieldId);
        if (!$existing) return ['ok' => false, 'errors' => ['general' => 'Field not found.']];

        $serviceId = (int) $existing['service_id'];
        $data      = $this->normalizeFieldInput($serviceId, $input);
        $errors    = $this->validateField($serviceId, $data, $fieldId);
        if ($errors) return ['ok' => false, 'errors' => $errors];

        // Field key cannot change after the field has been used in submissions
        if ($this->fields->usageCount($fieldId) > 0 && ($data['field_key'] ?? null) !== $existing['field_key']) {
            return ['ok' => false, 'errors' => [
                'field_key' => 'Field key cannot be changed because submissions already reference this field.',
            ]];
        }

        $this->fields->update($fieldId, $data);

        $old = [];
        $new = [];
        foreach ($data as $k => $v) {
            $prev = $existing[$k] ?? null;
            if ($k === 'field_options') {
                $prev = $prev !== null ? json_decode((string) $prev, true) : null;
            }
            if ((string) json_encode($prev) !== (string) json_encode($v)) {
                $old[$k] = $prev;
                $new[$k] = $v;
            }
        }

        if ($old) {
            $this->audit->log(
                'SERVICE_FIELD_UPDATED', 'service_field', $fieldId,
                'Field updated: ' . $existing['field_label'],
                oldValues: $old,
                newValues: $new,
                userType: 'super_admin', userId: $superAdminId
            );
        }
        return ['ok' => true];
    }

    public function deleteField(int $superAdminId, int $fieldId): array
    {
        $existing = $this->fields->findById($fieldId);
        if (!$existing) return ['ok' => false, 'errors' => ['general' => 'Field not found.']];

        if ($this->fields->usageCount($fieldId) > 0) {
            // Soft-disable instead of hard delete so historic submissions keep their data
            $this->fields->setStatus($fieldId, 'inactive');
            $this->audit->log(
                'SERVICE_FIELD_DISABLED', 'service_field', $fieldId,
                'Field deactivated (has submissions): ' . $existing['field_label'],
                userType: 'super_admin', userId: $superAdminId
            );
            return ['ok' => true, 'soft' => true];
        }

        $this->fields->delete($fieldId);
        $this->audit->log(
            'SERVICE_FIELD_DELETED', 'service_field', $fieldId,
            'Field deleted: ' . $existing['field_label'],
            oldValues: $existing,
            userType: 'super_admin', userId: $superAdminId
        );
        return ['ok' => true];
    }

    public function setFieldStatus(int $superAdminId, int $fieldId, string $status): array
    {
        if (!in_array($status, ['active', 'inactive'], true)) {
            return ['ok' => false, 'errors' => ['general' => 'Invalid status.']];
        }
        $existing = $this->fields->findById($fieldId);
        if (!$existing) return ['ok' => false, 'errors' => ['general' => 'Field not found.']];
        if ($existing['status'] === $status) return ['ok' => true];

        $this->fields->setStatus($fieldId, $status);
        $this->audit->log(
            'SERVICE_FIELD_STATUS_CHANGED', 'service_field', $fieldId,
            'Field status: ' . $status,
            oldValues: ['status' => $existing['status']],
            newValues: ['status' => $status],
            userType: 'super_admin', userId: $superAdminId
        );
        return ['ok' => true];
    }

    public function moveField(int $superAdminId, int $fieldId, string $direction): array
    {
        if (!in_array($direction, ['up', 'down'], true)) {
            return ['ok' => false, 'errors' => ['general' => 'Invalid direction.']];
        }
        $existing = $this->fields->findById($fieldId);
        if (!$existing) return ['ok' => false, 'errors' => ['general' => 'Field not found.']];

        $siblings = $this->fields->listAllForService((int) $existing['service_id']);
        $index = null;
        foreach ($siblings as $i => $row) {
            if ((int) $row['id'] === $fieldId) { $index = $i; break; }
        }
        if ($index === null) return ['ok' => false, 'errors' => ['general' => 'Field not in list.']];

        $targetIndex = $direction === 'up' ? $index - 1 : $index + 1;
        if ($targetIndex < 0 || $targetIndex >= count($siblings)) {
            return ['ok' => true]; // already at the edge
        }

        $this->fields->swapSortOrder($fieldId, (int) $siblings[$targetIndex]['id']);
        $this->audit->log(
            'SERVICE_FIELD_REORDERED', 'service_field', $fieldId,
            'Field moved ' . $direction,
            userType: 'super_admin', userId: $superAdminId
        );
        return ['ok' => true];
    }

    // ================================================================
    // VALIDATION / NORMALISATION
    // ================================================================

    private function normalizeServiceInput(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $slug = trim((string) ($input['slug'] ?? ''));

        // Auto-generate slug if missing
        if ($slug === '') {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? '');
            $slug = trim($slug, '-');
        }

        return [
            'name'               => $name,
            'slug'               => $slug,
            'description'        => trim((string) ($input['description'] ?? '')) ?: null,
            'short_description'  => trim((string) ($input['short_description'] ?? '')) ?: null,
            'price'              => money_normalize((string) ($input['price'] ?? '0')),
            'admin_commission'   => money_normalize((string) ($input['admin_commission'] ?? '0')),
            'processing_minutes' => (int) ($input['processing_minutes'] ?? 1440),
            'duration_label'     => trim((string) ($input['duration_label'] ?? '')) ?: null,
            'icon'               => trim((string) ($input['icon'] ?? '')) ?: null,
            'sort_order'         => (int) ($input['sort_order'] ?? 0),
            'status'             => (string) ($input['status'] ?? 'active'),
        ];
    }

    private function validateService(array $data, ?int $id): array
    {
        $errors = [];

        if ($data['name'] === '' || mb_strlen($data['name']) < 3) {
            $errors['name'] = 'Service name is required (min 3 characters).';
        } elseif (mb_strlen($data['name']) > 150) {
            $errors['name'] = 'Service name must not exceed 150 characters.';
        }

        if ($data['slug'] === '' || !preg_match('/^[a-z0-9\-]{2,160}$/', $data['slug'])) {
            $errors['slug'] = 'Slug must be lowercase letters, digits and dashes only.';
        } elseif ($this->services->slugExists($data['slug'], $id)) {
            $errors['slug'] = 'This slug is already in use by another service.';
        }

        if (money_cmp($data['price'], '0.00') < 0) {
            $errors['price'] = 'Price cannot be negative.';
        }
        if (money_cmp($data['admin_commission'], '0.00') < 0) {
            $errors['admin_commission'] = 'Commission cannot be negative.';
        }
        if (money_cmp($data['admin_commission'], $data['price']) > 0) {
            $errors['admin_commission'] = 'Commission cannot exceed the service price.';
        }
        if ($data['processing_minutes'] <= 0) {
            $errors['processing_minutes'] = 'Processing duration must be a positive number of minutes.';
        }
        if ($data['processing_minutes'] > 60 * 24 * 60) {
            $errors['processing_minutes'] = 'Processing duration cannot exceed 60 days.';
        }
        if (!in_array($data['status'], ['active', 'inactive'], true)) {
            $errors['status'] = 'Invalid status.';
        }
        if (!empty($data['icon']) && !preg_match('/^[A-Za-z0-9_\-\.\/]+$/', $data['icon'])) {
            $errors['icon'] = 'Icon path contains invalid characters.';
        }
        return $errors;
    }

    private function normalizeFieldInput(int $serviceId, array $input): array
    {
        $type = (string) ($input['field_type'] ?? 'text');

        // Options: accept array or comma-separated string
        $options = null;
        if (in_array($type, ['select','radio','checkbox'], true)) {
            $raw = $input['field_options'] ?? null;
            if (is_array($raw)) {
                $options = array_values(array_filter(array_map('trim', $raw), fn($v) => $v !== ''));
            } elseif (is_string($raw)) {
                $options = array_values(array_filter(array_map('trim', explode(',', $raw)), fn($v) => $v !== ''));
            }
            if (empty($options)) $options = null;
        }

        $key = (string) ($input['field_key'] ?? '');
        if ($key === '') {
            $label = (string) ($input['field_label'] ?? '');
            $key   = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $label) ?? '');
            $key   = trim($key, '_');
        }

        return [
            'service_id'        => $serviceId,
            'field_key'         => $key,
            'field_label'       => trim((string) ($input['field_label'] ?? '')),
            'field_type'        => $type,
            'field_options'     => $options,
            'placeholder'       => trim((string) ($input['placeholder'] ?? '')) ?: null,
            'help_text'         => trim((string) ($input['help_text'] ?? '')) ?: null,
            'is_required'       => !empty($input['is_required']) ? 1 : 0,
            'validation_regex'  => trim((string) ($input['validation_regex'] ?? '')) ?: null,
            'max_length'        => ($input['max_length'] ?? '') !== '' ? (int) $input['max_length'] : null,
            'sort_order'        => (int) ($input['sort_order'] ?? 0),
            'status'            => (string) ($input['status'] ?? 'active'),
        ];
    }

    private function validateField(int $serviceId, array $data, ?int $id): array
    {
        $errors = [];

        if ($data['field_label'] === '' || mb_strlen($data['field_label']) < 2) {
            $errors['field_label'] = 'Field label is required (min 2 characters).';
        } elseif (mb_strlen($data['field_label']) > 150) {
            $errors['field_label'] = 'Field label must not exceed 150 characters.';
        }

        if ($data['field_key'] === '' || !preg_match('/^[a-z][a-z0-9_]{1,59}$/', $data['field_key'])) {
            $errors['field_key'] = 'Field key must start with a letter and contain only a–z, 0–9 and underscores.';
        } elseif ($this->fields->keyExists($serviceId, $data['field_key'], $id)) {
            $errors['field_key'] = 'This key is already used by another field in this service.';
        }

        if (!in_array($data['field_type'], self::FIELD_TYPES, true)) {
            $errors['field_type'] = 'Invalid field type.';
        }
        if (in_array($data['field_type'], ['select','radio','checkbox'], true) && empty($data['field_options'])) {
            $errors['field_options'] = 'Provide at least one option for this field type.';
        }

        if (!empty($data['validation_regex'])) {
            // Test the pattern in a harmless way
            $test = @preg_match('/' . str_replace('/', '\/', (string) $data['validation_regex']) . '/', '');
            if ($test === false) {
                $errors['validation_regex'] = 'The regex is not valid.';
            }
        }
        if ($data['max_length'] !== null && $data['max_length'] <= 0) {
            $errors['max_length'] = 'Max length must be a positive number.';
        }
        if (!in_array($data['status'], ['active','inactive'], true)) {
            $errors['status'] = 'Invalid status.';
        }
        return $errors;
    }
}