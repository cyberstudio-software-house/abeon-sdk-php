<?php

declare(strict_types=1);

namespace Abeon\SDK\Http;

use Abeon\SDK\Exceptions\InvalidQueryException;
use Illuminate\Http\Request;
use LogicException;

/**
 * Parses the list conventions of ADR-0004: `filter[field]=value`, `sort=-a,b`, `page`,
 * `per_page`. Fields are allowlisted per endpoint; anything else answers 422 instead of
 * being ignored, so a client never believes a filter it sent was applied.
 */
final class QueryParser
{
    public const MAX_PER_PAGE = 100;

    /**
     * @param  list<string>  $filters
     * @param  list<string>  $sorts
     *
     * @throws InvalidQueryException
     */
    public static function parse(
        Request $request,
        array $filters = [],
        array $sorts = [],
        string $defaultSort = '',
        int $maxPerPage = self::MAX_PER_PAGE,
    ): QuerySpec {
        $errors = [];

        $parsedFilters = self::filters($request->query('filter'), $filters, $errors);

        $rawSort = $request->query('sort');
        if ($rawSort !== null && ! is_string($rawSort)) {
            $errors['sort'][] = 'sort must be a comma-separated list of fields.';
            $rawSort = null;
        }

        $parsedSorts = is_string($rawSort) && $rawSort !== ''
            ? self::sorts($rawSort, $sorts, $errors)
            : self::defaultSorts($defaultSort, $sorts);

        $page = self::positiveInt($request->query('page'), 'page', $errors);
        $perPage = self::positiveInt($request->query('per_page'), 'per_page', $errors);

        if ($perPage !== null && $perPage > $maxPerPage) {
            $errors['per_page'][] = "per_page may not be greater than {$maxPerPage}.";
        }

        if ($errors !== []) {
            throw InvalidQueryException::withErrors($errors);
        }

        return new QuerySpec($parsedFilters, $parsedSorts, $page, $perPage);
    }

    /**
     * @param  list<string>  $allowed
     * @param  array<string, list<string>>  $errors
     * @return array<string, string>
     */
    private static function filters(mixed $raw, array $allowed, array &$errors): array
    {
        if ($raw === null) {
            return [];
        }

        if (! is_array($raw)) {
            $errors['filter'][] = 'filter must be given as filter[field]=value.';

            return [];
        }

        $parsed = [];
        foreach ($raw as $field => $value) {
            $field = (string) $field;

            if (! in_array($field, $allowed, true)) {
                $errors["filter.{$field}"][] = "Filtering by {$field} is not supported.";

                continue;
            }

            if (! is_scalar($value)) {
                $errors["filter.{$field}"][] = "filter[{$field}] must be a single value.";

                continue;
            }

            $parsed[$field] = (string) $value;
        }

        return $parsed;
    }

    /**
     * @param  list<string>  $allowed
     * @param  array<string, list<string>>  $errors
     * @return list<array{0: string, 1: 'asc'|'desc'}>
     */
    private static function sorts(string $raw, array $allowed, array &$errors): array
    {
        $parsed = [];
        $seen = [];

        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $direction = str_starts_with($part, '-') ? 'desc' : 'asc';
            $field = ltrim($part, '-');

            if (! in_array($field, $allowed, true)) {
                $errors['sort'][] = "Sorting by {$field} is not supported.";

                continue;
            }

            if (isset($seen[$field])) {
                continue;
            }

            $seen[$field] = true;
            $parsed[] = [$field, $direction];
        }

        return $parsed;
    }

    /**
     * @param  list<string>  $allowed
     * @return list<array{0: string, 1: 'asc'|'desc'}>
     */
    private static function defaultSorts(string $defaultSort, array $allowed): array
    {
        if ($defaultSort === '') {
            return [];
        }

        $errors = [];
        $parsed = self::sorts($defaultSort, $allowed, $errors);

        if ($errors !== []) {
            throw new LogicException("Default sort {$defaultSort} names a field that is not allowed");
        }

        return $parsed;
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private static function positiveInt(mixed $raw, string $name, array &$errors): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_string($raw) || ! ctype_digit($raw) || (int) $raw < 1) {
            $errors[$name][] = "{$name} must be a positive integer.";

            return null;
        }

        return (int) $raw;
    }
}
