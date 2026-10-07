<x-filament-panels::page>
    @php($widgets = $this->getDashboardMounts())

    @if (blank($widgets))
        <x-filament::section>
            <div style="padding: 2rem 0; text-align: center">
                <x-filament::icon
                    icon="heroicon-o-presentation-chart-line"
                    style="width: 2.5rem; height: 2.5rem; margin: 0 auto 0.75rem; opacity: 0.5"
                />
                <p style="font-weight: 600">No widgets yet</p>
                <p style="margin-top: 0.25rem; font-size: 0.875rem; opacity: 0.7">
                    {{ \CarlJanzell\FilamentInsights\FilamentInsightsPlugin::get()->isManageable()
                        ? 'Use “Edit dashboard” to add stats and charts.'
                        : 'Nothing has been added to this dashboard yet.' }}
                </p>
            </div>
        </x-filament::section>
    @else
        {{-- Plain CSS grid: plugin markup can't rely on the host compiling Tailwind classes. --}}
        <div class="fi-insights-grid">
            @foreach ($widgets as $widget)
                <div style="--fi-insights-span: {{ $widget['span'] }}">
                    @livewire($widget['class'], $widget['props'], key($widget['key']))
                </div>
            @endforeach
        </div>
    @endif

    <style>
        .fi-insights-grid { display: grid; gap: 1.5rem; grid-template-columns: minmax(0, 1fr); }
        @media (min-width: 1024px) {
            .fi-insights-grid { grid-template-columns: repeat(12, minmax(0, 1fr)); }
            .fi-insights-grid > * { grid-column: span var(--fi-insights-span) / span var(--fi-insights-span); }
        }
    </style>
</x-filament-panels::page>
