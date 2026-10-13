<?php

namespace CarlJanzell\FilamentInsights\Support;

use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Contracts\HasLabel;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

use function Livewire\trigger;

/**
 * Turns one widget's config into numbers.
 *
 * Every query starts from the resource's own list-page table query, so a chart
 * counts exactly the records its viewer could see in that table: the resource's
 * `getEloquentQuery()` and the table's `modifyQueryUsing()` scopes both apply.
 * Column names come from the widget config and end up in raw SQL, so each one is
 * checked against the model's real columns before it is used.
 */
class InsightQuery
{
    public const AGGREGATES = [
        'count' => 'Count of records',
        'sum' => 'Sum',
        'avg' => 'Average',
        'min' => 'Minimum',
        'max' => 'Maximum',
    ];

    /** Bucket => [key format, display format]. Key formats are also translated to SQL. */
    public const BUCKETS = [
        'day' => ['Y-m-d', 'M j'],
        'month' => ['Y-m', 'M Y'],
        'year' => ['Y', 'Y'],
    ];

    protected const NUMERIC = ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'numeric', 'float', 'double', 'double precision', 'real', 'int2', 'int4', 'int8', 'float4', 'float8', 'money'];

    protected const DATES = ['date', 'datetime', 'timestamp', 'timestamptz'];

    protected const UNGROUPABLE = ['text', 'tinytext', 'mediumtext', 'longtext', 'json', 'jsonb', 'blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary', 'bytea'];

    /** @var array<class-string<Model>, array{columns: array<string, array{type: string, boolean: bool}>, relations: array<string, string>}> */
    protected static array $schemas = [];

    protected Model $model;

    /**
     * @param  class-string<resource>  $resource
     * @param  list<string>  $excluded  columns never offered or accepted
     */
    public function __construct(protected string $resource, protected array $excluded = [])
    {
        $this->model = app($resource::getModel());
    }

    /**
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable} `$period` 1 is the window before the current one.
     */
    public static function window(array $config, int $period = 0): array
    {
        $days = (int) ($config['range'] ?? 0);

        if ($days < 1 || blank($config['date_column'] ?? null)) {
            return [null, null];
        }

        $until = CarbonImmutable::now()->endOfDay()->subDays($days * $period);

        return [$until->subDays($days - 1)->startOfDay(), $until];
    }

    /**
     * @return list<string> every column a widget config reads
     */
    public static function columnsIn(array $config): array
    {
        $group = (string) ($config['group_by'] ?? '');

        return array_values(array_filter([
            $config['column'] ?? null,
            $config['date_column'] ?? null,
            str_starts_with($group, '@') ? null : $group,
        ]));
    }

    public static function describe(array $config): string
    {
        return static::window($config)[0] ? "Last {$config['range']} days" : 'All time';
    }

    /**
     * Select options for the widget form: column => label.
     *
     * @param  'numeric'|'date'|'group'  $kind
     * @return array<string, string>
     */
    public function options(string $kind): array
    {
        return collect($this->schema()['columns'])
            ->keys()
            ->filter(fn (string $column): bool => $this->is($kind, $column))
            ->mapWithKeys(fn (string $column): array => [$column => Str::headline($this->schema()['relations'][$column] ?? $column)])
            ->all();
    }

    public function value(array $config, int $period = 0): float
    {
        [$from, $until] = static::window($config, $period);
        $query = $this->constrain($this->query(), $config, $from, $until);

        return (float) $query->toBase()
            ->select([])
            ->selectRaw($this->aggregate($config, $query).' as aggregate')
            ->value('aggregate');
    }

    /**
     * @return array{0: list<string>, 1: list<float>} labels and values, aligned
     */
    public function series(array $config): array
    {
        [$from, $until] = static::window($config);
        $query = $this->constrain($this->query(), $config, $from, $until);
        $aggregate = $this->aggregate($config, $query);
        $base = $query->toBase()->select([]);
        $group = (string) ($config['group_by'] ?? '');

        if (str_starts_with($group, '@') && isset(self::BUCKETS[$unit = substr($group, 1)])) {
            $bucket = $this->bucket($this->column($config['date_column'] ?? null, 'date'), $unit, $base);

            $rows = $base->selectRaw("{$bucket} as bucket, {$aggregate} as aggregate")
                ->groupByRaw($bucket)
                ->pluck('aggregate', 'bucket');

            return $this->timeline($rows, $unit, $from, $until);
        }

        $column = $this->column($group, 'group');

        $rows = $base->selectRaw($base->getGrammar()->wrap($column)." as bucket, {$aggregate} as aggregate")
            ->groupBy($column)
            ->orderByRaw("{$aggregate} desc")
            ->limit(min(max((int) ($config['limit'] ?? 10), 1), 50))
            ->pluck('aggregate', 'bucket');

        return [$this->labels($group, $rows->keys()), $rows->values()->map(fn (mixed $value): float => (float) $value)->all()];
    }

    /**
     * The records this user may see, scoped the way the resource's list page scopes them.
     */
    public function query(): Builder
    {
        $page = ($this->resource::getPages()['index'] ?? null)?->getPage();

        if ($page !== null && is_subclass_of($page, HasTable::class)) {
            // Filament's own InteractsWithPageTable does the same: boot the list page
            // off-screen to borrow its table. The tab is cleared so a default tab
            // doesn't silently narrow the numbers; filters are never applied.
            $livewire = app('livewire')->new($page);
            trigger('mount', $livewire, [], null, null, []);

            if (property_exists($livewire, 'activeTab')) {
                $livewire->activeTab = null;
            }

            $livewire->bootedInteractsWithTable();
            $query = $livewire->getTable()->getQuery();
        }

        return ($query ?? $this->resource::getEloquentQuery())->reorder()->setEagerLoads([]);
    }

    protected function constrain(Builder $query, array $config, ?CarbonImmutable $from, ?CarbonImmutable $until): Builder
    {
        if ($from !== null) {
            $query->whereBetween($this->column($config['date_column'] ?? null, 'date'), [$from, $until]);
        }

        return $query;
    }

    protected function aggregate(array $config, Builder $query): string
    {
        $function = $config['aggregate'] ?? 'count';

        if (! in_array($function, ['sum', 'avg', 'min', 'max'], true)) {
            return 'count(*)';
        }

        return $function.'('.$query->getQuery()->getGrammar()->wrap($this->column($config['column'] ?? null, 'numeric')).')';
    }

    // ponytail: mysql/mariadb, pgsql and sqlite only; add a sqlsrv arm (FORMAT()) if anyone needs it.
    protected function bucket(string $column, string $unit, QueryBuilder $query): string
    {
        $column = $query->getGrammar()->wrap($column);
        $format = self::BUCKETS[$unit][0];

        return match ($query->getConnection()->getDriverName()) {
            'pgsql' => "to_char({$column}, '".strtr($format, ['Y' => 'YYYY', 'm' => 'MM', 'd' => 'DD'])."')",
            'sqlite' => "strftime('".strtr($format, ['Y' => '%Y', 'm' => '%m', 'd' => '%d'])."', {$column})",
            default => "date_format({$column}, '".strtr($format, ['Y' => '%Y', 'm' => '%m', 'd' => '%d'])."')",
        };
    }

    /**
     * Every bucket in the window, zero-filled, so a quiet day is a dip and not a gap.
     *
     * @param  Collection<string, mixed>  $rows
     * @return array{0: list<string>, 1: list<float>}
     */
    protected function timeline(Collection $rows, string $unit, ?CarbonImmutable $from, ?CarbonImmutable $until): array
    {
        if ($from === null && $rows->isEmpty()) {
            return [[], []];
        }

        [$key, $display] = self::BUCKETS[$unit];
        // `!` zeroes the unparsed fields; without it "2026-02" on the 31st overflows into March.
        $parse = fn (string $bucket): CarbonImmutable => CarbonImmutable::createFromFormat("!{$key}", $bucket);

        $from ??= $parse($rows->keys()->min());
        $until ??= $parse($rows->keys()->max());
        $labels = $values = [];

        foreach (CarbonPeriod::create($from->startOf($unit), "1 {$unit}", $until) as $date) {
            $labels[] = $date->format($display);
            $values[] = (float) ($rows[$date->format($key)] ?? 0);
        }

        return [$labels, $values];
    }

    /**
     * @param  Collection<int, mixed>  $keys
     * @return list<string>
     */
    protected function labels(string $column, Collection $keys): array
    {
        $cast = $this->model->getCasts()[$column] ?? null;

        if ($relation = $this->schema()['relations'][$column] ?? null) {
            /** @var BelongsTo $belongsTo */
            $belongsTo = $this->model->{$relation}();
            $ownerKey = $belongsTo->getOwnerKeyName();
            $resource = Filament::getModelResource($belongsTo->getRelated());

            $titles = $belongsTo->getRelated()->newQuery()
                ->whereIn($ownerKey, $keys->filter(fn (mixed $key): bool => filled($key))->all())
                ->get()
                ->mapWithKeys(fn (Model $record): array => [
                    $record->getAttribute($ownerKey) => $resource && $resource::hasRecordTitle() ? $resource::getRecordTitle($record) : $record->getKey(),
                ]);
        }

        return $keys->map(fn (mixed $key): string => (string) match (true) {
            blank($key) => '(none)',
            isset($titles) => $titles[$key] ?? $key,
            is_string($cast) && is_subclass_of($cast, BackedEnum::class) => ($case = $cast::tryFrom($key)) instanceof HasLabel ? $case->getLabel() : ($case->name ?? $key),
            $this->schema()['columns'][$column]['boolean'] => $key ? 'Yes' : 'No',
            default => $key,
        })->values()->all();
    }

    /**
     * @param  'numeric'|'date'|'group'  $kind
     */
    protected function is(string $kind, string $column): bool
    {
        $type = $this->schema()['columns'][$column]['type'] ?? null;

        return $type !== null && ! in_array($column, $this->excluded, true) && match ($kind) {
            // Summing ids is never what anyone meant.
            'numeric' => in_array($type, self::NUMERIC, true)
                && $column !== $this->model->getKeyName()
                && ! isset($this->schema()['relations'][$column])
                && ! str_ends_with($column, '_id'),
            'date' => in_array($type, self::DATES, true),
            'group' => $column !== $this->model->getKeyName() && ! in_array($type, [...self::DATES, ...self::UNGROUPABLE], true),
        };
    }

    /**
     * @param  'numeric'|'date'|'group'  $kind
     */
    protected function column(?string $column, string $kind): string
    {
        if (! $this->is($kind, (string) $column)) {
            throw new InvalidArgumentException("[{$column}] is not a usable {$kind} column on [{$this->model->getTable()}].");
        }

        return $this->model->qualifyColumn($column);
    }

    /**
     * Real columns minus hidden and encrypted ones, plus which belong to a BelongsTo.
     *
     * @return array{columns: array<string, array{type: string, boolean: bool}>, relations: array<string, string>}
     */
    protected function schema(): array
    {
        $model = $this->model;
        $casts = $model->getCasts();

        return static::$schemas[$model::class] ??= [
            'columns' => collect($model->getConnection()->getSchemaBuilder()->getColumns($model->getTable()))
                ->reject(fn (array $column): bool => in_array($column['name'], $model->getHidden(), true)
                    || str_contains(strtolower((string) ($casts[$column['name']] ?? '')), 'encrypted'))
                ->mapWithKeys(fn (array $column): array => [$column['name'] => [
                    'type' => strtolower($column['type_name']),
                    'boolean' => in_array($casts[$column['name']] ?? null, ['bool', 'boolean'], true)
                        || in_array(strtolower($column['type']), ['tinyint(1)', 'bool', 'boolean'], true),
                ]])
                ->all(),
            'relations' => collect((new ReflectionClass($model))->getMethods(ReflectionMethod::IS_PUBLIC))
                ->filter(fn (ReflectionMethod $method): bool => ! $method->isStatic()
                    && $method->getNumberOfParameters() === 0
                    && ($type = $method->getReturnType()) instanceof ReflectionNamedType
                    && is_a($type->getName(), BelongsTo::class, true)
                    && ! is_a($type->getName(), MorphTo::class, true))
                // A relation that can't be built on a blank model just loses its pretty labels.
                ->mapWithKeys(fn (ReflectionMethod $method): array => rescue(
                    fn (): array => [$model->{$method->getName()}()->getForeignKeyName() => $method->getName()],
                    [],
                    report: false,
                ))
                ->all(),
        ];
    }
}
