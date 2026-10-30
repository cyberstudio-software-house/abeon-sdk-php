<?php

declare(strict_types=1);

namespace Abeon\SDK\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Records a model's creation, changes and deletion in the audit log (ADR-0042), with the fields
 * that changed. A model lists in `$auditExcept` what is not worth an entry (drafts saved every few
 * seconds, counters); a change to nothing else writes nothing.
 *
 * @mixin Model
 */
trait Audited
{
    public static function bootAudited(): void
    {
        static::created(function (Model $model): void {
            /** @var Model&self $model */
            $model->audit('created', array_map(fn (mixed $value) => [null, $value], $model->auditable($model->getAttributes())));
        });

        static::updated(function (Model $model): void {
            /** @var Model&self $model */
            $changes = [];
            foreach ($model->auditable($model->getChanges()) as $field => $after) {
                $changes[$field] = [$model->getOriginal($field), $after];
            }

            if ($changes !== []) {
                $model->audit('updated', $changes);
            }
        });

        static::deleted(function (Model $model): void {
            /** @var Model&self $model */
            $model->audit('deleted', []);
        });
    }

    /**
     * The name the log knows this model by: `page_translation.updated`.
     */
    public function auditType(): string
    {
        return Str::snake(class_basename($this));
    }

    public function auditLabel(): ?string
    {
        foreach (['title', 'name', 'label', 'slug'] as $attribute) {
            $value = $this->getAttribute($attribute);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function auditContext(): array
    {
        return [];
    }

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     */
    protected function audit(string $event, array $changes): void
    {
        app(AuditLog::class)->record($this->auditType().'.'.$event, $this->auditType(), $this->getKey(), $this->auditLabel(), $changes, $this->auditContext());
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function auditable(array $attributes): array
    {
        $except = [...($this->auditExcept ?? []), 'id', 'created_at', 'updated_at'];
        $values = array_diff_key($attributes, array_flip($except));

        foreach ($values as $field => $value) {
            $values[$field] = $this->hasCast($field) && ! in_array($this->getCasts()[$field], ['encrypted', 'encrypted:array'], true) ? $this->getAttribute($field) : $value;
        }

        return $values;
    }
}
