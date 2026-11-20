<x-filament-widgets::widget class="fi-wi-insights-table">
    <x-filament::section
        :heading="$heading"
        :description="$description"
    >
        @if (blank($rows))
            <div class="fi-insights-table-empty">
                No matching records
            </div>
        @else
            <div class="fi-insights-table-scroll" style="--fi-insights-bar-accent: {{ $accent }}">
                <table class="fi-insights-table">
                    <thead>
                        <tr>
                            @if ($showRank)
                                <th scope="col" class="fi-insights-col-rank">#</th>
                            @endif
                            <th scope="col" class="fi-insights-col-label">{{ $groupHeader }}</th>
                            <th scope="col" class="fi-insights-col-value">{{ $valueHeader }}</th>
                            @if ($showShare)
                                <th scope="col" class="fi-insights-col-share">Share</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                @if ($showRank)
                                    <td class="fi-insights-col-rank">{{ $row['rank'] }}</td>
                                @endif
                                <td class="fi-insights-col-label" title="{{ $row['label'] }}">{{ $row['label'] }}</td>
                                <td class="fi-insights-col-value">{{ $row['formatted'] }}</td>
                                @if ($showShare)
                                    <td class="fi-insights-col-share">
                                        <div class="fi-insights-share-wrap">
                                            <span class="fi-insights-share-track" aria-hidden="true">
                                                <span
                                                    class="fi-insights-share-fill"
                                                    style="width: {{ min(max((float) ($row['share'] ?? 0), 0), 100) }}%"
                                                ></span>
                                            </span>
                                            <span class="fi-insights-share-pct">{{ number_format((float) ($row['share'] ?? 0), 1) }}%</span>
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                    @if ($showTotal)
                        <tfoot>
                            <tr>
                                @if ($showRank)
                                    <td class="fi-insights-col-rank"></td>
                                @endif
                                <td class="fi-insights-col-label">Total</td>
                                <td class="fi-insights-col-value">{{ $totalFormatted }}</td>
                                @if ($showShare)
                                    <td class="fi-insights-col-share fi-insights-total-pct">100%</td>
                                @endif
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        @endif
    </x-filament::section>

    <style>
        .fi-wi-insights-table .fi-section-content {
            padding: 0 !important;
        }
        .fi-insights-table-empty {
            padding: 2rem 1.25rem;
            text-align: center;
            font-size: 0.875rem;
            opacity: 0.65;
        }
        .fi-insights-table-scroll {
            max-height: 320px;
            overflow-y: auto;
            overflow-x: auto;
            border: 0;
            border-radius: 0 0 0.75rem 0.75rem;
        }
        .fi-insights-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.84rem;
            line-height: 1.4;
        }
        .fi-insights-table thead th {
            position: sticky;
            top: 0;
            z-index: 1;
            padding: 0.65rem 1.25rem;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            text-align: left;
            background: color-mix(in srgb, Canvas 94%, currentColor 6%);
            border-bottom: 1px solid color-mix(in srgb, currentColor 14%, transparent);
            white-space: nowrap;
        }
        .dark .fi-insights-table thead th {
            background: #0f172a;
        }
        .fi-insights-table tbody td {
            padding: 0.65rem 1.25rem;
            border-bottom: 1px solid color-mix(in srgb, currentColor 8%, transparent);
        }
        .fi-insights-table tbody tr:last-child td {
            border-bottom: 0;
        }
        .fi-insights-table tbody tr:hover td {
            background: color-mix(in srgb, currentColor 4%, transparent);
        }
        .fi-insights-table tfoot td {
            padding: 0.7rem 1.25rem;
            font-weight: 700;
            border-top: 1px solid color-mix(in srgb, currentColor 16%, transparent);
            background: color-mix(in srgb, currentColor 4%, transparent);
        }
        .fi-insights-col-rank {
            width: 2.25rem;
            opacity: 0.55;
            font-variant-numeric: tabular-nums;
            text-align: left;
        }
        .fi-insights-col-label {
            max-width: 16rem;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-weight: 500;
        }
        .fi-insights-col-value {
            text-align: right !important;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }
        .fi-insights-col-share {
            width: 8.5rem;
            text-align: right !important;
            white-space: nowrap;
        }
        .fi-insights-share-wrap {
            display: inline-flex;
            align-items: center;
            justify-content: flex-end;
            gap: 0.5rem;
            width: 100%;
        }
        .fi-insights-share-track {
            flex: 1 1 3.5rem;
            height: 0.375rem;
            border-radius: 9999px;
            background: color-mix(in srgb, currentColor 12%, transparent);
            overflow: hidden;
        }
        .fi-insights-share-fill {
            display: block;
            height: 100%;
            border-radius: 9999px;
            background: var(--fi-insights-bar-accent, #3b82f6);
        }
        .fi-insights-share-pct,
        .fi-insights-total-pct {
            min-width: 2.85rem;
            font-size: 0.78rem;
            opacity: 0.75;
            font-variant-numeric: tabular-nums;
            text-align: right;
        }
    </style>
</x-filament-widgets::widget>
