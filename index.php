<?php
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
 * Bulk Plugin Manager — main controller.
 *
 * URL parameters:
 *   tab    = 'uninstall' | 'install'   (default: 'uninstall')
 *   step   = 1 | 2 | 3                 (default: 1)
 *   action = 'execute'                 (triggers the actual operation)
 *
 * @package   tool_bulkpluginmanager
 * @copyright 2024 WisdmLabs
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use tool_bulkpluginmanager\manager;
use tool_bulkpluginmanager\dependency_resolver;

admin_externalpage_setup('tool_bulkpluginmanager');
require_capability('moodle/site:config', context_system::instance());

$tab    = optional_param('tab',    'uninstall', PARAM_ALPHA);
$step   = optional_param('step',   1,           PARAM_INT);
$action = optional_param('action', '',          PARAM_ALPHA);

// Normalise tab.
if (!in_array($tab, ['uninstall', 'install'])) {
    $tab = 'uninstall';
}

$PAGE->requires->css('/admin/tool/bulkpluginmanager/styles/styles.css');

$baseurl = new moodle_url('/admin/tool/bulkpluginmanager/index.php');

// ============================================================================
// POST: Uninstall — step 1 → step 2 (build order list from selected plugins).
// ============================================================================
if ($tab === 'uninstall' && $step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $selected = optional_param_array('plugins', [], PARAM_COMPONENT);
    $selected = array_filter($selected);

    if (empty($selected)) {
        redirect(
            new moodle_url($baseurl, ['tab' => 'uninstall', 'step' => 1]),
            get_string('no_plugins_selected', 'tool_bulkpluginmanager'),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }

    // Validate each component exists.
    $pluginman = core_plugin_manager::instance();
    $valid     = [];
    foreach ($selected as $component) {
        if ($pluginman->get_plugin_info($component) !== null) {
            $valid[] = $component;
        }
    }

    // For each "conditionally uninstallable" plugin in the selection, verify that all
    // of its blocking dependents are ALSO in the selection. Without them the uninstall
    // will fail mid-way. The JS auto-selects them, but we guard here too.
    $selectedset = array_flip($valid);
    foreach ($valid as $component) {
        $info     = $pluginman->get_plugin_info($component);
        $blockers = $pluginman->other_plugins_that_require($component);
        // Filter to external (non-subplugin) dependents that are still installed.
        $subpluginkeys = array_keys($pluginman->get_subplugins_of_plugin($component));
        $external      = array_filter($blockers, fn($r) => !in_array($r, $subpluginkeys));
        $missing       = array_filter($external, fn($r) => !isset($selectedset[$r]));

        if (!empty($missing)) {
            $msgdata          = new stdClass();
            $msgdata->plugin  = $component;
            $msgdata->missing = implode(', ', $missing);
            redirect(
                new moodle_url($baseurl, ['tab' => 'uninstall', 'step' => 1]),
                get_string('missing_dependents', 'tool_bulkpluginmanager', $msgdata),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }
    }

    $depmap = manager::build_dependency_map($valid);
    $sorted = dependency_resolver::sort_for_uninstall($valid, $depmap);

    // Store in session for step 3.
    $SESSION->tool_bulkpluginmanager = [
        'tab'     => 'uninstall',
        'plugins' => $sorted,
        'depmap'  => $depmap,
    ];

    // Render step 2.
    render_step2_uninstall($sorted, $depmap, $OUTPUT, $PAGE);
    exit;
}

// ============================================================================
// POST: Uninstall — step 3 (confirmation from step 2, possibly reordered).
// ============================================================================
if ($tab === 'uninstall' && $step === 3 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $orderjson = optional_param('order', '', PARAM_RAW);
    $ordered   = !empty($orderjson) ? json_decode($orderjson, true) : [];

    if (empty($ordered) || !is_array($ordered)) {
        // Fall back to session.
        $ordered = $SESSION->tool_bulkpluginmanager['plugins'] ?? [];
    }

    // Sanitise each component name.
    $ordered = array_filter(array_map('clean_param', $ordered, array_fill(0, count($ordered), PARAM_COMPONENT)));

    $depmap = $SESSION->tool_bulkpluginmanager['depmap'] ?? manager::build_dependency_map($ordered);

    $violations = dependency_resolver::validate_order($ordered, $depmap, 'uninstall');

    // Update session with potentially reordered list.
    $SESSION->tool_bulkpluginmanager = [
        'tab'     => 'uninstall',
        'plugins' => $ordered,
        'depmap'  => $depmap,
    ];

    render_step3_uninstall($ordered, $depmap, $violations, $OUTPUT, $PAGE);
    exit;
}

// ============================================================================
// POST: Uninstall — execute.
// ============================================================================
if ($tab === 'uninstall' && $action === 'execute' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $orderjson = optional_param('order', '', PARAM_RAW);
    $ordered   = !empty($orderjson) ? json_decode($orderjson, true) : [];
    $ordered   = array_filter(array_map('clean_param', $ordered, array_fill(0, count($ordered), PARAM_COMPONENT)));

    if (empty($ordered)) {
        redirect($baseurl, get_string('no_plugins_selected', 'tool_bulkpluginmanager'), null, \core\output\notification::NOTIFY_WARNING);
    }

    render_execute_uninstall($ordered, $OUTPUT, $PAGE);
    exit;
}

// ============================================================================
// POST: Install — step 1 → step 2 (upload ZIPs and parse).
// ============================================================================
if ($tab === 'install' && $step === 2 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    if (!manager::is_install_allowed()) {
        redirect(
            new moodle_url($baseurl, ['tab' => 'install']),
            get_string('install_disabled', 'tool_bulkpluginmanager'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    if (empty($_FILES['zipfiles']['name'][0]) && empty($_FILES['zipfiles']['name'])) {
        redirect(
            new moodle_url($baseurl, ['tab' => 'install']),
            get_string('no_files_uploaded', 'tool_bulkpluginmanager'),
            null,
            \core\output\notification::NOTIFY_WARNING
        );
    }

    $uploads = manager::store_uploaded_zips($_FILES['zipfiles']);

    $parsed  = [];
    $errors  = [];
    foreach ($uploads as $upload) {
        if (!empty($upload->error)) {
            $errors[] = "{$upload->original_name}: {$upload->error}";
        } else {
            $parsed[] = $upload;
        }
    }

    if (empty($parsed)) {
        redirect(
            new moodle_url($baseurl, ['tab' => 'install']),
            implode('<br>', $errors),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }

    // Build dependency map from parsed ZIPs.
    $components = array_map(fn($u) => $u->info->component, $parsed);
    $depmap     = [];
    foreach ($parsed as $upload) {
        $depmap[$upload->info->component] = array_values(
            array_filter($upload->info->dependencies, fn($d) => in_array($d, $components))
        );
    }

    $sorted      = dependency_resolver::sort_for_install($components, $depmap);
    $sortedpaths = [];
    $pathmap     = [];
    foreach ($parsed as $upload) {
        $pathmap[$upload->info->component] = $upload->path;
    }
    foreach ($sorted as $comp) {
        if (isset($pathmap[$comp])) {
            $sortedpaths[$comp] = $pathmap[$comp];
        }
    }

    // Store in session.
    $SESSION->tool_bulkpluginmanager = [
        'tab'     => 'install',
        'plugins' => $sorted,
        'depmap'  => $depmap,
        'paths'   => $sortedpaths,
        'infos'   => array_combine(
            array_column($parsed, null),
            array_map(fn($u) => $u->info, $parsed)
        ),
    ];
    // Re-index infos by component.
    $infomap = [];
    foreach ($parsed as $upload) {
        $infomap[$upload->info->component] = $upload->info;
    }
    $SESSION->tool_bulkpluginmanager['infos'] = $infomap;

    render_step2_install($sorted, $depmap, $infomap, $sortedpaths, $errors, $OUTPUT, $PAGE);
    exit;
}

// ============================================================================
// POST: Install — step 3 (confirmation from step 2, possibly reordered).
// ============================================================================
if ($tab === 'install' && $step === 3 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $orderjson = optional_param('order', '', PARAM_RAW);
    $ordered   = !empty($orderjson) ? json_decode($orderjson, true) : [];
    $ordered   = array_filter(array_map('clean_param', $ordered, array_fill(0, count($ordered), PARAM_COMPONENT)));

    $session  = $SESSION->tool_bulkpluginmanager ?? [];
    $depmap   = $session['depmap']  ?? [];
    $paths    = $session['paths']   ?? [];
    $infomap  = $session['infos']   ?? [];

    $violations = dependency_resolver::validate_order($ordered, $depmap, 'install');

    // Update session with reordered list.
    $SESSION->tool_bulkpluginmanager['plugins'] = $ordered;

    render_step3_install($ordered, $depmap, $infomap, $paths, $violations, $OUTPUT, $PAGE);
    exit;
}

// ============================================================================
// POST: Install — execute (deploy ZIPs in order).
// ============================================================================
if ($tab === 'install' && $action === 'execute' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();

    $orderjson = optional_param('order', '', PARAM_RAW);
    $ordered   = !empty($orderjson) ? json_decode($orderjson, true) : [];
    $ordered   = array_filter(array_map('clean_param', $ordered, array_fill(0, count($ordered), PARAM_COMPONENT)));

    $session = $SESSION->tool_bulkpluginmanager ?? [];
    $paths   = $session['paths']   ?? [];
    $infomap = $session['infos']   ?? [];

    render_execute_install($ordered, $paths, $infomap, $OUTPUT, $PAGE);
    exit;
}

// ============================================================================
// Default: render step 1 (plugin selector or file upload).
// ============================================================================
echo $OUTPUT->header();
echo render_tabs($tab, $OUTPUT);

if ($tab === 'uninstall') {
    render_step1_uninstall($OUTPUT, $PAGE);
} else {
    render_step1_install($OUTPUT, $PAGE);
}

echo $OUTPUT->footer();

// ============================================================================
// Render functions
// ============================================================================

/**
 * Render the tab bar.
 */
function render_tabs(string $activetab, \renderer_base $OUTPUT): string {
    $baseurl = new moodle_url('/admin/tool/bulkpluginmanager/index.php');

    $tabs = [
        new tabobject('uninstall', new moodle_url($baseurl, ['tab' => 'uninstall']), get_string('tab_uninstall', 'tool_bulkpluginmanager')),
        new tabobject('install',   new moodle_url($baseurl, ['tab' => 'install']),   get_string('tab_install',   'tool_bulkpluginmanager')),
    ];

    return $OUTPUT->tabtree($tabs, $activetab);
}

// ----------------------------------------------------------------------------
// UNINSTALL — Step 1: Plugin selector
// ----------------------------------------------------------------------------

function render_step1_uninstall(\renderer_base $OUTPUT, \moodle_page $PAGE): void {
    global $CFG;

    $PAGE->requires->js_call_amd('tool_bulkpluginmanager/order_manager', 'initSelector');

    $plugins   = manager::get_all_plugins();
    $pluginman = core_plugin_manager::instance();
    $baseurl   = new moodle_url('/admin/tool/bulkpluginmanager/index.php');

    // Build unique type list for filter dropdown.
    $types = array_unique(array_column((array) $plugins, 'type'));
    sort($types);

    echo $OUTPUT->heading(get_string('uninstall_select_heading', 'tool_bulkpluginmanager'), 2);
    echo html_writer::tag('p', get_string('uninstall_select_desc', 'tool_bulkpluginmanager'), ['class' => 'text-muted']);

    // Search and filter controls.
    echo html_writer::start_div('bpm-controls mb-3 d-flex flex-wrap gap-2 align-items-center');
    echo html_writer::tag('input', '', [
        'type'        => 'text',
        'id'          => 'bpm-search',
        'class'       => 'form-control bpm-search-input',
        'placeholder' => get_string('search_plugins', 'tool_bulkpluginmanager'),
        'style'       => 'max-width:280px',
    ]);

    // Type filter select.
    $typeopts = ['' => get_string('all_types', 'tool_bulkpluginmanager')];
    foreach ($types as $type) {
        $typeopts[$type] = $type;
    }
    echo html_writer::select($typeopts, 'bpm-type-filter', '', false, ['id' => 'bpm-type-filter', 'class' => 'custom-select bpm-type-select', 'style' => 'max-width:180px']);

    echo html_writer::tag('button', get_string('filter_additional', 'tool_bulkpluginmanager'), [
        'type'            => 'button',
        'id'              => 'bpm-filter-additional',
        'class'           => 'btn btn-outline-secondary btn-sm',
        'aria-pressed'    => 'false',
        'title'           => get_string('filter_additional_active', 'tool_bulkpluginmanager'),
    ]);

    echo html_writer::span(
        html_writer::tag('span', '0', ['id' => 'bpm-selected-count']) . ' selected',
        'bpm-count-badge badge badge-primary ml-2 align-self-center'
    );
    echo html_writer::end_div();

    // Plugin table.
    $formurl = new moodle_url($baseurl, ['tab' => 'uninstall', 'step' => 2]);
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $formurl->out(false), 'id' => 'bpm-uninstall-form']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

    echo html_writer::start_tag('table', ['class' => 'generaltable table table-striped table-sm bpm-plugin-table', 'id' => 'bpm-plugin-table']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', html_writer::checkbox('selectall', '1', false, '', ['id' => 'bpm-select-all', 'title' => get_string('select_all', 'tool_bulkpluginmanager')]), ['style' => 'width:40px']);
    echo html_writer::tag('th', get_string('plugin_name',      'tool_bulkpluginmanager'));
    echo html_writer::tag('th', get_string('plugin_component', 'tool_bulkpluginmanager'), ['class' => 'd-none d-md-table-cell']);
    echo html_writer::tag('th', get_string('plugin_type',      'tool_bulkpluginmanager'), ['class' => 'd-none d-sm-table-cell']);
    echo html_writer::tag('th', get_string('plugin_version',   'tool_bulkpluginmanager'), ['class' => 'd-none d-lg-table-cell']);
    echo html_writer::tag('th', get_string('plugin_requires',  'tool_bulkpluginmanager'), ['class' => 'd-none d-xl-table-cell']);
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($plugins as $plugin) {
        $canuninstall   = $plugin->can_uninstall;
        $conditional    = $plugin->can_uninstall_conditionally ?? false;
        $blockedby      = $plugin->blocked_by ?? [];

        // Three states:
        //   can_uninstall=true                      → normal selectable row
        //   can_uninstall_conditionally=true        → selectable but must also pick dependents
        //   neither                                 → disabled (core-locked)
        $selectable = $canuninstall || $conditional;

        $rowattrs = [
            'data-type'       => $plugin->type,
            'data-component'  => $plugin->component,
            'data-standard'   => $plugin->is_standard ? '1' : '0',
            'data-blocked-by' => json_encode($blockedby),
        ];
        if (!$selectable) {
            $rowattrs['class'] = 'text-muted bpm-row-locked';
        } elseif ($conditional) {
            $rowattrs['class'] = 'bpm-row-conditional';
        }

        echo html_writer::start_tag('tr', $rowattrs);

        // Checkbox cell.
        $cbattrs = ['value' => $plugin->component, 'name' => 'plugins[]'];
        if (!$selectable) {
            $cbattrs['disabled'] = 'disabled';
            $cbattrs['title']    = get_string('cannot_uninstall_reason', 'tool_bulkpluginmanager');
        } elseif ($conditional) {
            $cbattrs['class'] = 'bpm-cb-conditional';
            $cbattrs['title'] = get_string('blocked_by_dependents', 'tool_bulkpluginmanager');
            // Encode which dependent components must be co-selected.
            $cbattrs['data-blocked-by'] = json_encode($blockedby);
        }
        echo html_writer::tag('td', html_writer::checkbox('plugins[]', $plugin->component, false, '', $cbattrs));

        // Name column.
        $namecell = html_writer::tag('strong', s($plugin->displayname));

        if (!$selectable) {
            $namecell .= ' ' . html_writer::tag('span',
                get_string('cannot_uninstall', 'tool_bulkpluginmanager'),
                ['class' => 'badge badge-secondary']
            );
        } elseif ($conditional) {
            $namecell .= ' ' . html_writer::tag('span',
                get_string('blocked_by_dependents', 'tool_bulkpluginmanager'),
                ['class' => 'badge badge-warning', 'title' => get_string('blocked_by_dependents', 'tool_bulkpluginmanager')]
            );
        }

        if (!empty($plugin->dependencies)) {
            $namecell .= html_writer::start_div('bpm-deps mt-1');
            foreach ($plugin->dependencies as $dep) {
                $namecell .= html_writer::tag('span', s($dep), [
                    'class' => 'badge badge-info mr-1',
                    'title' => get_string('depends_on', 'tool_bulkpluginmanager'),
                ]);
            }
            $namecell .= html_writer::end_div();
        }
        echo html_writer::tag('td', $namecell);

        echo html_writer::tag('td', html_writer::tag('code', s($plugin->component)), ['class' => 'd-none d-md-table-cell']);
        echo html_writer::tag('td', s($plugin->type),    ['class' => 'd-none d-sm-table-cell']);
        echo html_writer::tag('td', s($plugin->version), ['class' => 'd-none d-lg-table-cell']);

        // Required-by cell.
        $reqby = '';
        if (!empty($plugin->requiredby)) {
            foreach ($plugin->requiredby as $rb) {
                $reqby .= html_writer::tag('span', s($rb), ['class' => 'badge badge-warning mr-1']);
            }
        }
        echo html_writer::tag('td', $reqby ?: '—', ['class' => 'd-none d-xl-table-cell']);

        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');

    // Buttons.
    echo html_writer::start_div('bpm-form-actions mt-3 d-flex align-items-center gap-2');
    echo html_writer::tag('button', get_string('deselect_all', 'tool_bulkpluginmanager'), ['type' => 'button', 'id' => 'bpm-deselect-all', 'class' => 'btn btn-secondary']);
    echo html_writer::tag('button', get_string('btn_review_order', 'tool_bulkpluginmanager'), [
        'type'     => 'submit',
        'id'       => 'bpm-btn-next',
        'class'    => 'btn btn-primary',
        'disabled' => 'disabled',
    ]);
    echo html_writer::end_div();

    echo html_writer::end_tag('form');
}

// ----------------------------------------------------------------------------
// UNINSTALL — Step 2: Review order (drag-and-drop)
// ----------------------------------------------------------------------------

function render_step2_uninstall(array $sorted, array $depmap, \renderer_base $OUTPUT, \moodle_page $PAGE): void {
    $PAGE->requires->js_call_amd('tool_bulkpluginmanager/order_manager', 'initOrderList', ['bpm-order-list', 'uninstall']);

    $baseurl   = new moodle_url('/admin/tool/bulkpluginmanager/index.php');
    $pluginman = core_plugin_manager::instance();

    echo $OUTPUT->header();
    echo render_tabs('uninstall', $OUTPUT);
    echo $OUTPUT->heading(get_string('uninstall_order_heading', 'tool_bulkpluginmanager'), 2);
    echo html_writer::tag('p', get_string('uninstall_order_desc', 'tool_bulkpluginmanager'), ['class' => 'text-muted']);

    $formurl = new moodle_url($baseurl, ['tab' => 'uninstall', 'step' => 3]);
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $formurl->out(false), 'id' => 'bpm-order-form']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',    'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'order',      'value' => json_encode($sorted), 'id' => 'bpm-order-input']);

    echo html_writer::tag('button', get_string('btn_auto_sort', 'tool_bulkpluginmanager'), [
        'type'  => 'button',
        'id'    => 'bpm-auto-sort',
        'class' => 'btn btn-outline-secondary btn-sm mb-3',
    ]);

    echo render_order_list($sorted, $depmap, $pluginman, 'uninstall');

    echo html_writer::start_div('bpm-form-actions mt-3 d-flex gap-2');
    echo html_writer::tag('a', get_string('btn_back', 'tool_bulkpluginmanager'), [
        'href'  => (new moodle_url($baseurl, ['tab' => 'uninstall']))->out(false),
        'class' => 'btn btn-secondary',
    ]);
    echo html_writer::tag('button', get_string('btn_confirm_uninstall', 'tool_bulkpluginmanager'), ['type' => 'submit', 'class' => 'btn btn-danger']);
    echo html_writer::end_div();

    echo html_writer::end_tag('form');
    echo $OUTPUT->footer();
}

// ----------------------------------------------------------------------------
// UNINSTALL — Step 3: Confirm
// ----------------------------------------------------------------------------

function render_step3_uninstall(array $ordered, array $depmap, array $violations, \renderer_base $OUTPUT, \moodle_page $PAGE): void {
    $baseurl   = new moodle_url('/admin/tool/bulkpluginmanager/index.php');
    $pluginman = core_plugin_manager::instance();

    echo $OUTPUT->header();
    echo render_tabs('uninstall', $OUTPUT);
    echo $OUTPUT->heading(get_string('uninstall_confirm_heading', 'tool_bulkpluginmanager'), 2);
    echo html_writer::tag('p', get_string('uninstall_confirm_desc', 'tool_bulkpluginmanager'));

    // Violation warnings.
    if (!empty($violations)) {
        $msgs = array_map(fn($v) => s($v['message']), $violations);
        echo $OUTPUT->notification(implode('<br>', $msgs), \core\output\notification::NOTIFY_WARNING);
    }

    echo $OUTPUT->notification(get_string('uninstall_warning', 'tool_bulkpluginmanager'), \core\output\notification::NOTIFY_WARNING);

    // Summary list.
    echo html_writer::start_tag('ol', ['class' => 'bpm-confirm-list']);
    foreach ($ordered as $component) {
        $info = $pluginman->get_plugin_info($component);
        $name = $info ? ($info->displayname ?? $component) : $component;
        echo html_writer::tag('li', s($name) . html_writer::tag('small', ' (' . s($component) . ')', ['class' => 'text-muted ml-1']));
    }
    echo html_writer::end_tag('ol');

    // Execute form.
    $execurl = new moodle_url($baseurl, ['tab' => 'uninstall', 'action' => 'execute']);
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $execurl->out(false)]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'order',   'value' => json_encode($ordered)]);

    echo html_writer::start_div('bpm-form-actions mt-3 d-flex gap-2');
    echo html_writer::tag('a', get_string('btn_back', 'tool_bulkpluginmanager'), [
        'href'  => (new moodle_url($baseurl, ['tab' => 'uninstall']))->out(false),
        'class' => 'btn btn-secondary',
    ]);
    echo html_writer::tag('button', get_string('btn_execute_uninstall', 'tool_bulkpluginmanager'), [
        'type'    => 'submit',
        'class'   => 'btn btn-danger',
        'onclick' => "return confirm('" . addslashes(get_string('uninstall_confirm_heading', 'tool_bulkpluginmanager')) . ": " . count($ordered) . " plugins?');",
    ]);
    echo html_writer::end_div();

    echo html_writer::end_tag('form');
    echo $OUTPUT->footer();
}

// ----------------------------------------------------------------------------
// UNINSTALL — Execute
// ----------------------------------------------------------------------------

function render_execute_uninstall(array $ordered, \renderer_base $OUTPUT, \moodle_page $PAGE): void {
    // Run every uninstall BEFORE touching page output.
    // Each call to manager::uninstall_plugin() triggers purge_all_caches() internally
    // (via adminlib's uninstall_plugin). That increments $CFG->jsrev, changing the AMD
    // module URL paths. If this happens between $OUTPUT->header() and $OUTPUT->footer(),
    // the header and footer reference different revisions, the RequireJS factory for
    // 'jquery' can't be resolved, and `$ is not a function` is thrown.
    $results = [];
    foreach ($ordered as $component) {
        $results[$component] = manager::uninstall_plugin($component);
    }

    // All cache side-effects are done — safe to render the page now.
    echo $OUTPUT->header();
    echo render_tabs('uninstall', $OUTPUT);
    echo $OUTPUT->heading(get_string('uninstall_progress', 'tool_bulkpluginmanager'), 2);

    $success = 0;
    $failed  = 0;

    echo html_writer::start_tag('ul', ['class' => 'bpm-progress-list list-group']);

    foreach ($results as $component => $result) {
        if ($result === true) {
            $success++;
            $icon  = html_writer::tag('span', '✔', ['class' => 'text-success mr-2']);
            $msg   = get_string('uninstall_success', 'tool_bulkpluginmanager', s($component));
            $class = 'list-group-item list-group-item-success';
            echo html_writer::tag('li', $icon . $msg, ['class' => $class]);
        } else {
            $failed++;
            $icon   = html_writer::tag('span', '✘', ['class' => 'text-danger mr-2']);
            $msg    = get_string('uninstall_failed', 'tool_bulkpluginmanager', s($component));
            $detail = html_writer::tag('small', s($result), ['class' => 'd-block text-muted ml-4 mt-1']);
            $class  = 'list-group-item list-group-item-danger';
            echo html_writer::tag('li', $icon . $msg . $detail, ['class' => $class]);
        }
    }

    echo html_writer::end_tag('ul');

    $summary = (object) ['success' => $success, 'failed' => $failed];
    echo html_writer::tag('p', get_string('uninstall_complete_desc', 'tool_bulkpluginmanager', $summary), ['class' => 'mt-3 font-weight-bold']);

    echo html_writer::tag('a', get_string('view_plugin_overview', 'tool_bulkpluginmanager'), [
        'href'  => (new moodle_url('/admin/plugins.php'))->out(false),
        'class' => 'btn btn-primary mt-2',
    ]);

    echo $OUTPUT->footer();
}

// ----------------------------------------------------------------------------
// INSTALL — Step 1: File upload
// ----------------------------------------------------------------------------

function render_step1_install(\renderer_base $OUTPUT, \moodle_page $PAGE): void {
    $baseurl = new moodle_url('/admin/tool/bulkpluginmanager/index.php');

    echo $OUTPUT->heading(get_string('install_upload_heading', 'tool_bulkpluginmanager'), 2);
    echo html_writer::tag('p', get_string('install_upload_desc', 'tool_bulkpluginmanager'), ['class' => 'text-muted']);

    if (!manager::is_install_allowed()) {
        echo $OUTPUT->notification(get_string('install_disabled_desc', 'tool_bulkpluginmanager'), \core\output\notification::NOTIFY_ERROR);
        return; // Outer block calls $OUTPUT->footer().
    }

    $formurl = new moodle_url($baseurl, ['tab' => 'install', 'step' => 2]);
    echo html_writer::start_tag('form', [
        'method'  => 'post',
        'action'  => $formurl->out(false),
        'enctype' => 'multipart/form-data',
        'id'      => 'bpm-install-form',
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

    // File drop zone.
    echo html_writer::start_div('bpm-dropzone card p-4 text-center mb-3', ['id' => 'bpm-dropzone']);
    echo html_writer::tag('div', '📦', ['style' => 'font-size:3rem']);
    echo html_writer::tag('p', get_string('upload_zips', 'tool_bulkpluginmanager') . ' (.zip)', ['class' => 'mb-2 font-weight-bold']);
    echo html_writer::tag('p', get_string('upload_zips_help', 'tool_bulkpluginmanager'), ['class' => 'text-muted small mb-3']);
    echo html_writer::empty_tag('input', [
        'type'     => 'file',
        'name'     => 'zipfiles[]',
        'id'       => 'bpm-zipfiles',
        'class'    => 'form-control-file',
        'accept'   => '.zip',
        'multiple' => 'multiple',
        'required' => 'required',
    ]);
    echo html_writer::end_div();

    echo html_writer::tag('button', get_string('btn_analyze', 'tool_bulkpluginmanager'), ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');
}

// ----------------------------------------------------------------------------
// INSTALL — Step 2: Review order
// ----------------------------------------------------------------------------

function render_step2_install(array $sorted, array $depmap, array $infomap, array $paths, array $errors, \renderer_base $OUTPUT, \moodle_page $PAGE): void {
    $PAGE->requires->js_call_amd('tool_bulkpluginmanager/order_manager', 'initOrderList', ['bpm-order-list', 'install']);

    $baseurl   = new moodle_url('/admin/tool/bulkpluginmanager/index.php');
    $pluginman = core_plugin_manager::instance();

    echo $OUTPUT->header();
    echo render_tabs('install', $OUTPUT);

    if (!empty($errors)) {
        echo $OUTPUT->notification(implode('<br>', array_map('s', $errors)), \core\output\notification::NOTIFY_WARNING);
    }

    echo $OUTPUT->heading(get_string('install_order_heading', 'tool_bulkpluginmanager'), 2);
    echo html_writer::tag('p', get_string('install_order_desc', 'tool_bulkpluginmanager'), ['class' => 'text-muted']);

    $formurl = new moodle_url($baseurl, ['tab' => 'install', 'step' => 3]);
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $formurl->out(false), 'id' => 'bpm-order-form']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'order',   'value' => json_encode($sorted), 'id' => 'bpm-order-input']);

    echo html_writer::tag('button', get_string('btn_auto_sort', 'tool_bulkpluginmanager'), [
        'type'  => 'button',
        'id'    => 'bpm-auto-sort',
        'class' => 'btn btn-outline-secondary btn-sm mb-3',
    ]);

    // Build enriched sorted list using infomap.
    echo html_writer::start_tag('ul', ['class' => 'bpm-order-list list-group mb-3', 'id' => 'bpm-order-list']);
    foreach ($sorted as $component) {
        $info       = $infomap[$component] ?? null;
        $deps       = $depmap[$component]  ?? [];
        $existing   = $pluginman->get_plugin_info($component);

        $badge = $existing
            ? html_writer::tag('span', get_string('already_installed', 'tool_bulkpluginmanager'), ['class' => 'badge badge-warning'])
            : html_writer::tag('span', get_string('new_install',       'tool_bulkpluginmanager'), ['class' => 'badge badge-success']);

        $depbadges = '';
        foreach ($deps as $dep) {
            $depbadges .= html_writer::tag('span', s($dep), ['class' => 'badge badge-info mr-1']);
        }

        $warning = html_writer::tag('span', '⚠ ' . get_string('dep_warning', 'tool_bulkpluginmanager'), [
            'class' => 'bpm-dep-warning text-danger ml-2 small',
            'title' => get_string('dep_warning_detail', 'tool_bulkpluginmanager'),
            'style' => 'display:none',
        ]);

        $content  = html_writer::tag('span', '⠿', ['class' => 'bpm-drag-handle mr-2 text-muted', 'style' => 'cursor:grab']);
        $content .= html_writer::tag('strong', s($component));
        if ($info && $info->version) {
            $content .= html_writer::tag('small', ' v' . s((string)$info->version), ['class' => 'text-muted ml-1']);
        }
        $content .= ' ' . $badge;
        $content .= $warning;
        if ($depbadges) {
            $content .= html_writer::start_div('bpm-deps mt-1 ml-4');
            $content .= html_writer::tag('small', get_string('depends_on', 'tool_bulkpluginmanager') . ': ', ['class' => 'text-muted']);
            $content .= $depbadges;
            $content .= html_writer::end_div();
        }

        // Arrow buttons.
        $arrows   = html_writer::tag('button', '▲', ['type' => 'button', 'class' => 'bpm-move-up btn btn-sm btn-outline-secondary ml-2', 'title' => 'Move up']);
        $arrows  .= html_writer::tag('button', '▼', ['type' => 'button', 'class' => 'bpm-move-down btn btn-sm btn-outline-secondary ml-1', 'title' => 'Move down']);

        echo html_writer::tag('li', $content . html_writer::div($arrows, 'bpm-arrows ml-auto'), [
            'class'          => 'list-group-item d-flex align-items-start',
            'data-component' => $component,
            'data-deps'      => json_encode($deps),
        ]);
    }
    echo html_writer::end_tag('ul');

    echo html_writer::start_div('bpm-form-actions mt-3 d-flex gap-2');
    echo html_writer::tag('a', get_string('btn_back', 'tool_bulkpluginmanager'), [
        'href'  => (new moodle_url($baseurl, ['tab' => 'install']))->out(false),
        'class' => 'btn btn-secondary',
    ]);
    echo html_writer::tag('button', get_string('btn_confirm_install', 'tool_bulkpluginmanager'), ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo html_writer::end_div();

    echo html_writer::end_tag('form');
    echo $OUTPUT->footer();
}

// ----------------------------------------------------------------------------
// INSTALL — Step 3: Confirm
// ----------------------------------------------------------------------------

function render_step3_install(array $ordered, array $depmap, array $infomap, array $paths, array $violations, \renderer_base $OUTPUT, \moodle_page $PAGE): void {
    $baseurl = new moodle_url('/admin/tool/bulkpluginmanager/index.php');

    echo $OUTPUT->header();
    echo render_tabs('install', $OUTPUT);
    echo $OUTPUT->heading(get_string('install_confirm_heading', 'tool_bulkpluginmanager'), 2);
    echo html_writer::tag('p', get_string('install_confirm_desc', 'tool_bulkpluginmanager'));

    if (!empty($violations)) {
        $msgs = array_map(fn($v) => s($v['message']), $violations);
        echo $OUTPUT->notification(implode('<br>', $msgs), \core\output\notification::NOTIFY_WARNING);
    }

    echo $OUTPUT->notification(get_string('install_warning', 'tool_bulkpluginmanager'), \core\output\notification::NOTIFY_WARNING);

    echo html_writer::start_tag('ol', ['class' => 'bpm-confirm-list']);
    foreach ($ordered as $component) {
        $info    = $infomap[$component] ?? null;
        $version = $info ? ' v' . $info->version : '';
        echo html_writer::tag('li', s($component) . html_writer::tag('small', $version, ['class' => 'text-muted ml-1']));
    }
    echo html_writer::end_tag('ol');

    $execurl = new moodle_url($baseurl, ['tab' => 'install', 'action' => 'execute']);
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $execurl->out(false)]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'order',   'value' => json_encode($ordered)]);

    echo html_writer::start_div('bpm-form-actions mt-3 d-flex gap-2');
    echo html_writer::tag('a', get_string('btn_back', 'tool_bulkpluginmanager'), [
        'href'  => (new moodle_url($baseurl, ['tab' => 'install']))->out(false),
        'class' => 'btn btn-secondary',
    ]);
    echo html_writer::tag('button', get_string('btn_execute_install', 'tool_bulkpluginmanager'), ['type' => 'submit', 'class' => 'btn btn-primary']);
    echo html_writer::end_div();

    echo html_writer::end_tag('form');
    echo $OUTPUT->footer();
}

// ----------------------------------------------------------------------------
// INSTALL — Execute (deploy)
// ----------------------------------------------------------------------------

function render_execute_install(array $ordered, array $paths, array $infomap, \renderer_base $OUTPUT, \moodle_page $PAGE): void {
    global $CFG;
    require_once($CFG->libdir . '/upgradelib.php');

    echo $OUTPUT->header();
    echo render_tabs('install', $OUTPUT);
    echo $OUTPUT->heading(get_string('install_deploying', 'tool_bulkpluginmanager', ''), 2);

    echo html_writer::start_tag('ul', ['class' => 'bpm-progress-list list-group']);

    $success    = 0;
    $failed     = 0;
    $deployable = [];

    foreach ($ordered as $component) {
        if (!isset($paths[$component])) {
            echo html_writer::tag('li',
                html_writer::tag('span', '⚠', ['class' => 'text-warning mr-2']) . s("No path found for {$component}"),
                ['class' => 'list-group-item list-group-item-warning']
            );
            $failed++;
            continue;
        }

        $info = $infomap[$component] ?? null;
        if ($info === null) {
            $failed++;
            continue;
        }

        $obj              = new stdClass();
        $obj->component   = $component;
        $obj->zipfilepath = $paths[$component];
        $deployable[]     = $obj;
    }

    if (!empty($deployable)) {
        $pluginman = core_plugin_manager::instance();

        // Validate first (dry run — shows output).
        echo html_writer::end_tag('ul');
        echo html_writer::start_tag('pre', ['class' => 'bpm-install-log bg-dark text-light p-3 rounded mt-3']);

        $validated = $pluginman->install_plugins($deployable, false, false);

        echo html_writer::end_tag('pre');

        if ($validated) {
            // Actually deploy.
            echo html_writer::start_tag('pre', ['class' => 'bpm-install-log bg-dark text-light p-3 rounded mt-1']);
            $pluginman->install_plugins($deployable, true, false);
            echo html_writer::end_tag('pre');

            core_plugin_manager::reset_caches();

            echo $OUTPUT->notification(get_string('install_complete_manual', 'tool_bulkpluginmanager'), \core\output\notification::NOTIFY_SUCCESS);

            echo html_writer::tag('a', get_string('complete_upgrade', 'tool_bulkpluginmanager'), [
                'href'  => (new moodle_url('/admin/index.php', ['cache' => 0, 'confirmplugincheck' => 0]))->out(false),
                'class' => 'btn btn-success mt-2',
            ]);
        } else {
            echo $OUTPUT->notification('Validation failed — please check the log above.', \core\output\notification::NOTIFY_ERROR);
        }
    }

    echo $OUTPUT->footer();
}

// ----------------------------------------------------------------------------
// Shared: render the drag-and-drop order list (for uninstall step 2).
// ----------------------------------------------------------------------------

function render_order_list(array $sorted, array $depmap, \core_plugin_manager $pluginman, string $mode): string {
    $html = html_writer::start_tag('ul', ['class' => 'bpm-order-list list-group mb-3', 'id' => 'bpm-order-list']);

    foreach ($sorted as $component) {
        $info = $pluginman->get_plugin_info($component);
        $name = $info ? ($info->displayname ?? $component) : $component;
        $deps = $depmap[$component] ?? [];

        $depbadges = '';
        foreach ($deps as $dep) {
            $depbadges .= html_writer::tag('span', s($dep), ['class' => 'badge badge-info mr-1']);
        }

        $warning = html_writer::tag('span', '⚠ ' . get_string('dep_warning', 'tool_bulkpluginmanager'), [
            'class' => 'bpm-dep-warning text-danger ml-2 small',
            'title' => get_string('dep_warning_detail', 'tool_bulkpluginmanager'),
            'style' => 'display:none',
        ]);

        $content  = html_writer::tag('span', '⠿', ['class' => 'bpm-drag-handle mr-2 text-muted', 'style' => 'cursor:grab']);
        $content .= html_writer::tag('strong', s($name));
        $content .= html_writer::tag('small', ' (' . s($component) . ')', ['class' => 'text-muted ml-1']);
        $content .= $warning;
        if ($depbadges) {
            $content .= html_writer::start_div('bpm-deps mt-1 ml-4');
            $content .= html_writer::tag('small', get_string('depends_on', 'tool_bulkpluginmanager') . ': ', ['class' => 'text-muted']);
            $content .= $depbadges;
            $content .= html_writer::end_div();
        }

        $arrows  = html_writer::tag('button', '▲', ['type' => 'button', 'class' => 'bpm-move-up btn btn-sm btn-outline-secondary ml-2', 'title' => 'Move up']);
        $arrows .= html_writer::tag('button', '▼', ['type' => 'button', 'class' => 'bpm-move-down btn btn-sm btn-outline-secondary ml-1', 'title' => 'Move down']);

        $html .= html_writer::tag('li', $content . html_writer::div($arrows, 'bpm-arrows ml-auto'), [
            'class'          => 'list-group-item d-flex align-items-start',
            'data-component' => $component,
            'data-deps'      => json_encode($deps),
            'data-mode'      => $mode,
        ]);
    }

    $html .= html_writer::end_tag('ul');
    return $html;
}
