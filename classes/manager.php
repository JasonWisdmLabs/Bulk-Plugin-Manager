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
 * Core business logic for the Bulk Plugin Manager.
 *
 * @package   tool_bulkpluginmanager
 * @copyright 2024 WisdmLabs
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_bulkpluginmanager;

defined('MOODLE_INTERNAL') || die();

/**
 * Provides methods to list, analyse, install, and uninstall Moodle plugins in bulk.
 */
class manager {

    // -------------------------------------------------------------------------
    // Installed-plugin helpers
    // -------------------------------------------------------------------------

    /**
     * Return all installed plugins grouped by type, enriched with dependency info.
     *
     * @return array  Flat list of stdClass objects:
     *                ->component, ->type, ->name, ->displayname, ->version,
     *                ->dependencies, ->requiredby, ->can_uninstall
     */
    public static function get_all_plugins(): array {
        $pluginman  = \core_plugin_manager::instance();
        $allplugins = $pluginman->get_plugins();

        $result = [];

        foreach ($allplugins as $type => $plugins) {
            foreach ($plugins as $name => $info) {
                $component = $info->component;

                // Gather the component names of direct dependencies.
                $deps = [];
                if (!empty($info->dependencies)) {
                    foreach ($info->dependencies as $depcomponent => $depversion) {
                        $deps[] = $depcomponent;
                    }
                }

                // Gather what other installed plugins require this one.
                $requiredby = $pluginman->other_plugins_that_require($component);

                // Determine the subplugin components so we can exclude them from the
                // "blocked by" list (subplugins are not independent blockers).
                $subplugins    = $pluginman->get_subplugins_of_plugin($component);
                $subpluginkeys = array_keys($subplugins);

                // External plugins that currently block this plugin's uninstall.
                // These are other (non-subplugin) installed plugins that depend on it.
                $blockedby = array_values(
                    array_filter($requiredby, fn($r) => !in_array($r, $subpluginkeys))
                );

                $canuninstall = $pluginman->can_uninstall_plugin($component);

                // A plugin is "conditionally uninstallable" when:
                // • its plugin type permits uninstall (is_uninstall_allowed),
                // • but right now it is blocked only by other plugins that require it —
                //   so it WILL become uninstallable once those dependents are removed first.
                $typeallows = $info->is_uninstall_allowed();

                $obj               = new \stdClass();
                $obj->component    = $component;
                $obj->type         = $type;
                $obj->name         = $name;
                $obj->displayname  = $info->displayname ?? "{$type}_{$name}";
                $obj->version      = isset($info->versiondb) ? (string) $info->versiondb : '';
                $obj->dependencies = $deps;
                $obj->requiredby   = array_values($requiredby);
                $obj->blocked_by   = $blockedby;
                $obj->can_uninstall = $canuninstall;
                // Conditionally uninstallable: type permits it but dependents are in the way.
                $obj->can_uninstall_conditionally = !$canuninstall && $typeallows && !empty($blockedby);
                $obj->is_standard  = $info->is_standard();

                $result[] = $obj;
            }
        }

        // Sort alphabetically by displayname for consistent presentation.
        usort($result, fn($a, $b) => strcmp($a->displayname, $b->displayname));

        return $result;
    }

    /**
     * Build a dependency map (component => [direct dependency components]) for
     * the given list of installed components. Only dependencies that are
     * themselves in the installed set are included.
     *
     * @param string[] $components
     * @return array
     */
    public static function build_dependency_map(array $components): array {
        $pluginman = \core_plugin_manager::instance();
        $map       = [];

        foreach ($components as $component) {
            $info = $pluginman->get_plugin_info($component);
            $deps = [];
            if ($info && !empty($info->dependencies)) {
                foreach (array_keys($info->dependencies) as $dep) {
                    $deps[] = $dep;
                }
            }
            $map[$component] = $deps;
        }

        return $map;
    }

    /**
     * Uninstall a single plugin.
     *
     * Both core_component and core_plugin_manager caches are reset before the
     * uninstallability check. This is essential during a bulk loop: after each
     * uninstall the previously-removed plugin still lives in core_component's
     * static $plugins array, so subsequent can_uninstall_plugin() calls see
     * stale dependents and falsely return false.
     *
     * @param  string $component
     * @return true|string  True on success, human-readable error string on failure.
     */
    public static function uninstall_plugin(string $component) {
        // Flush both caches so this call sees the real on-disk state, not a
        // snapshot taken before earlier plugins in the batch were removed.
        \core_component::reset();
        \core_plugin_manager::reset_caches();

        $pluginman = \core_plugin_manager::instance();
        $pluginfo  = $pluginman->get_plugin_info($component);

        if (!$pluginfo) {
            return "Plugin not found: {$component}";
        }

        if (!$pluginman->can_uninstall_plugin($component)) {
            return "Plugin cannot be uninstalled (core-locked or a required dependent is still installed).";
        }

        // Capture the directory now — the pluginfo object becomes stale after uninstall.
        $rootdir = $pluginfo->rootdir;

        try {
            $progress = new \null_progress_trace();
            $pluginman->uninstall_plugin($component, $progress);

            // Delete plugin files from disk. Without this Moodle detects the
            // directory on the next page load and flags the plugin as
            // "to be installed", undoing the uninstall visually.
            if ($rootdir && is_dir($rootdir)) {
                fulldelete($rootdir);
            }

            // Reset again so the next plugin in the batch gets a clean scan.
            \core_component::reset();
            \core_plugin_manager::reset_caches();

            return true;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    // -------------------------------------------------------------------------
    // ZIP / install helpers
    // -------------------------------------------------------------------------

    /**
     * Check whether the server allows automatic plugin deployment.
     *
     * @return bool
     */
    public static function is_install_allowed(): bool {
        global $CFG;
        return empty($CFG->disableupdateautodeploy);
    }

    /**
     * Parse a plugin ZIP file and return its metadata without extracting it.
     *
     * Reads only version.php from inside the archive using regex — intentionally
     * avoids eval() for security.
     *
     * @param  string      $zippath  Absolute path to the ZIP file.
     * @return \stdClass|null        Object with ->component, ->version, ->requires,
     *                               ->dependencies (array), ->rootdir; or null on failure.
     */
    public static function parse_zip(string $zippath): ?\stdClass {
        $zip = new \ZipArchive();
        if ($zip->open($zippath) !== true) {
            return null;
        }

        // Find the single root directory inside the ZIP.
        $rootdir = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if (strpos($entry, '/') !== false) {
                $rootdir = explode('/', $entry)[0];
                break;
            }
        }

        if ($rootdir === null) {
            $zip->close();
            return null;
        }

        $versioncontent = $zip->getFromName("{$rootdir}/version.php");
        $zip->close();

        if ($versioncontent === false) {
            return null;
        }

        return self::parse_version_php($versioncontent, $rootdir);
    }

    /**
     * Deploy a plugin ZIP file to the correct Moodle plugin directory.
     *
     * Uses core_plugin_manager's install_plugins to deploy a single local ZIP.
     * Requires the file info parsed by parse_zip().
     *
     * @param  string     $zippath   Path to the uploaded/temp ZIP file.
     * @param  \stdClass  $info      Object returned by parse_zip().
     * @return bool|string           True on success, error message string on failure.
     */
    public static function deploy_zip(string $zippath, \stdClass $info) {
        global $CFG;

        if (!self::is_install_allowed()) {
            return 'Plugin auto-deploy is disabled ($CFG->disableupdateautodeploy).';
        }

        // Build the installable descriptor that install_plugins() expects for local ZIPs.
        $installable              = new \stdClass();
        $installable->component   = $info->component;
        $installable->zipfilepath = $zippath;

        try {
            $pluginman = \core_plugin_manager::instance();
            $result    = $pluginman->install_plugins([$installable], true, true);
            \core_plugin_manager::reset_caches();
            return $result ? true : 'Deployment returned false.';
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * Store uploaded ZIP files in a session-scoped temp directory.
     *
     * @param  array  $files   $_FILES sub-array for the multi-file input.
     * @return array           List of stdClass with ->path, ->original_name, ->info (or ->error).
     */
    public static function store_uploaded_zips(array $files): array {
        $tmpdir  = make_temp_directory('tool_bulkpluginmanager/' . sesskey());
        $results = [];

        // Normalise PHP's multi-file $_FILES structure to a flat list.
        $count = is_array($files['name']) ? count($files['name']) : 1;

        for ($i = 0; $i < $count; $i++) {
            $name    = is_array($files['name'])    ? $files['name'][$i]    : $files['name'];
            $tmpname = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
            $error   = is_array($files['error'])   ? $files['error'][$i]   : $files['error'];

            $entry                  = new \stdClass();
            $entry->original_name   = $name;

            if ($error !== UPLOAD_ERR_OK) {
                $entry->error = "Upload error code {$error}";
                $results[]    = $entry;
                continue;
            }

            $dest = $tmpdir . '/' . clean_filename($name);
            if (!move_uploaded_file($tmpname, $dest)) {
                $entry->error = "Could not save uploaded file.";
                $results[]    = $entry;
                continue;
            }

            $info = self::parse_zip($dest);
            if ($info === null) {
                $entry->error = "Could not parse plugin info from ZIP.";
                @unlink($dest);
                $results[] = $entry;
                continue;
            }

            $entry->path = $dest;
            $entry->info = $info;
            $results[]   = $entry;
        }

        return $results;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Parse a version.php string using regex — no eval().
     *
     * @param  string      $content    Content of version.php.
     * @param  string      $rootdir    Directory name inside the ZIP (used as fallback name).
     * @return \stdClass|null
     */
    private static function parse_version_php(string $content, string $rootdir): ?\stdClass {
        // Component: $plugin->component = 'mod_foobar';
        $component = null;
        if (preg_match('/\$plugin\s*->\s*component\s*=\s*[\'"]([a-z][a-z0-9_]+)[\'"]/', $content, $m)) {
            $component = $m[1];
        }

        if ($component === null) {
            // Some older plugins declare component by convention from the folder name.
            // Try to derive it from the root dir as a last resort.
            if (strpos($rootdir, '_') !== false) {
                $component = $rootdir;
            } else {
                return null;
            }
        }

        // Version: $plugin->version = 2024042200;
        $version = null;
        if (preg_match('/\$plugin\s*->\s*version\s*=\s*(\d+)/', $content, $m)) {
            $version = (int) $m[1];
        }

        // Requires: $plugin->requires = 2022112800;
        $requires = null;
        if (preg_match('/\$plugin\s*->\s*requires\s*=\s*(\d+)/', $content, $m)) {
            $requires = (int) $m[1];
        }

        // Dependencies: $plugin->dependencies = ['mod_x' => '...', 'local_y' => ANY_VERSION];
        $dependencies = [];
        if (preg_match('/\$plugin\s*->\s*dependencies\s*=\s*\[([^\]]*)\]/s', $content, $m)) {
            // Each key is a component name in single or double quotes.
            preg_match_all('/[\'"]([a-z][a-z0-9_]+)[\'"\s]*=>/', $m[1], $dm);
            $dependencies = $dm[1];
        }

        $obj               = new \stdClass();
        $obj->component    = $component;
        $obj->version      = $version;
        $obj->requires     = $requires;
        $obj->dependencies = $dependencies;
        $obj->rootdir      = $rootdir;

        return $obj;
    }
}
