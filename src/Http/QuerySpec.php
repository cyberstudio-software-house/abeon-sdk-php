<?php

declare(strict_types=1);

namespace Abeon\SDK\Http;

use Illuminate\Contracts\Database\Query\Builder;
use LogicException;

/**
 * A parsed list query: equality filters, ordered sorts and an optional page (ADR-0004).
 */
final readonly class QuerySpec
{
    /**
     * @param  array<string, string>  $filters
     * @param  list<array{0: string, 1: 'asc'|'desc'}>  $sorts
     */
    public function __construct(
        public array $filters = [],
        public array $sorts = [],
        public ?int $page = null,
        public ?int $perPage = null,
    ) {
    }

    /**
     * True only when the caller asked for a page. An endpoint that returned full lists
     * before keeps doing so for callers that do not ask.
     */
    public function isPaginated(): bool
    {
        return $this->page !== null || $this->perPage !== null;
    }

    public function pageOrFirst(): int
    {
        return $this->page ?? 1;
    }

    public function perPageOr(int $default): int
    {
        return $this->perPage ?? $default;
    }

    /**
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @param  array<string, string>  $columns  public field name => column
     * @return TBuilder
     */
    public function apply(Builder $query, array $columns): Builder
    {
        foreach ($this->filters as $field => $value) {
            $query->where(self::column($columns, $field), '=', $value);
        }

        foreach ($this->sorts as [$field, $direction]) {
            $query->orderBy(self::column($columns, $field), $direction);
        }

        return $query;
    }

    /**
     * @param  array<string, string>  $columns
     */
    private static function column(array $columns, string $field): string
    {
        if (! isset($columns[$field])) {
            throw new LogicException("No column mapped for query field {$field}");
        }

        return $columns[$field];
    }
}
