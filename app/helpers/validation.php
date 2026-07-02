<?php
/**
 * Validación de entrada (AGENTS.md §Seguridad 3): tipos, enums contra constantes,
 * longitudes, fechas parseables. Acumula errores por campo para responder 422 con
 * mensajes claros en español. La validación crítica vive en backend (§Validaciones).
 */

declare(strict_types=1);

final class Validator
{
    /** @var array<string, string> */
    private array $errors = [];

    public function __construct(private array $data)
    {
    }

    /** Valor crudo (string recortado) o null si no vino. */
    public function value(string $field): ?string
    {
        if (!array_key_exists($field, $this->data) || $this->data[$field] === null) {
            return null;
        }
        return is_string($this->data[$field]) ? trim($this->data[$field]) : (string) $this->data[$field];
    }

    public function required(string $field, string $label): self
    {
        $v = $this->value($field);
        if ($v === null || $v === '') {
            $this->errors[$field] ??= "{$label} es obligatorio.";
        }
        return $this;
    }

    public function maxLen(string $field, int $max, string $label): self
    {
        $v = $this->value($field);
        if ($v !== null && mb_strlen($v) > $max) {
            $this->errors[$field] ??= "{$label} no puede exceder {$max} caracteres.";
        }
        return $this;
    }

    /** El valor debe pertenecer al conjunto permitido (constantes de enum). */
    public function inEnum(string $field, array $allowed, string $label): self
    {
        $v = $this->value($field);
        if ($v !== null && $v !== '' && !in_array($v, $allowed, true)) {
            $this->errors[$field] ??= "{$label} no es un valor válido.";
        }
        return $this;
    }

    /** Entero positivo (para FKs). */
    public function positiveInt(string $field, string $label): self
    {
        $v = $this->value($field);
        if ($v !== null && $v !== '' && (!ctype_digit($v) || (int) $v < 1)) {
            $this->errors[$field] ??= "{$label} no es válido.";
        }
        return $this;
    }

    /** Fecha parseable con el formato dado (default Y-m-d). */
    public function date(string $field, string $label, string $format = 'Y-m-d'): self
    {
        $v = $this->value($field);
        if ($v !== null && $v !== '') {
            $d = DateTime::createFromFormat($format, $v);
            if (!$d || $d->format($format) !== $v) {
                $this->errors[$field] ??= "{$label} no es una fecha válida.";
            }
        }
        return $this;
    }

    public function addError(string $field, string $message): self
    {
        $this->errors[$field] ??= $message;
        return $this;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** Corta con 422 si hay errores. */
    public function validateOrFail(): void
    {
        if ($this->fails()) {
            json_unprocessable($this->errors);
        }
    }
}
