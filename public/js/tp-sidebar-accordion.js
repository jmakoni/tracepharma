/**
 * App panel sidebar accordion: only one nav group expanded at a time.
 * Patches Filament's Alpine $store.sidebar after it initializes.
 */
(function () {
    function allGroupLabels() {
        return Array.from(
            document.querySelectorAll(
                '.fi-main-sidebar .fi-sidebar-group[data-group-label]',
            ),
        )
            .map(function (el) {
                return el.getAttribute('data-group-label');
            })
            .filter(Boolean);
    }

    function activeGroupLabel() {
        var active = document.querySelector(
            '.fi-main-sidebar .fi-sidebar-group.fi-active[data-group-label]',
        );

        return active ? active.getAttribute('data-group-label') : null;
    }

    function normalizeCollapsed(store) {
        if (!Array.isArray(store.collapsedGroups)) {
            store.collapsedGroups = [];
        }
    }

    function ensureAccordionState(store) {
        if (!store) {
            return;
        }

        normalizeCollapsed(store);

        var labels = allGroupLabels();

        if (labels.length === 0) {
            return;
        }

        var open = activeGroupLabel() || 'Operations';

        if (labels.indexOf(open) === -1) {
            store.collapsedGroups = labels.slice();

            return;
        }

        store.collapsedGroups = labels.filter(function (label) {
            return label !== open;
        });
    }

    function patchStore(store) {
        if (!store || store.__tpAccordionPatched) {
            return store;
        }

        normalizeCollapsed(store);

        store.toggleCollapsedGroup = function (group) {
            normalizeCollapsed(this);

            var labels = allGroupLabels();
            var isCollapsed = this.collapsedGroups.includes(group);

            if (isCollapsed) {
                // Opening this group — collapse every other group.
                this.collapsedGroups = labels.filter(function (label) {
                    return label !== group;
                });
            } else {
                // Closing this group — allow all collapsed.
                this.collapsedGroups = this.collapsedGroups.concat(group);
            }
        };

        store.__tpAccordionPatched = true;

        return store;
    }

    function tryPatch() {
        if (typeof Alpine === 'undefined' || typeof Alpine.store !== 'function') {
            return false;
        }

        var store;

        try {
            store = Alpine.store('sidebar');
        } catch (e) {
            return false;
        }

        if (!store) {
            return false;
        }

        patchStore(store);
        ensureAccordionState(store);

        return true;
    }

    function boot() {
        if (tryPatch()) {
            return;
        }

        document.addEventListener('alpine:initialized', function () {
            tryPatch();
        });
    }

    document.addEventListener('livewire:navigated', function () {
        tryPatch();
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
