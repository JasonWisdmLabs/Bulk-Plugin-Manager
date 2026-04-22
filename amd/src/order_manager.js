// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Bulk Plugin Manager — frontend order management and plugin selection.
 *
 * @module    tool_bulkpluginmanager/order_manager
 * @copyright 2024 WisdmLabs
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['jquery'], function($) {

    'use strict';

    // -------------------------------------------------------------------------
    // Plugin selector (Step 1 — uninstall)
    // -------------------------------------------------------------------------

    var PluginSelector = {

        additionalOnly: false,

        init: function() {
            this.bindSearch();
            this.bindTypeFilter();
            this.bindAdditionalFilter();
            this.bindSelectAll();
            this.bindRowCheckboxCount();
        },

        /**
         * Re-apply all active filters to every row.
         * Single source of truth — called whenever any filter changes.
         */
        applyFilters: function() {
            var term       = $('#bpm-search').val().toLowerCase().trim();
            var type       = $('#bpm-type-filter').val();
            var addlOnly   = PluginSelector.additionalOnly;

            $('#bpm-plugin-table tbody tr').each(function() {
                var row       = $(this);
                var text      = row.text().toLowerCase();
                var rowtype   = row.data('type');
                var isStd     = row.data('standard') === 1 || row.data('standard') === '1';

                var visible = true;
                if (term     && text.indexOf(term) === -1) { visible = false; }
                if (type     && rowtype !== type)           { visible = false; }
                if (addlOnly && isStd)                      { visible = false; }

                row.toggle(visible);
            });
        },

        bindSearch: function() {
            $('#bpm-search').on('input', function() {
                PluginSelector.applyFilters();
            });
        },

        bindTypeFilter: function() {
            $('#bpm-type-filter').on('change', function() {
                PluginSelector.applyFilters();
            });
        },

        bindAdditionalFilter: function() {
            $('#bpm-filter-additional').on('click', function() {
                PluginSelector.additionalOnly = !PluginSelector.additionalOnly;
                var active = PluginSelector.additionalOnly;
                $(this)
                    .attr('aria-pressed', active ? 'true' : 'false')
                    .toggleClass('btn-outline-secondary', !active)
                    .toggleClass('btn-secondary', active);
                PluginSelector.applyFilters();
            });
        },

        bindSelectAll: function() {
            $('#bpm-select-all').on('change', function() {
                var checked = $(this).prop('checked');
                $('#bpm-plugin-table tbody tr:visible input[type="checkbox"]:not(:disabled)')
                    .prop('checked', checked);
                // If checking all, also auto-select all dependents for conditional plugins.
                if (checked) {
                    PluginSelector.autoSelectDependents();
                }
                PluginSelector.updateCount();
            });

            $('#bpm-deselect-all').on('click', function(e) {
                e.preventDefault();
                $('#bpm-plugin-table tbody tr input[type="checkbox"]').prop('checked', false);
                $('#bpm-select-all').prop('checked', false);
                PluginSelector.updateCount();
            });
        },

        bindRowCheckboxCount: function() {
            $('#bpm-plugin-table').on('change', 'input[type="checkbox"].bpm-cb-conditional', function() {
                // This is a "conditionally uninstallable" plugin (e.g. edwiser page builder).
                // When checked, auto-select all of its blocking dependents.
                if ($(this).prop('checked')) {
                    PluginSelector.selectBlockedBy($(this));
                }
                PluginSelector.updateCount();
            });

            $('#bpm-plugin-table').on('change', 'input[type="checkbox"]:not(.bpm-cb-conditional)', function() {
                // When a regular plugin is unchecked, check whether any conditional plugin
                // in the selection depends on it being selected — and warn/uncheck that conditional.
                if (!$(this).prop('checked')) {
                    PluginSelector.handleDependentUncheck($(this));
                }
                PluginSelector.updateCount();
            });
        },

        /**
         * Given a conditional plugin's checkbox, auto-check every component listed in
         * its data-blocked-by attribute (its currently-installed dependents).
         */
        selectBlockedBy: function($cb) {
            var raw = $cb.attr('data-blocked-by') || '[]';
            var deps;
            try { deps = JSON.parse(raw); } catch(e) { deps = []; }

            deps.forEach(function(dep) {
                var $depCb = $('#bpm-plugin-table tbody tr[data-component="' + dep + '"] input[type="checkbox"]');
                if ($depCb.length && !$depCb.prop('disabled') && !$depCb.prop('checked')) {
                    $depCb.prop('checked', true).trigger('change');
                }
            });
        },

        /**
         * When a regular plugin checkbox is unchecked, find any conditional plugins
         * whose blocked-by list includes it and uncheck those too (they can't proceed
         * without this dependent being selected).
         */
        handleDependentUncheck: function($uncheckedCb) {
            var uncheckedComponent = $uncheckedCb.closest('tr').data('component');

            $('#bpm-plugin-table tbody .bpm-cb-conditional:checked').each(function() {
                var raw = $(this).attr('data-blocked-by') || '[]';
                var deps;
                try { deps = JSON.parse(raw); } catch(e) { deps = []; }

                if (deps.indexOf(uncheckedComponent) !== -1) {
                    $(this).prop('checked', false);
                }
            });
        },

        /** Auto-select dependents for all currently-checked conditional plugins. */
        autoSelectDependents: function() {
            $('#bpm-plugin-table tbody .bpm-cb-conditional:checked').each(function() {
                PluginSelector.selectBlockedBy($(this));
            });
        },

        updateCount: function() {
            var count = $('#bpm-plugin-table tbody input[type="checkbox"]:checked').length;
            $('#bpm-selected-count').text(count);
            $('#bpm-btn-next').prop('disabled', count === 0);
        }
    };

    // -------------------------------------------------------------------------
    // Order list (Step 2 — drag-and-drop reordering)
    // -------------------------------------------------------------------------

    var OrderManager = {

        listEl: null,
        mode: 'install', // 'install' or 'uninstall'
        dragSrc: null,

        init: function(listId, mode) {
            this.listEl = document.getElementById(listId);
            this.mode   = mode || 'install';

            if (!this.listEl) {
                return;
            }

            this.enableDragDrop();
            this.bindMoveButtons();
            this.bindAutoSort();
            this.syncOrderInput();
            this.validateDependencies();
        },

        enableDragDrop: function() {
            var self = this;
            var items = this.listEl.querySelectorAll('li[data-component]');

            items.forEach(function(item) {
                item.setAttribute('draggable', 'true');

                item.addEventListener('dragstart', function(e) {
                    self.dragSrc = item;
                    item.classList.add('bpm-dragging');
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', item.dataset.component);
                });

                item.addEventListener('dragend', function() {
                    item.classList.remove('bpm-dragging');
                    self.listEl.querySelectorAll('li').forEach(function(el) {
                        el.classList.remove('bpm-drag-over');
                    });
                    self.syncOrderInput();
                    self.validateDependencies();
                });

                item.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    if (item !== self.dragSrc) {
                        item.classList.add('bpm-drag-over');
                    }
                });

                item.addEventListener('dragleave', function() {
                    item.classList.remove('bpm-drag-over');
                });

                item.addEventListener('drop', function(e) {
                    e.preventDefault();
                    item.classList.remove('bpm-drag-over');

                    if (self.dragSrc && self.dragSrc !== item) {
                        // Determine relative position.
                        var list    = self.listEl;
                        var items   = Array.from(list.querySelectorAll('li[data-component]'));
                        var srcIdx  = items.indexOf(self.dragSrc);
                        var dstIdx  = items.indexOf(item);

                        if (srcIdx < dstIdx) {
                            list.insertBefore(self.dragSrc, item.nextSibling);
                        } else {
                            list.insertBefore(self.dragSrc, item);
                        }

                        self.syncOrderInput();
                        self.validateDependencies();
                    }
                });
            });
        },

        bindMoveButtons: function() {
            var self = this;

            $(this.listEl).on('click', '.bpm-move-up', function() {
                var li   = $(this).closest('li');
                var prev = li.prev('li[data-component]');
                if (prev.length) {
                    prev.before(li);
                    self.syncOrderInput();
                    self.validateDependencies();
                }
            });

            $(this.listEl).on('click', '.bpm-move-down', function() {
                var li   = $(this).closest('li');
                var next = li.next('li[data-component]');
                if (next.length) {
                    next.after(li);
                    self.syncOrderInput();
                    self.validateDependencies();
                }
            });
        },

        bindAutoSort: function() {
            var self = this;

            $('#bpm-auto-sort').on('click', function(e) {
                e.preventDefault();
                self.autoSort();
            });
        },

        /**
         * Re-sort the list items by topological dependency order.
         * Reads dependency data from data-deps attributes.
         */
        autoSort: function() {
            var items      = Array.from(this.listEl.querySelectorAll('li[data-component]'));
            var components = items.map(function(el) { return el.dataset.component; });
            var depmap     = {};

            items.forEach(function(el) {
                var raw  = el.dataset.deps || '[]';
                var deps;
                try { deps = JSON.parse(raw); } catch(e) { deps = []; }
                // Keep only deps that are in our selection set.
                depmap[el.dataset.component] = deps.filter(function(d) { return components.indexOf(d) !== -1; });
            });

            var sorted = this.mode === 'uninstall'
                ? this.topoSortReverse(components, depmap)
                : this.topoSort(components, depmap);

            // Re-append items in sorted order.
            var self = this;
            sorted.forEach(function(component) {
                var el = self.listEl.querySelector('li[data-component="' + CSS.escape(component) + '"]');
                if (el) {
                    self.listEl.appendChild(el);
                }
            });

            this.syncOrderInput();
            this.validateDependencies();
        },

        /** Topological sort — dependencies first (install order). */
        topoSort: function(components, depmap) {
            var indegree = {};
            var graph    = {};
            components.forEach(function(c) { indegree[c] = 0; graph[c] = []; });

            components.forEach(function(c) {
                (depmap[c] || []).forEach(function(dep) {
                    if (graph[dep] !== undefined) {
                        graph[dep].push(c);
                        indegree[c]++;
                    }
                });
            });

            var queue  = components.filter(function(c) { return indegree[c] === 0; });
            var sorted = [];

            while (queue.length) {
                var node = queue.shift();
                sorted.push(node);
                (graph[node] || []).forEach(function(dep) {
                    indegree[dep]--;
                    if (indegree[dep] === 0) { queue.push(dep); }
                });
            }

            // Append any remaining (circular deps).
            components.forEach(function(c) {
                if (sorted.indexOf(c) === -1) { sorted.push(c); }
            });

            return sorted;
        },

        /** Reverse topological sort — dependents first (uninstall order). */
        topoSortReverse: function(components, depmap) {
            return this.topoSort(components, depmap).reverse();
        },

        /** Write the current order to the hidden JSON input. */
        syncOrderInput: function() {
            var order = Array.from(this.listEl.querySelectorAll('li[data-component]'))
                .map(function(el) { return el.dataset.component; });
            var input = document.getElementById('bpm-order-input');
            if (input) {
                input.value = JSON.stringify(order);
            }
        },

        /** Highlight items whose position violates dependency constraints. */
        validateDependencies: function() {
            var items     = Array.from(this.listEl.querySelectorAll('li[data-component]'));
            var positions = {};
            items.forEach(function(el, idx) { positions[el.dataset.component] = idx; });

            items.forEach(function(el) {
                var raw  = el.dataset.deps || '[]';
                var deps;
                try { deps = JSON.parse(raw); } catch(e) { deps = []; }

                var hasViolation = deps.some(function(dep) {
                    if (positions[dep] === undefined) { return false; }
                    if (el.dataset.mode === 'uninstall' || this.mode === 'uninstall') {
                        // For uninstall, dep must come AFTER the dependent.
                        return positions[dep] < positions[el.dataset.component];
                    }
                    // For install, dep must come BEFORE the plugin.
                    return positions[dep] > positions[el.dataset.component];
                }.bind(this));

                el.querySelector('.bpm-dep-warning').style.display = hasViolation ? '' : 'none';
            }.bind(this));
        }
    };

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    return {
        initSelector: function() {
            PluginSelector.init();
        },

        initOrderList: function(listId, mode) {
            OrderManager.init(listId, mode);
        }
    };
});
