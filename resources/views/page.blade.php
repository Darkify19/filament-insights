<x-filament-panels::page>
    @php
        $widgets = $this->getDashboardMounts();
        $canManage = \CarlJanzell\FilamentInsights\FilamentInsightsPlugin::get()->isManageable();
        $arrangeable = $canManage && ! $this->previewing;
        $spans = \CarlJanzell\FilamentInsights\Pages\InsightsPage::SPANS;
        $spanOrder = array_keys($spans);

        // Precompute initial widget counts per section for server-rendered manager badges.
        $sectionCounts = [];
        $activeSectionKey = null;
        foreach ($widgets as $item) {
            if (($item['type'] ?? null) === 'section') {
                $activeSectionKey = $item['key'];
                $sectionCounts[$activeSectionKey] = 0;
            } elseif ($activeSectionKey !== null) {
                $sectionCounts[$activeSectionKey]++;
            }
        }
    @endphp

    @if (blank($widgets))
        <x-filament::section>
            <div style="padding: 2.5rem 1rem; text-align: center">
                <x-filament::icon
                    icon="heroicon-o-presentation-chart-line"
                    style="width: 2.75rem; height: 2.75rem; margin: 0 auto 0.85rem; opacity: 0.55"
                />
                <p style="font-weight: 600; font-size: 1.05rem">No widgets yet</p>
                <p style="margin-top: 0.35rem; font-size: 0.875rem; opacity: 0.72; max-width: 28rem; margin-inline: auto">
                    {{ $canManage
                        ? 'Build your dashboard with sections, KPI counters, charts, summary tables, or custom widgets.'
                        : 'Nothing has been added to this dashboard yet.' }}
                </p>
                @if ($canManage)
                    <div style="margin-top: 1.25rem; display: inline-flex; flex-wrap: wrap; justify-content: center; gap: 0.6rem">
                        <x-filament::button
                            icon="heroicon-o-plus"
                            wire:click="mountAction('addWidget')"
                        >
                            Add your first widget
                        </x-filament::button>
                        <x-filament::button
                            icon="heroicon-o-rectangle-group"
                            color="gray"
                            wire:click="mountAction('addSection')"
                        >
                            Add section
                        </x-filament::button>
                    </div>
                @endif
            </div>
        </x-filament::section>
    @else
        @php
            // Attribute bags, not @if inside the tags: Livewire 3 injects a morph marker at every
            // @if, and one inside a tag's attribute list closes that tag early.
            // Keep x-sortable mounted on the single flat grid for managers so toggling Preview
            // and dragging across sections never remounts charts or breaks Livewire morphing.
            $gridArrangement = new \Illuminate\View\ComponentAttributeBag($canManage ? [
                'role' => 'list',
                'x-sortable' => '',
                'x-on:start.stop' => 'onDragStart($event)',
                'x-on:keydown' => 'nudge($event)',
            ] : []);
        @endphp

        {{--
            Single flat CSS grid with container queries: responds to the actual dashboard pane width
            (even when Filament's sidebar is open), never relies on host Tailwind compilation, and
            keeps every Livewire widget inside one parent container so dragging widgets into, out of,
            and between sections always stays in sync with Livewire morphing and Preview mode.
        --}}
        <div class="fi-insights-wrap">
            <div
                class="fi-insights-grid fi-insights-pending"
                x-ref="grid"
                wire:ignore.self
                data-anime-src="{{ \Filament\Support\Facades\FilamentAsset::getScriptSrc('anime', \CarlJanzell\FilamentInsights\InsightsServiceProvider::PACKAGE) }}"
                data-motion-src="{{ \Filament\Support\Facades\FilamentAsset::getScriptSrc('motion', \CarlJanzell\FilamentInsights\InsightsServiceProvider::PACKAGE) }}"
                x-data="{
                    collapsed: {},
                    counts: @js($sectionCounts),
                    init() {
                        const grid = this.$refs.grid
                        const started = performance.now()

                        this.seedCollapsed()
                        this.syncSections()

                        new MutationObserver(() => {
                            if (grid._draggingSection) return
                            this.seedCollapsed()
                            this.syncSections()
                        }).observe(grid, { childList: true })

                        window.filamentInsightsMotion ??= matchMedia('(prefers-reduced-motion: reduce)').matches
                            ? Promise.resolve(null)
                            : Promise.all([import(grid.dataset.animeSrc), import(grid.dataset.motionSrc)])
                                .then(([anime, motion]) => motion.default(anime), () => null)

                        // Too late for an entrance once the CSS fallback may already have shown the widgets.
                        window.filamentInsightsMotion.then((motion) => motion
                            ? motion.watch(grid, performance.now() - started < 1200)
                            : grid.classList.remove('fi-insights-pending'))

                        if (! grid.hasAttribute('x-sortable')) return

                        this.$nextTick(() => {
                            const filamentOnEnd = grid.sortable.option('onEnd')

                            Object.entries({
                                delay: 350,
                                delayOnTouchOnly: true,
                                touchStartThreshold: 8,
                                filter: 'input, textarea, select, [contenteditable], .fi-insights-toolbar-actions, .fi-insights-toolbar-actions *, .fi-insights-section-toggle, .fi-insights-section-toggle *',
                                preventOnFilter: false,
                                animation: matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 250,
                                onMove: (evt) => {
                                    if (evt.dragged?.dataset?.widgetType !== 'section') return true

                                    const related = evt.related
                                    if (! related) return false

                                    const draggedIsAfter = Boolean(
                                        related.compareDocumentPosition(evt.dragged) & Node.DOCUMENT_POSITION_FOLLOWING
                                    )

                                    if (draggedIsAfter) {
                                        // Dragging UP: only snap strictly BEFORE another section header (never between a section and its children).
                                        return related.dataset.widgetType === 'section' ? -1 : false
                                    }

                                    // Dragging DOWN: ignore own children, and only snap AFTER an empty section or the last child of another section.
                                    const ownChildren = grid._sectionSnapshot?.map?.get(evt.dragged) ?? []
                                    if (ownChildren.includes(related)) return false

                                    const next = related.nextElementSibling
                                    const isEndOfSection = ! next || next.dataset.widgetType === 'section'

                                    return isEndOfSection ? 1 : false
                                },
                                // Run after Filament's own onEnd (SortableJS dispatches the DOM 'end' event BEFORE options.onEnd).
                                onEnd: (evt) => {
                                    if (evt.item?.dataset?.widgetType !== 'section') {
                                        filamentOnEnd?.call(grid.sortable, evt)
                                    }

                                    this.onDragEnd(evt)
                                },
                            }).forEach(([option, value]) => grid.sortable.option(option, value))
                        })
                    },
                    seedCollapsed() {
                        ;[...this.$refs.grid.children].forEach((el) => {
                            if (el.dataset.widgetType !== 'section') return
                            const key = el.dataset.sectionKey
                            if (key && ! (key in this.collapsed)) {
                                this.collapsed[key] = el.dataset.defaultCollapsed === 'true'
                            }
                        })
                    },
                    toggleSection(key) {
                        this.collapsed[key] = ! this.collapsed[key]
                        this.syncSections()
                    },
                    syncSections() {
                        let current = null
                        let hidden = false
                        const nextCounts = {}

                        ;[...this.$refs.grid.children].forEach((el) => {
                            if (el.dataset.widgetType === 'section') {
                                current = el.dataset.sectionKey
                                nextCounts[current] = 0
                                hidden = Boolean(this.collapsed[current])
                                el.classList.remove('fi-insights-section-hidden')
                                return
                            }

                            if (current !== null) {
                                nextCounts[current] = (nextCounts[current] ?? 0) + 1
                            }

                            el.classList.toggle('fi-insights-section-hidden', hidden)
                        })

                        this.counts = nextCounts
                    },
                    onDragStart(event) {
                        const grid = this.$refs.grid
                        const item = event.item
                        // Keep raw DOM nodes off reactive Alpine state so Proxy wrapping never breaks DOM moves.
                        grid._sectionSnapshot = null
                        grid._draggingSection = false

                        if (item?.dataset?.widgetType !== 'section') return

                        grid._draggingSection = true

                        const leading = []
                        const map = new Map()
                        let current = null

                        ;[...grid.children].forEach((el) => {
                            if (el.dataset.widgetType === 'section') {
                                current = el
                                map.set(el, [])
                            } else if (current === null) {
                                leading.push(el)
                            } else {
                                map.get(current).push(el)
                            }
                        })

                        grid._sectionSnapshot = { leading, map }
                        ;(map.get(item) ?? []).forEach((el) => el.classList.add('fi-insights-section-dragged-own'))
                        grid.classList.add('is-dragging-section')
                    },
                    onDragEnd(event) {
                        const grid = this.$refs.grid
                        const item = event.item
                        const snapshot = grid._sectionSnapshot
                        grid._sectionSnapshot = null
                        grid._draggingSection = false
                        grid.classList.remove('is-dragging-section')
                        ;(snapshot?.map?.get(item) ?? []).forEach((el) => el.classList.remove('fi-insights-section-dragged-own'))

                        if (item?.dataset?.widgetType === 'section' && snapshot) {
                            const order = [...snapshot.leading]

                            ;[...grid.children].forEach((el) => {
                                if (el.dataset.widgetType === 'section') {
                                    order.push(el, ...(snapshot.map.get(el) ?? []))
                                }
                            })

                            // Reapply exact section + children grouping in DOM order without touching already-ordered leading nodes.
                            order.forEach((el, index) => {
                                if (grid.children[index] !== el) {
                                    grid.insertBefore(el, grid.children[index] ?? null)
                                }
                            })

                            this.syncSections()
                            this.save()

                            return
                        }

                        if (event.oldIndex !== event.newIndex) {
                            this.syncSections()
                            this.save()
                        }
                    },
                    nudge(event) {
                        const grid = this.$refs.grid
                        const step = { ArrowLeft: -1, ArrowUp: -1, ArrowRight: 1, ArrowDown: 1 }[event.key]
                        const item = event.target

                        if (! event.altKey || ! step || item.parentElement !== grid || ! item.hasAttribute('x-sortable-handle')) return
                        event.preventDefault()

                        const to = step < 0 ? item.previousElementSibling : item.nextElementSibling
                        if (! to) return

                        grid.sortable.captureAnimationState()
                        to[step < 0 ? 'before' : 'after'](item)
                        grid.sortable.animateAll()
                        item.focus()
                        this.syncSections()
                        window.filamentInsightsMotion.then((motion) => motion?.land(item))
                        this.save()
                    },
                    save() {
                        const keys = this.$refs.grid.sortable.toArray()
                        if (keys.length > 0) {
                            this.$wire.reorderWidgets(keys)
                        }
                    },
                }"
                {{ $gridArrangement }}
            >
                @foreach ($widgets as $widget)
                    @if (($widget['type'] ?? null) === 'section')
                        @php
                            $sectionKey = $widget['key'];
                            $itemCount = $sectionCounts[$sectionKey] ?? 0;

                            $sectionAttrs = new \Illuminate\View\ComponentAttributeBag(array_filter([
                                'data-widget-type' => 'section',
                                'data-section-key' => $sectionKey,
                                'data-default-collapsed' => ($widget['collapsed'] ?? false) ? 'true' : 'false',
                                'data-accent' => $widget['color'] ?? null,
                                ...($arrangeable ? [
                                    'role' => 'listitem',
                                    'tabindex' => '0',
                                    'aria-description' => 'Drag to move section, or hold Alt (Option) and press the arrow keys.',
                                    'x-sortable-item' => $sectionKey,
                                    'x-sortable-handle' => '',
                                ] : []),
                            ], fn (mixed $v): bool => $v !== null));
                        @endphp
                        <div
                            class="fi-insights-section"
                            wire:key="{{ $sectionKey }}"
                            {{ $sectionAttrs }}
                        >
                            <div class="fi-insights-section-inner">
                                <div class="fi-insights-section-main">
                                    @if (filled($widget['icon'] ?? null))
                                        <span class="fi-insights-section-icon" aria-hidden="true">
                                            <x-filament::icon :icon="$widget['icon']" style="width: 1.2rem; height: 1.2rem" />
                                        </span>
                                    @endif

                                    <div class="fi-insights-section-copy">
                                        <div class="fi-insights-section-title-row">
                                            <h2 class="fi-insights-section-title">{{ $widget['label'] }}</h2>

                                            @if ($arrangeable)
                                                <span
                                                    class="fi-insights-section-count"
                                                    x-text="(counts[{{ \Illuminate\Support\Js::from($sectionKey) }}] ?? {{ $itemCount }}) + ((counts[{{ \Illuminate\Support\Js::from($sectionKey) }}] ?? {{ $itemCount }}) === 1 ? ' widget' : ' widgets')"
                                                >
                                                    {{ $itemCount }} {{ $itemCount === 1 ? 'widget' : 'widgets' }}
                                                </span>
                                            @endif
                                        </div>

                                        @if (filled($widget['description'] ?? null))
                                            <p class="fi-insights-section-desc">{{ $widget['description'] }}</p>
                                        @endif
                                    </div>
                                </div>

                                <div class="fi-insights-section-controls">
                                    @if ($widget['collapsible'] ?? false)
                                        <button
                                            type="button"
                                            class="fi-insights-tool-btn fi-insights-section-toggle"
                                            x-on:click.stop="toggleSection({{ \Illuminate\Support\Js::from($sectionKey) }})"
                                            x-bind:aria-expanded="(! collapsed[{{ \Illuminate\Support\Js::from($sectionKey) }}]).toString()"
                                            x-bind:title="collapsed[{{ \Illuminate\Support\Js::from($sectionKey) }}] ? 'Expand section' : 'Collapse section'"
                                            aria-label="Toggle {{ $widget['label'] ?: 'section' }}"
                                        >
                                            <span
                                                class="fi-insights-chevron"
                                                x-bind:class="{ 'is-collapsed': collapsed[{{ \Illuminate\Support\Js::from($sectionKey) }}] }"
                                            >
                                                <x-filament::icon icon="heroicon-o-chevron-down" style="width: 1.05rem; height: 1.05rem" />
                                            </span>
                                        </button>
                                    @endif

                                    @if ($arrangeable)
                                        <div class="fi-insights-section-toolbar" aria-label="Section actions">
                                            <span
                                                class="fi-insights-grip"
                                                title="Drag to move section (or focus and press Alt + Arrow keys)"
                                                aria-hidden="true"
                                            >
                                                <x-filament::icon icon="heroicon-o-bars-2" style="width: 0.9rem; height: 0.9rem; flex-shrink: 0" />
                                            </span>

                                            <div class="fi-insights-toolbar-actions">
                                                <button
                                                    type="button"
                                                    class="fi-insights-tool-btn"
                                                    title="Add widget inside {{ $widget['label'] ?: 'section' }}"
                                                    aria-label="Add widget to {{ $widget['label'] ?: 'section' }}"
                                                    wire:click="mountAction('addWidget', {{ \Illuminate\Support\Js::from(['section_key' => $sectionKey]) }})"
                                                >
                                                    <x-filament::icon icon="heroicon-o-plus" style="width: 0.9rem; height: 0.9rem" />
                                                </button>

                                                <button
                                                    type="button"
                                                    class="fi-insights-tool-btn"
                                                    title="Edit section"
                                                    aria-label="Edit {{ $widget['label'] ?: 'section' }}"
                                                    wire:click="mountAction('editWidget', {{ \Illuminate\Support\Js::from(['key' => $sectionKey]) }})"
                                                >
                                                    <x-filament::icon icon="heroicon-o-pencil-square" style="width: 0.9rem; height: 0.9rem" />
                                                </button>

                                                <button
                                                    type="button"
                                                    class="fi-insights-tool-btn"
                                                    title="Duplicate section"
                                                    aria-label="Duplicate {{ $widget['label'] ?: 'section' }}"
                                                    wire:click="duplicateWidget({{ \Illuminate\Support\Js::from($sectionKey) }})"
                                                >
                                                    <x-filament::icon icon="heroicon-o-document-duplicate" style="width: 0.9rem; height: 0.9rem" />
                                                </button>

                                                <button
                                                    type="button"
                                                    class="fi-insights-tool-btn fi-insights-tool-danger"
                                                    title="Remove section"
                                                    aria-label="Remove {{ $widget['label'] ?: 'section' }}"
                                                    wire:click="mountAction('deleteWidget', {{ \Illuminate\Support\Js::from(['key' => $sectionKey]) }})"
                                                >
                                                    <x-filament::icon icon="heroicon-o-trash" style="width: 0.9rem; height: 0.9rem" />
                                                </button>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </div>

                            @if ($arrangeable)
                                <div
                                    class="fi-insights-section-empty-hint"
                                    x-show="(counts[{{ \Illuminate\Support\Js::from($sectionKey) }}] ?? {{ $itemCount }}) === 0"
                                    @if ($itemCount > 0) style="display: none" @endif
                                >
                                    No widgets in this section yet — drag a widget below this heading or click <strong>+</strong> to add one.
                                </div>
                            @endif
                        </div>
                    @else
                        @php
                            $currentSpanIndex = array_search($widget['span'], $spanOrder, true);
                            $nextSpan = $spanOrder[$currentSpanIndex === false ? 0 : ($currentSpanIndex + 1) % count($spanOrder)];
                            $spanLabel = $spans[$widget['span']] ?? '½';
                            $nextSpanLabel = $spans[$nextSpan] ?? '½';

                            $cardAttrs = new \Illuminate\View\ComponentAttributeBag(array_filter([
                                'data-widget-type' => $widget['type'] ?? 'widget',
                                'data-span' => (string) $widget['span'],
                                'data-accent' => $widget['color'] ?? null,
                                ...($arrangeable ? [
                                    'role' => 'listitem',
                                    'tabindex' => '0',
                                    'aria-description' => 'Drag to rearrange or move between sections, or hold Alt (Option) and press the arrow keys.',
                                    'x-sortable-item' => $widget['key'],
                                    'x-sortable-handle' => '',
                                ] : []),
                            ], fn (mixed $v): bool => $v !== null));
                        @endphp
                        <div
                            class="fi-insights-card"
                            wire:key="{{ $widget['key'] }}"
                            style="--fi-insights-span: {{ $widget['span'] }}"
                            {{ $cardAttrs }}
                        >
                            @if ($arrangeable)
                                <div class="fi-insights-toolbar" aria-label="Widget actions">
                                    <span
                                        class="fi-insights-grip"
                                        title="Drag to move (or focus card and press Alt + Arrow keys)"
                                        aria-hidden="true"
                                    >
                                        <x-filament::icon icon="heroicon-o-bars-2" style="width: 0.9rem; height: 0.9rem; flex-shrink: 0" />
                                    </span>

                                    <div class="fi-insights-toolbar-actions">
                                        <button
                                            type="button"
                                            class="fi-insights-tool-btn fi-insights-span-btn"
                                            title="Width: {{ $spanLabel }} — click to change to {{ $nextSpanLabel }}"
                                            wire:click="resizeWidget({{ \Illuminate\Support\Js::from($widget['key']) }}, {{ $nextSpan }})"
                                        >
                                            {{ $spanLabel }}
                                        </button>

                                        <button
                                            type="button"
                                            class="fi-insights-tool-btn"
                                            title="Edit widget"
                                            aria-label="Edit {{ $widget['label'] ?: 'widget' }}"
                                            wire:click="mountAction('editWidget', {{ \Illuminate\Support\Js::from(['key' => $widget['key']]) }})"
                                        >
                                            <x-filament::icon icon="heroicon-o-pencil-square" style="width: 0.9rem; height: 0.9rem" />
                                        </button>

                                        <button
                                            type="button"
                                            class="fi-insights-tool-btn"
                                            title="Duplicate widget"
                                            aria-label="Duplicate {{ $widget['label'] ?: 'widget' }}"
                                            wire:click="duplicateWidget({{ \Illuminate\Support\Js::from($widget['key']) }})"
                                        >
                                            <x-filament::icon icon="heroicon-o-document-duplicate" style="width: 0.9rem; height: 0.9rem" />
                                        </button>

                                        <button
                                            type="button"
                                            class="fi-insights-tool-btn fi-insights-tool-danger"
                                            title="Remove widget"
                                            aria-label="Remove {{ $widget['label'] ?: 'widget' }}"
                                            wire:click="mountAction('deleteWidget', {{ \Illuminate\Support\Js::from(['key' => $widget['key']]) }})"
                                        >
                                            <x-filament::icon icon="heroicon-o-trash" style="width: 0.9rem; height: 0.9rem" />
                                        </button>
                                    </div>
                                </div>
                            @endif

                            <div class="fi-insights-card-body">
                                @livewire($widget['class'], $widget['props'], key($widget['key']))
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    @endif

    <style>
        .fi-insights-wrap {
            container-type: inline-size;
            container-name: fi-insights;
            width: 100%;
            min-width: 0;
        }
        .fi-insights-grid {
            display: grid;
            gap: 1.25rem;
            grid-template-columns: minmax(0, 1fr);
            align-items: start;
        }
        .fi-insights-section-hidden,
        .fi-insights-section-dragged-own {
            display: none !important;
        }
        .fi-insights-grid.is-dragging-section > .fi-insights-card {
            opacity: 0.4;
            transition: opacity 150ms ease;
        }
        .fi-insights-grid.is-dragging-section > .fi-insights-section {
            padding: 0.5rem 0.75rem;
            border-radius: 0.75rem;
            border: 1px dashed color-mix(in srgb, currentColor 22%, transparent);
            background: color-mix(in srgb, currentColor 4%, transparent);
        }
        .fi-insights-card {
            position: relative;
            min-width: 0;
            max-width: 100%;
        }
        .fi-insights-card-body {
            min-width: 0;
            max-width: 100%;
            width: 100%;
            border-radius: 0.75rem;
        }
        /* Keep Chart.js canvases and Livewire roots constrained inside their grid track. */
        .fi-insights-card-body > *,
        .fi-insights-card-body .fi-wi-chart,
        .fi-insights-card-body .fi-section {
            min-width: 0;
            max-width: 100%;
        }
        .fi-insights-card-body .fi-wi-chart-canvas-ctn,
        .fi-insights-card-body .fi-section-content {
            min-width: 0;
            max-width: 100%;
            overflow: hidden;
        }
        .fi-insights-card-body canvas {
            max-width: 100% !important;
        }
        .fi-insights-grid > [x-sortable-handle] { cursor: grab; }
        .fi-insights-grid > [x-sortable-handle]:active { cursor: grabbing; }
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

        /* Semantic accent custom properties shared by cards and sections. */
        [data-accent="primary"] { --fi-insights-accent: #3b82f6; }
        [data-accent="info"] { --fi-insights-accent: #06b6d4; }
        [data-accent="success"] { --fi-insights-accent: #10b981; }
        [data-accent="warning"] { --fi-insights-accent: #f59e0b; }
        [data-accent="danger"] { --fi-insights-accent: #ef4444; }
        [data-accent="gray"] { --fi-insights-accent: #64748b; }

        /* Subtle semantic accent strip hugging the card body when an accent color is chosen. */
        .fi-insights-card[data-accent] .fi-insights-card-body { border-top: 3px solid var(--fi-insights-accent); }

        /* Borderless, background-free full-width Section Header */
        .fi-insights-section {
            grid-column: 1 / -1 !important;
            min-width: 0;
            padding: 0.25rem 0 0.35rem;
            border: 0;
            background: transparent;
            box-shadow: none;
        }
        .fi-insights-section:not(:first-child) {
            margin-top: 1.15rem;
        }
        .fi-insights-section-inner {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.75rem;
            flex-wrap: wrap;
        }
        .fi-insights-section-main {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            min-width: 0;
            flex: 1 1 14rem;
        }
        .fi-insights-section-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 2.35rem;
            height: 2.35rem;
            flex-shrink: 0;
            border-radius: 0.6rem;
            color: var(--fi-insights-accent, currentColor);
            background: color-mix(in srgb, var(--fi-insights-accent, currentColor) 14%, transparent);
            border: 1px solid color-mix(in srgb, var(--fi-insights-accent, currentColor) 25%, transparent);
        }
        .fi-insights-section-copy {
            min-width: 0;
            flex: 1 1 auto;
        }
        .fi-insights-section-title-row {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            flex-wrap: wrap;
        }
        .fi-insights-section-title {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 700;
            line-height: 1.25;
            letter-spacing: -0.02em;
        }
        .fi-insights-section-desc {
            margin: 0.25rem 0 0;
            font-size: 0.9rem;
            line-height: 1.45;
            opacity: 0.72;
        }
        .fi-insights-section-count {
            display: inline-flex;
            align-items: center;
            padding: 0.12rem 0.55rem;
            border-radius: 9999px;
            font-size: 0.72rem;
            font-weight: 600;
            background: color-mix(in srgb, var(--fi-insights-accent, currentColor) 12%, transparent);
            border: 1px solid color-mix(in srgb, var(--fi-insights-accent, currentColor) 22%, transparent);
            opacity: 0.85;
        }
        .fi-insights-section-empty-hint {
            margin-top: 0.75rem;
            padding: 1rem 1.15rem;
            border-radius: 0.75rem;
            border: 1.5px dashed color-mix(in srgb, var(--fi-insights-accent, currentColor) 22%, transparent);
            font-size: 0.84rem;
            opacity: 0.68;
            text-align: center;
        }
        .fi-insights-section-controls {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            flex-shrink: 0;
        }
        .fi-insights-section-toolbar {
            display: inline-flex;
            align-items: center;
            gap: 0.15rem;
            padding: 0.2rem 0.3rem;
            border-radius: 0.5rem;
            border: 1px solid color-mix(in srgb, currentColor 14%, transparent);
            background: color-mix(in srgb, currentColor 4%, transparent);
            opacity: 0.65;
            transition: opacity 150ms ease, border-color 150ms ease;
        }
        .dark .fi-insights-section-toolbar {
            background: rgb(15 23 42 / 0.7);
            border-color: rgb(255 255 255 / 0.12);
        }
        .fi-insights-section:hover .fi-insights-section-toolbar,
        .fi-insights-section:focus-within .fi-insights-section-toolbar {
            opacity: 1;
        }
        .fi-insights-chevron {
            display: inline-flex;
            transition: transform 180ms ease;
        }
        .fi-insights-chevron.is-collapsed {
            transform: rotate(-90deg);
        }

        /* Floating manager toolbar on each card */
        .fi-insights-toolbar {
            position: absolute;
            top: 0.5rem;
            right: 0.6rem;
            z-index: 10;
            display: inline-flex;
            align-items: center;
            gap: 0.15rem;
            padding: 0.2rem 0.3rem;
            border-radius: 0.5rem;
            border: 1px solid color-mix(in srgb, currentColor 16%, transparent);
            background: color-mix(in srgb, Canvas 88%, transparent);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            box-shadow: 0 2px 10px rgb(0 0 0 / 0.12);
            opacity: 0.45;
            transition: opacity 150ms ease, box-shadow 150ms ease;
        }
        .dark .fi-insights-toolbar {
            background: color-mix(in srgb, #0f172a 86%, transparent);
            border-color: rgb(255 255 255 / 0.14);
        }
        .fi-insights-card:hover .fi-insights-toolbar,
        .fi-insights-card:focus-within .fi-insights-toolbar,
        .fi-insights-toolbar:hover {
            opacity: 1;
            box-shadow: 0 4px 14px rgb(0 0 0 / 0.18);
        }
        @media (hover: none) {
            .fi-insights-toolbar,
            .fi-insights-section-toolbar { opacity: 0.92; }
        }
        .fi-insights-grip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.2rem;
            opacity: 0.65;
            cursor: grab;
        }
        .fi-insights-grip:active { cursor: grabbing; }
        .fi-insights-toolbar-actions {
            display: inline-flex;
            align-items: center;
            gap: 0.15rem;
            flex-shrink: 0;
        }
        .fi-insights-tool-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 1.65rem;
            height: 1.65rem;
            padding: 0 0.28rem;
            border: 0;
            border-radius: 0.375rem;
            background: transparent;
            color: inherit;
            cursor: pointer;
            opacity: 0.82;
            transition: background-color 120ms ease, color 120ms ease, opacity 120ms ease;
        }
        .fi-insights-tool-btn:hover,
        .fi-insights-tool-btn:focus-visible {
            opacity: 1;
            background: color-mix(in srgb, currentColor 12%, transparent);
            outline: none;
        }
        .fi-insights-span-btn {
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.01em;
            padding: 0 0.4rem;
        }
        .fi-insights-tool-danger:hover,
        .fi-insights-tool-danger:focus-visible {
            color: #ef4444;
            background: rgb(239 68 68 / 0.15);
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

        /* Container-aware responsive grid: adapts to the actual dashboard pane width (accounting for sidebar). */
        @container fi-insights (min-width: 560px) and (max-width: 899px) {
            .fi-insights-grid { grid-template-columns: repeat(6, minmax(0, 1fr)); }
            .fi-insights-card[data-span="3"],
            .fi-insights-card[data-span="4"] { grid-column: span 3 / span 3; }
            .fi-insights-card[data-span="6"],
            .fi-insights-card[data-span="8"],
            .fi-insights-card[data-span="12"] { grid-column: 1 / -1; }
        }

        @container fi-insights (min-width: 900px) and (max-width: 1159px) {
            .fi-insights-grid { grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 1.5rem; }
            .fi-insights-card { grid-column: span var(--fi-insights-span) / span var(--fi-insights-span); }
            /* Keep ¼ cards comfortable (3-across) until the pane is wide enough for 4-across. */
            .fi-insights-card[data-span="3"] { grid-column: span 4 / span 4; }
            .fi-insights-card[data-span="12"] { grid-column: 1 / -1; }
        }

        @container fi-insights (min-width: 1160px) {
            .fi-insights-grid { grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 1.5rem; }
            .fi-insights-card { grid-column: span var(--fi-insights-span) / span var(--fi-insights-span); }
            .fi-insights-card[data-span="12"] { grid-column: 1 / -1; }
        }

        /* Graceful fallback for older browsers without CSS Container Queries. */
        @supports not (container-type: inline-size) {
            @media (min-width: 1024px) {
                .fi-insights-grid { grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 1.5rem; }
                .fi-insights-card { grid-column: span var(--fi-insights-span) / span var(--fi-insights-span); }
            }
        }
    </style>
</x-filament-panels::page>
