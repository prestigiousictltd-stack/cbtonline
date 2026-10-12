<?php
declare(strict_types=1);

namespace App\Core;

final class Validator
{
    private array $data;
    private array $errors = [];

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function required(string $field, string $label): self
    {
        $v = $this->data[$field] ?? null;
        if (is_null($v) || (is_string($v) && trim($v) === '')) {
            $this->errors[$field] = "$label is required.";
        }
        return $this;
    }

    public function email(string $field, string $label = 'Email'): self
    {
        $v = trim((string) ($this->data[$field] ?? ''));
        if ($v === '') {
            return $this; // required() handles emptiness
        }
        if (!filter_var($v, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] = "$label must be a valid email address.";
        }
        return $this;
    }

    public function min(string $field, int $length, string $label): self
    {
        $v = (string) ($this->data[$field] ?? '');
        if ($v !== '' && mb_strlen($v) < $length) {
            $this->errors[$field] = "$label must be at least $length characters.";
        }
        return $this;
    }

    public function max(string $field, int $length, string $label): self
    {
        $v = (string) ($this->data[$field] ?? '');
        if ($v !== '' && mb_strlen($v) > $length) {
            $this->errors[$field] = "$label must not exceed $length characters.";
        }
        return $this;
    }

    public function regex(string $field, string $pattern, string $label, string $message): self
    {
        $v = (string) ($this->data[$field] ?? '');
        if ($v !== '' && !preg_match($pattern, $v)) {
            $this->errors[$field] = $message ?: "$label is not valid.";
        }
        return $this;
    }

    public function matches(string $field, string $otherField, string $label): self
    {
        $a = (string) ($this->data[$field] ?? '');
        $b = (string) ($this->data[$otherField] ?? '');
        if ($a !== $b) {
            $this->errors[$field] = "$label does not match.";
        }
        return $this;
    }

    public function in(string $field, array $allowed, string $label): self
    {
        $v = (string) ($this->data[$field] ?? '');
        if ($v !== '' && !in_array($v, $allowed, true)) {
            $this->errors[$field] = "$label is not valid.";
        }
        return $this;
    }

    public function fails(): bool
    {
        return !empty($this->errors);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): ?string
    {
        return $this->errors ? array_values($this->errors)[0] : null;
    }
}