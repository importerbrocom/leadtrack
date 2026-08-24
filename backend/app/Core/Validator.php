<?php

namespace App\Core;

/**
 * Tiny rule-based validator.
 *
 *   $data = Validator::make($request->body(), [
 *       'name'  => 'required|string|max:160',
 *       'phone' => 'required|phone',
 *       'email' => 'nullable|email',
 *       'status'=> 'required|in:new,contacted',
 *   ]);
 *
 * Returns only the validated keys, cast to sensible PHP types.
 * Throws ApiException(422) listing every failure.
 */
final class Validator
{
    public static function make(array $data, array $rules): array
    {
        $errors = [];
        $clean  = [];

        foreach ($rules as $field => $ruleString) {
            $ruleList = explode('|', $ruleString);
            $isNullable = in_array('nullable', $ruleList, true);
            $isRequired = in_array('required', $ruleList, true);
            $present    = array_key_exists($field, $data);
            $value      = $present ? $data[$field] : null;

            if (is_string($value)) {
                $value = trim($value);
            }

            $isEmpty = $value === null || $value === '';

            if ($isRequired && (!$present || $isEmpty)) {
                $errors[$field] = self::label($field) . ' is required';
                continue;
            }

            if (!$present) {
                continue; // nothing to validate, nothing to return
            }

            if ($isEmpty) {
                if ($isNullable || !$isRequired) {
                    $clean[$field] = null;
                    continue;
                }
            }

            // Decide up front whether min/max mean "length" or "value".
            // Without this, a numeric-looking string like a phone number
            // ("9876543210") would be compared against max:160 as a number.
            $declaredNumeric = array_intersect(['int', 'integer', 'numeric'], $ruleList) !== [];
            $declaredString  = array_intersect(['string', 'email', 'phone', 'date', 'datetime'], $ruleList) !== [];

            foreach ($ruleList as $rule) {
                if ($rule === 'required' || $rule === 'nullable' || $rule === '') {
                    continue;
                }

                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $error = null;

                switch ($name) {
                    case 'string':
                        if (!is_scalar($value)) {
                            $error = self::label($field) . ' must be text';
                        } else {
                            $value = (string) $value;
                        }
                        break;

                    case 'int':
                    case 'integer':
                        if (!is_numeric($value)) {
                            $error = self::label($field) . ' must be a number';
                        } else {
                            $value = (int) $value;
                        }
                        break;

                    case 'numeric':
                        if (!is_numeric($value)) {
                            $error = self::label($field) . ' must be a number';
                        } else {
                            $value = $value + 0;
                        }
                        break;

                    case 'bool':
                    case 'boolean':
                        $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                        if ($value === null) {
                            $error = self::label($field) . ' must be true or false';
                        } else {
                            $value = $value ? 1 : 0;
                        }
                        break;

                    case 'email':
                        if (!filter_var((string) $value, FILTER_VALIDATE_EMAIL)) {
                            $error = self::label($field) . ' must be a valid email address';
                        }
                        break;

                    case 'phone':
                        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
                        if (strlen($digits) < 10 || strlen($digits) > 15) {
                            $error = self::label($field) . ' must be a valid phone number';
                        }
                        break;

                    case 'min':
                        if (self::comparesAsNumber($value, $declaredNumeric, $declaredString)) {
                            if ((float) $value < (float) $arg) {
                                $error = self::label($field) . ' must be at least ' . $arg;
                            }
                        } elseif (mb_strlen((string) $value) < (int) $arg) {
                            $error = self::label($field) . ' must be at least ' . $arg . ' characters';
                        }
                        break;

                    case 'max':
                        if (self::comparesAsNumber($value, $declaredNumeric, $declaredString)) {
                            if ((float) $value > (float) $arg) {
                                $error = self::label($field) . ' must not exceed ' . $arg;
                            }
                        } elseif (mb_strlen((string) $value) > (int) $arg) {
                            $error = self::label($field) . ' must not exceed ' . $arg . ' characters';
                        }
                        break;

                    case 'in':
                        $allowed = explode(',', (string) $arg);
                        if (!in_array((string) $value, $allowed, true)) {
                            $error = self::label($field) . ' must be one of: ' . implode(', ', $allowed);
                        }
                        break;

                    case 'date':
                        $parsed = Helpers::toDate($value);
                        if ($parsed === null) {
                            $error = self::label($field) . ' must be a valid date';
                        } else {
                            $value = $parsed;
                        }
                        break;

                    case 'datetime':
                        $parsed = Helpers::toDateTime($value);
                        if ($parsed === null) {
                            $error = self::label($field) . ' must be a valid date and time';
                        } else {
                            $value = $parsed;
                        }
                        break;

                    case 'array':
                        if (!is_array($value)) {
                            $error = self::label($field) . ' must be a list';
                        }
                        break;

                    case 'exists':
                        // exists:table,column
                        [$table, $column] = array_pad(explode(',', (string) $arg), 2, 'id');
                        $found = Database::scalar(
                            sprintf('SELECT 1 FROM `%s` WHERE `%s` = ? LIMIT 1', $table, $column),
                            [$value]
                        );
                        if ($found === null) {
                            $error = self::label($field) . ' does not exist';
                        }
                        break;
                }

                if ($error !== null) {
                    $errors[$field] = $error;
                    continue 2;
                }
            }

            $clean[$field] = $value;
        }

        if ($errors !== []) {
            throw ApiException::validation($errors);
        }

        return $clean;
    }

    private static function label(string $field): string
    {
        return ucfirst(str_replace('_', ' ', $field));
    }

    /**
     * Should min/max compare the value as a number rather than a string length?
     * Driven by the declared rules first, and only then by the PHP type - a
     * digit string stays a string.
     */
    private static function comparesAsNumber($value, bool $declaredNumeric, bool $declaredString): bool
    {
        if ($declaredNumeric) {
            return true;
        }

        if ($declaredString) {
            return false;
        }

        return is_int($value) || is_float($value);
    }
}
