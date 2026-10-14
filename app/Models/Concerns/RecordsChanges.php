<?php

namespace App\Models\Concerns;

use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Writes every create, change, delete and restore of the model to the audit log: who, when, the old and the new
 * values (SPEC data protection). The Owner reads it on the record's page and in Settings → Change log.
 *
 * Models name their log type with AUDIT_TYPE (e.g. "customer" gives "customer.updated").
 *
 * @mixin Model
 */
trait RecordsChanges
{
    /** Columns never written to the log: bookkeeping, derived and secret values. */
    private static array $auditIgnored = ['id', 'company_id', 'created_at', 'updated_at', 'deleted_at', 'display_name', 'remember_token', 'password', 'public_token', 'access_token', 'refresh_token'];

    public static function bootRecordsChanges(): void
    {
        static::created(function (Model $model) {
            $model->writeChange('created', ['new' => $model->auditValues($model->getAttributes())]);
        });

        static::updated(function (Model $model) {
            $changed = $model->auditValues($model->getChanges());

            if ($changed === []) {
                return;
            }

            $old = [];
            foreach (array_keys($changed) as $key) {
                $old[$key] = $model->auditValue($model->getRawOriginal($key));
            }

            $model->writeChange('updated', ['old' => $old, 'new' => $changed]);
        });

        static::deleted(function (Model $model) {
            $forced = in_array(SoftDeletes::class, class_uses_recursive($model), true) && $model->isForceDeleting();
            $model->writeChange($forced ? 'force_deleted' : 'deleted', ['label' => $model->auditLabel()]);
        });

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::restored(function (Model $model) {
                $model->writeChange('restored', ['label' => $model->auditLabel()]);
            });
        }
    }

    /** Short name of the record for the log ("#1042", "Maria Lopez"). */
    public function auditLabel(): string
    {
        foreach (['display_name', 'number', 'description', 'name'] as $key) {
            $value = $this->getAttribute($key);

            if (filled($value)) {
                return $key === 'number' && is_numeric($value) ? "#{$value}" : (string) $value;
            }
        }

        return '#'.$this->getKey();
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function writeChange(string $event, array $changes): void
    {
        app(AuditLogger::class)->record(
            static::AUDIT_TYPE.'.'.$event,
            $this,
            $changes + ($event === 'created' || $event === 'updated' ? ['label' => $this->auditLabel()] : []),
            $this->getAttribute('company_id'),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function auditValues(array $attributes): array
    {
        $hidden = array_merge(self::$auditIgnored, $this->getHidden(), property_exists($this, 'auditIgnore') ? $this->auditIgnore : []);
        $values = [];

        foreach ($attributes as $key => $value) {
            if (! in_array($key, $hidden, true)) {
                $values[$key] = $this->auditValue($value);
            }
        }

        return $values;
    }

    private function auditValue(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_string($value) && mb_strlen($value) > 2000) {
            return mb_substr($value, 0, 2000).'…';
        }

        return $value;
    }
}
