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
                'x-on:end.stop' => '$event.oldIndex !== $event.newIndex && save()',
                'x-on:keydown' => 'nudge($event)',
            ] : []);
        @endphp

        {{--
            Plain CSS grid: plugin markup can't rely on the host compiling Tailwind classes.
            Managers rearrange it with Filament's own x-sortable (SortableJS, shipped on v3–v5):
            mouse drags at once, touch after a press-and-hold so the page still scrolls, and
            Alt + arrow keys move the focused widget one place.

            Motion (resources/js/motion.js, on anime.js) is imported only here and only when the
            viewer allows it. `fi-insights-pending` hides the widgets until it takes over; a CSS
            fallback below shows them anyway if it never does. wire:ignore.self keeps Livewire
            from putting that class back on later renders.
        --}}
        <div
            class="fi-insights-grid fi-insights-pending"
            wire:ignore.self
            data-anime-src="{{ \Filament\Support\Facades\FilamentAsset::getScriptSrc('anime', \CarlJanzell\FilamentInsights\InsightsServiceProvider::PACKAGE) }}"
            data-motion-src="{{ \Filament\Support\Facades\FilamentAsset::getScriptSrc('motion', \CarlJanzell\FilamentInsights\InsightsServiceProvider::PACKAGE) }}"
            x-data="{
                init() {
                    const started = performance.now()

                    window.filamentInsightsMotion ??= matchMedia('(prefers-reduced-motion: reduce)').matches
                        ? Promise.resolve(null)
                        : Promise.all([import(this.$el.dataset.animeSrc), import(this.$el.dataset.motionSrc)])
                            .then(([anime, motion]) => motion.default(anime), () => null)

                    // Too late for an entrance once the CSS fallback may already have shown the widgets.
                    window.filamentInsightsMotion.then((motion) => motion
                        ? motion.watch(this.$el, performance.now() - started < 1200)
                        : this.$el.classList.remove('fi-insights-pending'))

                    if (! this.$el.hasAttribute('x-sortable')) return

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
                    window.filamentInsightsMotion.then((motion) => motion?.land(item))
                    this.save()
                },
                save() {
                    this.$wire.reorderWidgets(this.$el.sortable.toArray())
                },
            }"
            {{ $gridArrangement }}
        >
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
        .fi-insights-grid > [x-sortable-handle] { cursor: grab; }
        .fi-insights-grid > [x-sortable-handle]:focus-visible { outline: 2px solid; outline-offset: 2px; }

        /* Motion: motion.js animates these variables and removes them when done. Unset, `translate`
           and `scale` fall back to none, so a resting widget has no transform or stacking context. */
        .fi-insights-grid > * {
            border-radius: 0.75rem;
            translate: 0 var(--fi-rise);
            scale: var(--fi-scale);
            box-shadow:
                0 calc(var(--fi-lift, 0) * 18px) calc(var(--fi-lift, 0) * 40px) calc(var(--fi-lift, 0) * -12px) rgb(0 0 0 / calc(var(--fi-lift, 0) * 0.3)),
                0 0 0 2px color-mix(in srgb, currentColor calc(var(--fi-ring, 0) * 40%), transparent);
        }

        /* While dragging, the widget's own spot is a soft outlined slot rather than a faded copy. */
        .fi-insights-grid > .fi-sortable-ghost { opacity: 1; outline: 2px dashed color-mix(in srgb, currentColor 30%, transparent); outline-offset: 2px; }
        .fi-insights-grid > .fi-sortable-ghost > * { opacity: 0.2; }
        /* Touch drags a copy of the widget; SortableJS fades it to 0.8 inline, which muddies the lift. */
        .fi-insights-grid > .sortable-fallback { opacity: 1 !important; }

        /* Hidden until motion.js plays the entrance; shown regardless after 1.5s or with reduced motion. */
        .fi-insights-pending > * { opacity: 0; animation: fi-insights-reveal 1ms 1.5s forwards; }
        @keyframes fi-insights-reveal { to { opacity: 1; } }
        @media (prefers-reduced-motion: reduce) {
            .fi-insights-pending > * { opacity: 1; animation: none; }
        }

        @media (min-width: 1024px) {
            .fi-insights-grid { grid-template-columns: repeat(12, minmax(0, 1fr)); }
            .fi-insights-grid > * { grid-column: span var(--fi-insights-span) / span var(--fi-insights-span); }
        }
    </style>
</x-filament-panels::page>
