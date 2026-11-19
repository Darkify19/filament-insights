<?php

namespace CarlJanzell\FilamentInsights\Support;

use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use CarlJanzell\FilamentInsights\FilamentInsightsPlugin;
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
        'count_distinct' => 'Unique values',
        'sum' => 'Sum',
        'avg' => 'Average',
        'min' => 'Minimum',
        'max' => 'Maximum',
    ];

    /** Bucket => [key format, display format]. Key formats are also translated to SQL. */
    public const BUCKETS = [
        'hour' => ['Y-m-d H', 'g A'],
        'day' => ['Y-m-d', 'M j'],
        'month' => ['Y-m', 'M Y'],
        'year' => ['Y', 'Y'],
    ];

    public const RANGES = [
        'today' => 'Today',
        '7' => 'Last 7 days',
        '30' => 'Last 30 days',
        '90' => 'Last 90 days',
        '365' => 'Last 365 days',
        'this_week' => 'This week',
        'this_month' => 'This month',
        'this_year' => 'This year',
    ];

    public const FILTER_OPERATORS = [
        'equals' => 'is',
        'not_equals' => 'is not',
        'in' => 'is any of',
        'not_in' => 'is none of',
        'contains' => 'contains',
        'gt' => 'greater than',
        'gte' => 'at least',
        'lt' => 'less than',
        'lte' => 'at most',
        'is_null' => 'is empty',
        'is_not_null' => 'is not empty',
    ];

    protected const NUMERIC = ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'numeric', 'float', 'double', 'double precision', 'real', 'int2', 'int4', 'int8', 'float4', 'float8', 'money'];

    protected const DATES = ['date', 'datetime', 'timestamp', 'timestamptz'];

    /** Free text, binary, and fractional amounts: every row its own group, so never a useful axis. */
    protected const UNGROUPABLE = ['text', 'tinytext', 'mediumtext', 'longtext', 'json', 'jsonb', 'blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary', 'bytea', 'decimal', 'numeric', 'float', 'double', 'double precision', 'real', 'float4', 'float8', 'money'];

    protected const UNFILTERABLE = ['json', 'jsonb', 'blob', 'tinyblob', 'mediumblob', 'longblob', 'binary', 'varbinary', 'bytea'];

    /** @var array<class-string<Model>, array{columns: array<string, array{type: string, boolean: bool}>, relations: array<string, string>}> */
    protected static array $schemas = [];

    /** @var array<class-string<resource>, array<string, string>> */
    protected static array $tableLabels = [];

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
        if (blank($config['date_column'] ?? null)) {
            return [null, null];
        }

        $range = $config['range'] ?? null;

        if ($range === 'today') {
            $day = CarbonImmutable::now()->subDays($period);

            return [$day->startOfDay(), $day->endOfDay()];
        }

        if ($range === 'this_week') {
            $start = CarbonImmutable::now()->startOfWeek()->subWeeks($period);

            return [$start, $start->endOfWeek()];
        }

        if ($range === 'this_month') {
            $start = CarbonImmutable::now()->startOfMonth()->subMonthsNoOverflow($period);

            return [$start, $start->endOfMonth()];
        }

        if ($range === 'this_year') {
            $start = CarbonImmutable::now()->startOfYear()->subYearsNoOverflow($period);

            return [$start, $start->endOfYear()];
        }

        $days = (int) $range;

        if ($days < 1) {
            return [null, null];
        }

        $until = CarbonImmutable::now()->endOfDay()->subDays($days * $period);

        return [$until->subDays($days - 1)->startOfDay(), $until];
    }

    /**
     * Time bucket used for a stat's trend sparkline.
     */
    public static function sparklineBucket(array $config): string
    {
        $range = (string) ($config['range'] ?? '');

        return match (true) {
            $range === 'today' => '@hour',
            $range === 'this_year', (int) $range > 90 => '@month',
            default => '@day',
        };
    }

    /**
     * @return list<string> every column a widget config reads
     */
    public static function columnsIn(array $config): array
    {
        $group = (string) ($config['group_by'] ?? '');
        $filterColumns = collect($config['filters'] ?? [])
            ->filter(fn (mixed $filter): bool => is_array($filter) && filled($filter['column'] ?? null))
            ->map(fn (array $filter): string => (string) $filter['column'])
            ->all();

        return array_values(array_unique(array_filter([
            $config['column'] ?? null,
            $config['date_column'] ?? null,
            str_starts_with($group, '@') ? null : $group,
            ...$filterColumns,
        ])));
    }

    /**
     * The currency a widget's amounts are shown in, or null for plain numbers.
     */
    public static function currencyFor(array $config): ?string
    {
        return ($config['format'] ?? null) === 'money' ? FilamentInsightsPlugin::get()->getCurrency() : null;
    }

    public static function describe(array $config): string
    {
        if (static::window($config)[0] === null) {
            return 'All time';
        }

        $range = (string) ($config['range'] ?? '');

        return self::RANGES[$range] ?? "Last {$range} days";
    }

    /**
     * Friendly human-readable label for a column, preferring the resource table's own label.
     */
    public function labelFor(string $column): string
    {
        return $this->tableLabels()[$column]
            ?? Str::headline($this->schema()['relations'][$column] ?? $column);
    }

    /**
     * Plain-English summary of a widget's active filters, or null when unfiltered.
     */
    public function filterSummary(array $config): ?string
    {
        $parts = collect($config['filters'] ?? [])
            ->filter(fn (mixed $filter): bool => is_array($filter) && filled($filter['column'] ?? null))
            ->map(function (array $filter): ?string {
                $column = (string) $filter['column'];
                $operator = (string) ($filter['operator'] ?? 'equals');
                $opLabel = self::FILTER_OPERATORS[$operator] ?? $operator;
                $fieldLabel = $this->labelFor($column);

                if (in_array($operator, ['is_null', 'is_not_null'], true)) {
                    return "{$fieldLabel} {$opLabel}";
                }

                $value = $filter['value'] ?? null;

                if ($value === null || $value === '' || $value === []) {
                    return null;
                }

                $options = $this->filterValueOptions($column);

                if (is_array($value)) {
                    $formatted = collect($value)
                        ->map(fn (mixed $item): string => $options[(string) $item] ?? (string) $item)
                        ->implode(', ');
                } else {
                    $formatted = $options[(string) $value] ?? (string) $value;
                }

                return "{$fieldLabel} {$opLabel} {$formatted}";
            })
            ->filter()
            ->values()
            ->all();

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * Select options for the widget form: column => label.
     *
     * @param  'numeric'|'date'|'group'|'filter'  $kind
     * @return array<string, string>
     */
    public function options(string $kind): array
    {
        return collect($this->schema()['columns'])
            ->keys()
            ->filter(fn (string $column): bool => $this->is($kind, $column))
            ->mapWithKeys(fn (string $column): array => [$column => $this->labelFor($column)])
            ->all();
    }

    /**
     * Suggested value options for a filter column (enums, booleans, and belongsTo relations).
     *
     * @return array<string, string>
     */
    public function filterValueOptions(string $column): array
    {
        if (! $this->is('filter', $column)) {
            return [];
        }

        $cast = $this->model->getCasts()[$column] ?? null;

        if (is_string($cast) && is_subclass_of($cast, BackedEnum::class)) {
            return collect($cast::cases())
                ->mapWithKeys(fn (BackedEnum $case): array => [
                    (string) $case->value => $case instanceof HasLabel ? (string) $case->getLabel() : Str::headline($case->name),
                ])
                ->all();
        }

        if ($this->schema()['columns'][$column]['boolean'] ?? false) {
            return ['1' => 'Yes', '0' => 'No'];
        }

        if ($relation = $this->schema()['relations'][$column] ?? null) {
            return rescue(function () use ($relation): array {
                /** @var BelongsTo $belongsTo */
                $belongsTo = $this->model->{$relation}();
                $ownerKey = $belongsTo->getOwnerKeyName();
                $resource = Filament::getModelResource($belongsTo->getRelated());

                return $belongsTo->getRelated()->newQuery()
                    ->limit(100)
                    ->get()
                    ->mapWithKeys(fn (Model $record): array => [
                        (string) $record->getAttribute($ownerKey) => $this->title($record, $resource),
                    ])
                    ->all();
            }, [], report: false);
        }

        return [];
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
                ->orderByRaw($bucket)
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
        $table = $this->listTable();

        return ($table?->getQuery() ?? $this->resource::getEloquentQuery())->reorder()->setEagerLoads([]);
    }

    protected function listTable(): mixed
    {
        $page = ($this->resource::getPages()['index'] ?? null)?->getPage();

        if ($page === null || ! is_subclass_of($page, HasTable::class)) {
            return null;
        }

        // Filament's own InteractsWithPageTable does the same: boot the list page
        // off-screen to borrow its table. The tab is cleared so a default tab
        // doesn't silently narrow the numbers; filters are never applied.
        $livewire = app('livewire')->new($page);
        trigger('mount', $livewire, [], null, null, []);

        if (property_exists($livewire, 'activeTab')) {
            $livewire->activeTab = null;
        }

        $livewire->bootedInteractsWithTable();

        return $livewire->getTable();
    }

    /**
     * Human labels from the resource's own list table, keyed by column name.
     *
     * @return array<string, string>
     */
    protected function tableLabels(): array
    {
        return static::$tableLabels[$this->resource] ??= rescue(function (): array {
            $table = $this->listTable();

            if ($table === null) {
                return [];
            }

            return collect($table->getColumns())
                ->filter(fn (mixed $column): bool => is_object($column)
                    && method_exists($column, 'getName')
                    && method_exists($column, 'getLabel')
                    && ! str_contains((string) $column->getName(), '.'))
                ->mapWithKeys(function (object $column): array {
                    $label = $column->getLabel();

                    return is_string($label) && filled($label)
                        ? [(string) $column->getName() => $label]
                        : [];
                })
                ->all();
        }, [], report: false);
    }

    protected function constrain(Builder $query, array $config, ?CarbonImmutable $from, ?CarbonImmutable $until): Builder
    {
        if ($from !== null) {
            $query->whereBetween($this->column($config['date_column'] ?? null, 'date'), [$from, $until]);
        }

        $this->applyFilters($query, $config['filters'] ?? []);

        return $query;
    }

    /**
     * @param  array<int, mixed>  $filters
     */
    protected function applyFilters(Builder $query, array $filters): void
    {
        foreach ($filters as $filter) {
            if (! is_array($filter) || blank($filter['column'] ?? null)) {
                continue;
            }

            $rawColumn = (string) $filter['column'];
            $column = $this->column($rawColumn, 'filter');
            $operator = (string) ($filter['operator'] ?? 'equals');

            if ($operator === 'is_null') {
                $query->whereNull($column);

                continue;
            }

            if ($operator === 'is_not_null') {
                $query->whereNotNull($column);

                continue;
            }

            $value = $filter['value'] ?? null;

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            match ($operator) {
                'equals' => $query->where($column, '=', $this->coerceFilterValue($rawColumn, $value)),
                'not_equals' => $query->where($column, '!=', $this->coerceFilterValue($rawColumn, $value)),
                'gt' => $query->where($column, '>', $value),
                'gte' => $query->where($column, '>=', $value),
                'lt' => $query->where($column, '<', $value),
                'lte' => $query->where($column, '<=', $value),
                'contains' => $query->where(
                    $column,
                    $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like',
                    '%'.addcslashes((string) $value, '\\%_').'%',
                ),
                'in' => $query->whereIn($column, $this->parseFilterList($rawColumn, $value)),
                'not_in' => $query->whereNotIn($column, $this->parseFilterList($rawColumn, $value)),
                default => throw new InvalidArgumentException("[{$operator}] is not a usable filter operator."),
            };
        }
    }

    protected function coerceFilterValue(string $column, mixed $value): mixed
    {
        if (! ($this->schema()['columns'][$column]['boolean'] ?? false)) {
            return $value;
        }

        return match (strtolower((string) $value)) {
            '1', 'true', 'yes' => true,
            '0', 'false', 'no' => false,
            default => (bool) $value,
        };
    }

    /**
     * @return list<mixed>
     */
    protected function parseFilterList(string $column, mixed $value): array
    {
        $items = is_array($value)
            ? $value
            : explode(',', (string) $value);

        return collect($items)
            ->map(fn (mixed $item): string => trim((string) $item))
            ->filter(fn (string $item): bool => $item !== '')
            ->map(fn (string $item): mixed => $this->coerceFilterValue($column, $item))
            ->values()
            ->all();
    }

    protected function aggregate(array $config, Builder $query): string
    {
        $function = $config['aggregate'] ?? 'count';

        if ($function === 'count_distinct') {
            return 'count(distinct '.$query->getQuery()->getGrammar()->wrap($this->column($config['column'] ?? null, 'filter')).')';
        }

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
            'pgsql' => "to_char({$column}, '".strtr($format, ['Y' => 'YYYY', 'm' => 'MM', 'd' => 'DD', 'H' => 'HH24'])."')",
            'sqlite' => "strftime('".strtr($format, ['Y' => '%Y', 'm' => '%m', 'd' => '%d', 'H' => '%H'])."', {$column})",
            default => "date_format({$column}, '".strtr($format, ['Y' => '%Y', 'm' => '%m', 'd' => '%d', 'H' => '%H'])."')",
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

        if ($from === null) {
            $from = $parse($rows->keys()->min());
            $until = $parse($rows->keys()->max());

            // ponytail: an all-time series with one stray date (a "year 4" typo) would zero-fill
            // thousands of buckets; past ~a year of buckets, plot only the ones with data.
            if ($from->{'diffIn'.ucfirst($unit).'s'}($until) > 366) {
                return [
                    $rows->keys()->map(fn (string $bucket): string => $parse($bucket)->format($display))->values()->all(),
                    $rows->values()->map(fn (mixed $value): float => (float) $value)->all(),
                ];
            }
        }

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
            $titles = $this->titlesFor($this->model->{$relation}(), $keys);

            // ponytail: a missed key is labeled from another belongsTo on this model (created_by
            // often stores a personnel id, not a user id). Wrong name if two of those tables share
            // an id and the real row is gone.
            foreach ($this->schema()['relations'] as $other) {
                if ($other === $relation) {
                    continue;
                }

                $missing = $keys->filter(fn (mixed $key): bool => filled($key) && ! isset($titles[$key]));

                if ($missing->isEmpty()) {
                    break;
                }

                $titles += $this->titlesFor($this->model->{$other}(), $missing);
            }
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
     * @param  Collection<int, mixed>  $keys
     * @return array<int|string, string>
     */
    protected function titlesFor(BelongsTo $belongsTo, Collection $keys): array
    {
        $ownerKey = $belongsTo->getOwnerKeyName();
        $ids = $keys->filter(fn (mixed $key): bool => filled($key))->all();

        if ($ids === []) {
            return [];
        }

        $resource = Filament::getModelResource($belongsTo->getRelated());

        return $belongsTo->getRelated()->newQuery()
            ->whereIn($ownerKey, $ids)
            ->get()
            ->mapWithKeys(fn (Model $record): array => [
                $record->getAttribute($ownerKey) => $this->title($record, $resource),
            ])
            ->all();
    }

    /**
     * A related record's title, or its name when the resource never set one.
     *
     * @param  class-string<resource>|null  $resource
     */
    protected function title(Model $record, ?string $resource): string
    {
        if ($resource !== null && $resource::hasRecordTitle()) {
            return (string) ($resource::getRecordTitle($record) ?? $record->getKey());
        }

        foreach (['name', 'full_name'] as $attribute) {
            $value = $record->getAttribute($attribute);

            if (is_scalar($value) && filled($value)) {
                return (string) $value;
            }
        }

        return (string) $record->getKey();
    }

    /**
     * @param  'numeric'|'date'|'group'|'filter'  $kind
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
            'filter' => ! in_array($type, self::UNFILTERABLE, true),
        };
    }

    /**
     * @param  'numeric'|'date'|'group'|'filter'  $kind
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
