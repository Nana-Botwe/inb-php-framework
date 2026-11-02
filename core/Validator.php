<?php

declare(strict_types=1);

namespace Core;

use Core\Http\UploadedFile;
use InvalidArgumentException;

/**
 * Rule-based input validation (Laravel-style).
 *
 *   $v = Validator::make($data, [
 *       'name'     => 'required|string|max:100|no_html',
 *       'email'    => 'required|email|unique:users,email',
 *       'password' => 'required|strong_password|confirmed',
 *       'avatar'   => 'nullable|image|max:2048',            // KB
 *       'code'     => ['required', 'regex:/^[A-Z]{3}\d{2}$/'],
 *   ]);
 *
 * Presence:   required, required_if:field,value, required_with:field, required_unless:field,value, nullable, sometimes, prohibited
 * Types:      string, integer, numeric, boolean, array, json, date, email, url, ip, uuid, file, image
 * Text:       alpha, alpha_num, alpha_dash, lowercase, uppercase, starts_with:a,b, ends_with:a,b, regex:/x/, not_regex:/x/, no_html
 * Size:       min:n, max:n, between:a,b, size:n, digits:n, digits_between:a,b   (files: kilobytes)
 * Compare:    in:a,b, not_in:a,b, same:field, different:field, confirmed, before:date, after:date, gt:field, lt:field
 * Database:   unique:table,column[,ignoreId[,idColumn]], exists:table,column
 * Security:   strong_password (8+ chars, upper, lower, number, symbol), current_password, mimes:jpg,png, accepted
 */
final class Validator
{
    /** @var array<string, string[]> */
    private array $errors = [];

    /** @var array<string, mixed> */
    private array $validated = [];

    private bool $ran = false;

    /**
     * @param array<string, mixed> $data
     * @param array<string, string|array<int, string>> $rules
     * @param array<string, string> $messages Custom messages: ['email.required' => '...'] or ['required' => '...']
     * @param array<string, string> $attributes Friendly field names: ['dob' => 'date of birth']
     */
    public function __construct(
        private readonly array $data,
        private readonly array $rules,
        private readonly array $messages = [],
        private readonly array $attributes = [],
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string|array<int, string>> $rules
     * @param array<string, string> $messages
     * @param array<string, string> $attributes
     */
    public static function make(array $data, array $rules, array $messages = [], array $attributes = []): self
    {
        return new self($data, $rules, $messages, $attributes);
    }

    public function passes(): bool
    {
        $this->run();

        return $this->errors === [];
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    /** @return array<string, string[]> field => list of messages */
    public function errors(): array
    {
        $this->run();

        return $this->errors;
    }

    /** @return array<string, mixed> Only the fields that have rules (safe to pass to Model::create). */
    public function validated(): array
    {
        $this->run();

        return $this->validated;
    }

    /** @return array<string, mixed> Throws ValidationException on failure. */
    public function validate(): array
    {
        if ($this->fails()) {
            throw new ValidationException($this->errors);
        }

        return $this->validated;
    }

    private function run(): void
    {
        if ($this->ran) {
            return;
        }
        $this->ran = true;

        foreach ($this->rules as $field => $rules) {
            $rules = is_array($rules) ? $rules : explode('|', $rules);
            $value = $this->value($field);
            $present = $this->present($field);

            if (in_array('sometimes', $rules, true) && !$present) {
                continue;
            }

            $required = $this->isRequired($rules);

            // Optional and empty: nothing else to check.
            if (!$required && $this->isEmpty($value)) {
                if ($present) {
                    $this->validated[$field] = $value;
                }
                continue;
            }

            $type = $this->typeOf($rules, $value);

            foreach ($rules as $rule) {
                [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);

                if (in_array($name, ['nullable', 'sometimes', 'bail'], true)) {
                    continue;
                }

                if (!$this->check($name, $field, $value, $param, $type)) {
                    $this->addError($field, $name, $param, $type);

                    if (str_starts_with($name, 'required') || in_array('bail', $rules, true)) {
                        break;
                    }
                }
            }

            if (!isset($this->errors[$field]) && $present) {
                $this->validated[$field] = $value;
            }
        }
    }

    /** @param string[] $rules */
    private function isRequired(array $rules): bool
    {
        foreach ($rules as $rule) {
            [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);
            $args = explode(',', (string) $param);
            $required = match ($name) {
                'required', 'accepted' => true,
                'required_if' => (string) ($this->value($args[0]) ?? '') === ($args[1] ?? ''),
                'required_unless' => (string) ($this->value($args[0]) ?? '') !== ($args[1] ?? ''),
                'required_with' => !$this->isEmpty($this->value($args[0])),
                default => false,
            };
            if ($required) {
                return true;
            }
        }

        return false;
    }

    private function check(string $rule, string $field, mixed $value, ?string $param, string $type): bool
    {
        $string = is_scalar($value) ? (string) $value : '';
        $args = $param === null ? [] : str_getcsv($param);

        return match ($rule) {
            'required', 'required_if', 'required_unless', 'required_with' => !$this->isEmpty($value) || !$this->isRequired([$rule . ($param !== null ? ':' . $param : '')]),
            'prohibited' => $this->isEmpty($value),
            'string' => is_string($value),
            'integer' => filter_var($value, FILTER_VALIDATE_INT) !== false,
            'numeric' => is_numeric($value),
            'boolean' => in_array($value, [true, false, 0, 1, '0', '1', 'true', 'false', 'on', 'off'], true),
            'array' => is_array($value),
            'json' => is_string($value) && json_validate_compat($value),
            'date' => is_string($value) && strtotime($value) !== false,
            'email' => filter_var($string, FILTER_VALIDATE_EMAIL) !== false,
            'url' => filter_var($string, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $string) === 1,
            'ip' => filter_var($string, FILTER_VALIDATE_IP) !== false,
            'uuid' => (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $string),
            'file' => $value instanceof UploadedFile && $value->isValid(),
            'image' => $value instanceof UploadedFile && $value->isValid() && $value->isImage(),
            'mimes' => $value instanceof UploadedFile && in_array($value->extension(), $args, true),
            'accepted' => in_array($value, ['yes', 'on', '1', 1, true, 'true'], true),
            'alpha' => (bool) preg_match('/^[\pL\pM]+$/u', $string),
            'alpha_num' => (bool) preg_match('/^[\pL\pM\pN]+$/u', $string),
            'alpha_dash' => (bool) preg_match('/^[\pL\pM\pN_-]+$/u', $string),
            'lowercase' => $string === mb_strtolower($string),
            'uppercase' => $string === mb_strtoupper($string),
            'starts_with' => Support\Str::startsWith($string, $args),
            'ends_with' => Support\Str::endsWith($string, $args),
            'regex' => (bool) preg_match((string) $param, $string),
            'not_regex' => !preg_match((string) $param, $string),
            'no_html' => $string === strip_tags($string) && !preg_match('/[<>]/', $string),
            'min' => $this->size($value, $type) >= (float) $param,
            'max' => $this->size($value, $type) <= (float) $param,
            'size' => $this->size($value, $type) == (float) $param,
            'between' => $this->size($value, $type) >= (float) ($args[0] ?? 0) && $this->size($value, $type) <= (float) ($args[1] ?? 0),
            'digits' => ctype_digit($string) && strlen($string) === (int) $param,
            'digits_between' => ctype_digit($string) && strlen($string) >= (int) ($args[0] ?? 0) && strlen($string) <= (int) ($args[1] ?? 0),
            'in' => in_array($string, $args, true),
            'not_in' => !in_array($string, $args, true),
            'same' => $value === $this->value((string) $param),
            'different' => $value !== $this->value((string) $param),
            'confirmed' => $value === $this->value($field . '_confirmation'),
            'gt' => is_numeric($value) && $value > $this->value((string) $param),
            'lt' => is_numeric($value) && $value < $this->value((string) $param),
            'before' => strtotime($string) !== false && strtotime($string) < $this->dateParam((string) $param),
            'after' => strtotime($string) !== false && strtotime($string) > $this->dateParam((string) $param),
            'strong_password' => is_string($value) && mb_strlen($value) >= 8
                && preg_match('/[a-z]/', $value) && preg_match('/[A-Z]/', $value)
                && preg_match('/\d/', $value) && preg_match('/[^A-Za-z0-9]/', $value),
            'current_password' => is_string($value) && Auth::check() && Hash::check($value, (string) Auth::user()?->password),
            'unique' => $this->unique((string) $param, $field, $value),
            'exists' => $this->exists((string) $param, $field, $value),
            default => throw new InvalidArgumentException("Unknown validation rule [$rule]."),
        };
    }

    private function value(string $field): mixed
    {
        return Support\Arr::get($this->data, $field);
    }

    private function present(string $field): bool
    {
        return Support\Arr::has($this->data, $field);
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === [] || (is_string($value) && trim($value) === '')
            || ($value instanceof UploadedFile && !$value->isValid());
    }

    /** @param string[] $rules */
    private function typeOf(array $rules, mixed $value): string
    {
        return match (true) {
            $value instanceof UploadedFile => 'file',
            is_array($value) || in_array('array', $rules, true) => 'array',
            (bool) array_intersect(['numeric', 'integer'], $rules) => 'numeric',
            default => 'string',
        };
    }

    private function size(mixed $value, string $type): float
    {
        return match ($type) {
            'file' => $value instanceof UploadedFile ? $value->size() / 1024 : 0,
            'array' => is_array($value) ? count($value) : 0,
            'numeric' => is_numeric($value) ? (float) $value : 0,
            default => mb_strlen(is_scalar($value) ? (string) $value : ''),
        };
    }

    private function dateParam(string $param): int
    {
        $other = $this->value($param);

        return (int) strtotime(is_string($other) && $other !== '' ? $other : $param);
    }

    private function unique(string $param, string $field, mixed $value): bool
    {
        [$table, $column, $ignoreId, $idColumn] = array_pad(explode(',', $param), 4, null);

        $query = DB::table((string) $table)->where($column ?: $field, $value);
        if ($ignoreId !== null && $ignoreId !== '' && strtoupper($ignoreId) !== 'NULL') {
            $query->where($idColumn ?: 'id', '!=', $ignoreId);
        }

        return !$query->exists();
    }

    private function exists(string $param, string $field, mixed $value): bool
    {
        [$table, $column] = array_pad(explode(',', $param), 2, null);

        return DB::table((string) $table)->where($column ?: $field, $value)->exists();
    }

    private function addError(string $field, string $rule, ?string $param, string $type): void
    {
        $key = in_array($rule, ['min', 'max', 'size', 'between'], true) ? "$rule.$type" : $rule;

        $template = $this->messages["$field.$rule"]
            ?? $this->messages[$rule]
            ?? (Lang::has("validation.$key") ? Lang::get("validation.$key") : null)
            ?? (Lang::has("validation.$rule") ? Lang::get("validation.$rule") : null)
            ?? 'The :attribute field is invalid.';

        $args = $param === null ? [] : str_getcsv($param);
        $other = in_array($rule, ['same', 'different', 'gt', 'lt', 'required_if', 'required_with', 'required_unless'], true)
            ? $this->label($args[0] ?? '')
            : '';

        $this->errors[$field][] = strtr($template, [
            ':attribute' => $this->label($field),
            ':other' => $other,
            ':value' => $args[1] ?? '',
            ':values' => implode(', ', $args),
            ':min' => $args[0] ?? '',
            ':max' => $args[1] ?? ($args[0] ?? ''),
            ':size' => $args[0] ?? '',
            ':digits' => $args[0] ?? '',
            ':date' => $args[0] ?? '',
        ]);
    }

    private function label(string $field): string
    {
        return $this->attributes[$field]
            ?? (Lang::has("validation.attributes.$field") ? Lang::get("validation.attributes.$field") : null)
            ?? str_replace(['_', '-', '.'], ' ', $field);
    }
}

/** json_validate() exists only on PHP 8.3+. */
function json_validate_compat(string $value): bool
{
    if (function_exists('json_validate')) {
        return json_validate($value);
    }
    json_decode($value);

    return json_last_error() === JSON_ERROR_NONE;
}
