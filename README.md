# Bulk Plugin Manager for Moodle

Install or uninstall multiple Moodle plugins in one workflow. Automatically resolves dependency order to prevent conflicts, lets you review and reorder before committing, and detects when dependent plugins must be removed together. Supports ZIP installs and bulk uninstall.

---

## Features

- **Bulk uninstall** — select multiple plugins at once with search, type filter, and an "Additional plugins only" toggle to focus on third-party plugins
- **Bulk install** — upload multiple ZIP files and install them in a single operation
- **Automatic dependency ordering** — uses topological sort to ensure plugins are installed or uninstalled in a safe order
- **Drag-and-drop reordering** — manually adjust the order before committing, with up/down arrow fallbacks
- **Dependency conflict detection** — highlights order violations in real time and warns when a dependency is out of place
- **Conditional uninstall** — if a plugin is blocked by dependents, selecting it auto-selects its dependents and handles the order automatically
- **Auto-sort button** — resets the list to the computed safe order at any point

## Compatibility

| Moodle Version | Supported |
|----------------|-----------|
| 4.5            | ✅        |
| 5.0            | ✅        |
| 5.1            | ✅        |
| 5.2            | ✅        |

**PHP:** 8.1 or higher

## Installation

1. Download or clone this repository into your Moodle installation:
   ```
   /path/to/moodle/admin/tool/bulkpluginmanager/
   ```
2. Log in as a site administrator and visit **Site Administration**. Moodle will detect the new plugin and prompt you to complete the installation.
3. After the upgrade, navigate to **Site Administration → Server → Bulk Plugin Manager**.

## Usage

### Uninstalling plugins

1. Open the **Uninstall Plugins** tab.
2. Use the search box, type filter, or the **Additional plugins only** button to narrow the list.
3. Check the plugins you want to remove. If a plugin requires dependents to be removed first, checking it will automatically select those dependents.
4. Click **Next: Review Uninstall Order**.
5. Drag and drop items to adjust the order, or click **Auto-sort by dependencies** to reset to the safe computed order. A ⚠ warning highlights any violations.
6. Click **Confirm Uninstall**, review the final list, then click **Uninstall All Selected Plugins**.

### Installing plugins

1. Open the **Install Plugins** tab.
2. Select one or more Moodle plugin ZIP files.
3. Click **Upload & Analyze**. The tool reads each ZIP, detects the component name and dependencies, and sorts them into the correct install order.
4. Adjust the order if needed, then click **Confirm Install → Install All Plugins**.
5. You will be redirected to complete the database upgrade.

> **Note:** Automatic plugin deployment must be enabled on your server (`$CFG->disableupdateautodeploy` must not be set) for the install feature to work.

## Requirements

- Site administrator access (`moodle/site:config` capability)
- For plugin installation: the Moodle `dataroot` and plugin directories must be writable by the web server

## License

This plugin is licensed under the [GNU General Public License v3.0](https://www.gnu.org/licenses/gpl-3.0.html).

## Credits

Developed by [WisdmLabs](https://wisdmlabs.com).
