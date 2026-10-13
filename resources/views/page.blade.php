<x-filament-panels::page>
    @php
        $widgets = $this->getDashboardMounts();
    @endphp

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
        @php
            $arrangeable = \CarlJanzell\FilamentInsights\FilamentInsightsPlugin::get()->isManageable();

            // Attribute bags, not @if inside the tags: Livewire 3 injects a morph marker at every
            // @if, and one inside a tag's attribute list closes that tag early.
            $gridArrangement = new \Illuminate\View\ComponentAttributeBag($arrangeable ? [
                'role' => 'list',
                'x-sortable' => '',
                'x-data' => <<<'JS'
                {
                    init() {
                        this.$nextTick(() => Object.entries({
                            delay: 350,
                            delayOnTouchOnly: true,
                            touchStartThreshold: 8,
                            filter: 'input, textarea, select, [contenteditable]',
                            preventOnFilter: false,
                            animation: matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 250,
                        }).forEach(([option, value]) => this.$el.sortable.option(option, value)))
                    },
                    nudge(event) {
                        const step = { ArrowLeft: -1, ArrowUp: -1, ArrowRight: 1, ArrowDown: 1 }[event.key]
                        const item = event.target

                        if (! event.altKey || ! step || item.parentElement !== this.$el) return
                        event.preventDefault()

                        const to = step < 0 ? item.previousElementSibling : item.nextElementSibling
                        if (! to) return

                        this.$el.sortable.captureAnimationState()
                        to[step < 0 ? 'before' : 'after'](item)
                        this.$el.sortable.animateAll()
                        item.focus()
                        this.save()
                    },
                    save() {
                        this.$wire.reorderWidgets(this.$el.sortable.toArray())
                    },
                }
                JS,
                'x-on:end.stop' => '$event.oldIndex !== $event.newIndex && save()',
                'x-on:keydown' => 'nudge($event)',
            ] : []);
        @endphp

        {{--
            Plain CSS grid: plugin markup can't rely on the host compiling Tailwind classes.
            Managers rearrange it with Filament's own x-sortable (SortableJS, shipped on v3 and v5):
            mouse drags at once, touch after a press-and-hold so the page still scrolls, and
            Alt + arrow keys move the focused widget one place.
        --}}
        <div class="fi-insights-grid" {{ $gridArrangement }}>
            @foreach ($widgets as $widget)
                <div
                    wire:key="{{ $widget['key'] }}"
                    style="--fi-insights-span: {{ $widget['span'] }}"
                    {{ new \Illuminate\View\ComponentAttributeBag($arrangeable ? [
                        'role' => 'listitem',
                        'tabindex' => '0',
                        'aria-description' => 'Drag to rearrange, or hold Alt (Option) and press the arrow keys.',
                        'x-sortable-item' => $widget['key'],
                        'x-sortable-handle' => '',
                    ] : []) }}
                >
                    @livewire($widget['class'], $widget['props'], key($widget['key']))
                </div>
            @endforeach
        </div>
    @endif

    <style>
        .fi-insights-grid { display: grid; gap: 1.5rem; grid-template-columns: minmax(0, 1fr); }
        .fi-insights-grid > [x-sortable-handle] { cursor: grab; border-radius: 0.75rem; }
        .fi-insights-grid > [x-sortable-handle]:focus-visible { outline: 2px solid; outline-offset: 2px; }
        @media (min-width: 1024px) {
            .fi-insights-grid { grid-template-columns: repeat(12, minmax(0, 1fr)); }
            .fi-insights-grid > * { grid-column: span var(--fi-insights-span) / span var(--fi-insights-span); }
        }
    </style>
</x-filament-panels::page>
