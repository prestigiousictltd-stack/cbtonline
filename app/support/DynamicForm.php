<?php
declare(strict_types=1);

namespace App\Support;

use App\Repositories\ServiceFieldRepository;

final class DynamicForm
{
    /** @param array<int,array> $fields rows from service_fields */
    public function __construct(private readonly array $fields) {}

    public static function forService(int $serviceId): self
    {
        return new self((new ServiceFieldRepository())->listActiveForService($serviceId));
    }

    /** @return array<int,array> */
    public function fields(): array { return $this->fields; }

    public function isEmpty(): bool { return empty($this->fields); }

    // ---------- Rendering ----------

    /**
     * Render the whole form body (no <form> tag).
     *
     * @param array<string,string> $old    previously submitted values keyed by field_key
     * @param array<string,string> $errors errors keyed by field_key
     */
    public function render(array $old = [], array $errors = []): string
    {
        if ($this->isEmpty()) {
            return '<div class="alert alert-warning mb-0">No fields are configured for this service.</div>';
        }

        $html = '';
        foreach ($this->fields as $f) {
            $html .= $this->renderField($f, $old, $errors);
        }
        return $html;
    }

    private function renderField(array $f, array $old, array $errors): string
    {
        $key      = (string) $f['field_key'];
        $id       = 'f_' . preg_replace('/[^a-z0-9_]/i', '_', $key);
        $label    = (string) $f['field_label'];
        $reqMark  = ((int) $f['is_required'] === 1) ? ' <span class="text-danger">*</span>' : '';
        $help     = !empty($f['help_text']) ? '<div class="form-text">' . e($f['help_text']) . '</div>' : '';
        $ph       = (string) ($f['placeholder'] ?? '');
        $value    = (string) ($old[$key] ?? '');
        $invalid  = isset($errors[$key]) ? ' is-invalid' : '';
        $errHtml  = isset($errors[$key])
            ? '<div class="invalid-feedback">' . e($errors[$key]) . '</div>'
            : '';
        $maxLen   = $f['max_length'] ? ' maxlength="' . (int) $f['max_length'] . '"' : '';
        $required = ((int) $f['is_required'] === 1) ? ' required' : '';
        $options  = $this->fieldOptions($f);

        $name = 'fields[' . $key . ']';

        switch ($f['field_type']) {
            case 'textarea':
                $input = sprintf(
                    '<textarea name="%s" id="%s" class="form-control%s" rows="3" placeholder="%s"%s%s>%s</textarea>',
                    e($name), e($id), $invalid, e($ph), $maxLen, $required, e($value)
                );
                break;

            case 'select':
                $opts = '<option value="">— select —</option>';
                foreach ($options as $opt) {
                    $sel  = ($value === $opt) ? ' selected' : '';
                    $opts .= '<option value="' . e($opt) . '"' . $sel . '>' . e($opt) . '</option>';
                }
                $input = sprintf(
                    '<select name="%s" id="%s" class="form-select%s"%s>%s</select>',
                    e($name), e($id), $invalid, $required, $opts
                );
                break;

            case 'radio':
                $input = '<div>';
                foreach ($options as $i => $opt) {
                    $checked = ($value === $opt) ? ' checked' : '';
                    $rid = $id . '_' . $i;
                    $input .= sprintf(
                        '<div class="form-check"><input class="form-check-input%s" type="radio" name="%s" id="%s" value="%s"%s%s><label class="form-check-label" for="%s">%s</label></div>',
                        $invalid, e($name), e($rid), e($opt), $checked, $required, e($rid), e($opt)
                    );
                }
                $input .= '</div>';
                break;

            case 'checkbox':
                $checkedVals = array_map('trim', explode(',', $value));
                $input = '<div>';
                foreach ($options as $i => $opt) {
                    $checked = in_array($opt, $checkedVals, true) ? ' checked' : '';
                    $cid = $id . '_' . $i;
                    $input .= sprintf(
                        '<div class="form-check"><input class="form-check-input%s" type="checkbox" name="%s[]" id="%s" value="%s"%s><label class="form-check-label" for="%s">%s</label></div>',
                        $invalid, e($name), e($cid), e($opt), $checked, e($cid), e($opt)
                    );
                }
                $input .= '</div>';
                break;

            case 'number':
                $input = sprintf(
                    '<input type="number" name="%s" id="%s" class="form-control%s" value="%s" placeholder="%s"%s%s%s>',
                    e($name), e($id), $invalid, e($value), e($ph), $maxLen, $required,
                    $f['validation_regex'] ? ' pattern="' . e($f['validation_regex']) . '"' : ''
                );
                break;

            case 'date':
                $input = sprintf(
                    '<input type="date" name="%s" id="%s" class="form-control%s" value="%s"%s%s>',
                    e($name), e($id), $invalid, e($value), $required, $maxLen
                );
                break;

            case 'file':
                // File-typed dynamic fields are not enabled yet (arrive with documents module)
                $input = '<input type="file" class="form-control disabled" disabled>'
                       . '<div class="form-text text-danger">File upload fields are not enabled yet.</div>';
                break;

            case 'email':
            case 'tel':
            case 'text':
            default:
                $input = sprintf(
                    '<input type="%s" name="%s" id="%s" class="form-control%s" value="%s" placeholder="%s"%s%s>',
                    e($f['field_type']), e($name), e($id), $invalid, e($value), e($ph), $maxLen, $required
                );
                break;
        }

        return sprintf(
            '<div class="mb-3"><label class="form-label" for="%s">%s%s</label>%s%s%s</div>',
            e($id), e($label), $reqMark, $input, $errHtml, $help
        );
    }

    // ---------- Validation ----------

    /**
     * @param  array<string,mixed> $input The raw $_POST['fields'] array.
     * @return array{ok:bool,errors:array<string,string>,values:array<int,array>}
     */
    public function validate(array $input): array
    {
        $errors = [];
        $values = [];

        foreach ($this->fields as $f) {
            $key     = (string) $f['field_key'];
            $type    = (string) $f['field_type'];
            $raw     = $input[$key] ?? '';
            $value   = '';

            if ($type === 'checkbox') {
                $arr   = is_array($raw) ? array_filter(array_map('trim', $raw), fn($v) => $v !== '') : [];
                $value = implode(', ', $arr);
            } else {
                $value = trim((string) (is_array($raw) ? '' : $raw));
            }

            $isRequired = ((int) $f['is_required'] === 1);

            if ($isRequired && $value === '') {
                $errors[$key] = 'This field is required.';
            } elseif ($value !== '') {
                $maxLen = $f['max_length'] ? (int) $f['max_length'] : null;
                if ($maxLen && mb_strlen($value) > $maxLen) {
                    $errors[$key] = 'Must not exceed ' . $maxLen . ' characters.';
                }
                if ($type === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$key] = 'Please enter a valid email address.';
                }
                if ($type === 'number' && !is_numeric($value)) {
                    $errors[$key] = 'Please enter a numeric value.';
                }
                if (($type === 'select' || $type === 'radio')) {
                    $opts = $this->fieldOptions($f);
                    if (!in_array($value, $opts, true)) {
                        $errors[$key] = 'Please choose a valid option.';
                    }
                }
                if (!empty($f['validation_regex'])) {
                    $rx = (string) $f['validation_regex'];
                    // Guard against malformed regex in DB from breaking validation
                    if (@preg_match('/' . str_replace('/', '\/', $rx) . '/', '') !== false
                        && !preg_match('/' . str_replace('/', '\/', $rx) . '/', $value)) {
                        $errors[$key] = 'The format is invalid.';
                    }
                }
            }

            $values[] = [
                'field_id'    => (int) $f['id'],
                'field_key'   => $key,
                'field_label' => (string) $f['field_label'],
                'value_text'  => $value,
            ];
        }

        return ['ok' => empty($errors), 'errors' => $errors, 'values' => $values];
    }

    /** @return string[] */
    private function fieldOptions(array $f): array
    {
        $raw = $f['field_options'] ?? null;
        if (!$raw) return [];
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
        }
        if (is_array($raw)) return array_values(array_map('strval', $raw));
        return [];
    }
}