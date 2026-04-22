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
 * Dependency resolver for bulk plugin operations.
 *
 * @package   tool_bulkpluginmanager
 * @copyright 2024 WisdmLabs
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_bulkpluginmanager;

defined('MOODLE_INTERNAL') || die();

/**
 * Resolves plugin dependency ordering using topological sort (Kahn's algorithm).
 */
class dependency_resolver {

    /**
     * Sort plugins into correct installation order (dependencies installed first).
     *
     * @param string[] $components  List of plugin component names to sort.
     * @param array    $depmap      Map of component => [list of components it depends on].
     * @return string[]             Sorted component list, dependencies first.
     */
    public static function sort_for_install(array $components, array $depmap): array {
        // For install: dependency must come before the plugin that needs it.
        // Edge direction: dep → plugin (dep must precede plugin).
        return self::topological_sort($components, $depmap);
    }

    /**
     * Sort plugins into correct uninstall order (dependents uninstalled first).
     *
     * @param string[] $components  List of plugin component names to sort.
     * @param array    $depmap      Map of component => [list of components it depends on].
     * @return string[]             Sorted component list, dependents first.
     */
    public static function sort_for_uninstall(array $components, array $depmap): array {
        // For uninstall: reverse of install order — dependents removed before their deps.
        return array_reverse(self::topological_sort($components, $depmap));
    }

    /**
     * Validate that a given order is safe for the specified operation.
     *
     * Returns an array of violation descriptions. Empty array means the order is valid.
     *
     * @param string[] $ordered   Plugin components in the proposed order.
     * @param array    $depmap    Map of component => [dependencies].
     * @param string   $mode      'install' or 'uninstall'.
     * @return array              Array of ['component' => string, 'message' => string] violations.
     */
    public static function validate_order(array $ordered, array $depmap, string $mode): array {
        $violations = [];
        $positions  = array_flip($ordered); // component => index.

        foreach ($ordered as $idx => $component) {
            $deps = $depmap[$component] ?? [];
            foreach ($deps as $dep) {
                if (!isset($positions[$dep])) {
                    continue; // Dep not in the selected set — handled elsewhere.
                }
                $depidx = $positions[$dep];
                if ($mode === 'install' && $depidx > $idx) {
                    $violations[] = [
                        'component' => $component,
                        'dep'       => $dep,
                        'message'   => "'{$component}' depends on '{$dep}' which is listed after it. Move '{$dep}' earlier.",
                    ];
                } elseif ($mode === 'uninstall' && $depidx < $idx) {
                    $violations[] = [
                        'component' => $component,
                        'dep'       => $dep,
                        'message'   => "'{$component}' depends on '{$dep}'. In uninstall mode '{$component}' must be removed before '{$dep}'.",
                    ];
                }
            }
        }

        return $violations;
    }

    /**
     * Topological sort using Kahn's algorithm.
     *
     * Produces an ordering where each plugin appears after all its dependencies
     * within the given set. Plugins not in the set are ignored in edge building.
     *
     * @param string[] $components Components to sort.
     * @param array    $depmap     Map of component => [dependencies].
     * @return string[]            Topologically sorted list (deps first).
     */
    private static function topological_sort(array $components, array $depmap): array {
        $componentset = array_flip($components);
        $indegree     = array_fill_keys($components, 0);
        // adjacency: dep => [plugins that depend on it].
        $graph        = array_fill_keys($components, []);

        foreach ($components as $component) {
            foreach ($depmap[$component] ?? [] as $dep) {
                if (!isset($componentset[$dep])) {
                    continue; // External dependency — not in the selected set.
                }
                $graph[$dep][] = $component;
                $indegree[$component]++;
            }
        }

        // Initialise queue with nodes that have no in-set dependencies.
        $queue = [];
        foreach ($indegree as $component => $degree) {
            if ($degree === 0) {
                $queue[] = $component;
            }
        }

        $sorted = [];
        while (!empty($queue)) {
            $node     = array_shift($queue);
            $sorted[] = $node;
            foreach ($graph[$node] as $dependent) {
                $indegree[$dependent]--;
                if ($indegree[$dependent] === 0) {
                    $queue[] = $dependent;
                }
            }
        }

        // Any remaining nodes have circular dependencies — append them as-is.
        foreach ($components as $component) {
            if (!in_array($component, $sorted, true)) {
                $sorted[] = $component;
            }
        }

        return $sorted;
    }
}
