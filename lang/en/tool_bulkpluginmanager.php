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
 * Bulk Plugin Manager - language strings.
 *
 * @package   tool_bulkpluginmanager
 * @copyright 2024 WisdmLabs
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// General.
$string['pluginname']                = 'Bulk Plugin Manager';
$string['pluginname_help']           = 'Install or uninstall multiple plugins at once with automatic dependency ordering.';
$string['privacy:metadata']          = 'The Bulk Plugin Manager plugin does not store any personal data.';

// Navigation/tabs.
$string['tab_uninstall']             = 'Uninstall Plugins';
$string['tab_install']               = 'Install Plugins';

// Uninstall Step 1 - Select.
$string['uninstall_select_heading']  = 'Select plugins to uninstall';
$string['uninstall_select_desc']     = 'Choose the plugins you want to uninstall. The tool will automatically determine a safe uninstall order based on dependencies.';
$string['search_plugins']            = 'Search plugins';
$string['filter_by_type']            = 'Filter by type';
$string['all_types']                 = 'All types';
$string['select_all']                = 'Select all';
$string['deselect_all']              = 'Deselect all';
$string['filter_additional']         = 'Additional plugins only';
$string['filter_additional_active']  = 'Showing additional plugins only';
$string['plugin_name']               = 'Plugin name';
$string['plugin_component']          = 'Component';
$string['plugin_type']               = 'Type';
$string['plugin_version']            = 'Version';
$string['plugin_requires']           = 'Required by';
$string['plugin_dependencies']       = 'Dependencies';
$string['cannot_uninstall']          = 'Cannot uninstall';
$string['cannot_uninstall_reason']   = 'This plugin cannot be uninstalled (core plugin or required by other plugins).';
$string['blocked_by_dependents']     = 'Uninstallable if dependents are also selected';
$string['blocked_by_label']          = 'Must also select:';
$string['blocked_by_autoselect']     = 'Dependents auto-selected. Uncheck them first to remove this plugin from the selection.';
$string['missing_dependents']        = 'Cannot proceed: {$a->plugin} requires you to also select its dependent plugin(s): {$a->missing}. Select them or deselect {$a->plugin}.';
$string['btn_review_order']          = 'Next: Review Uninstall Order';
$string['no_plugins_selected']       = 'Please select at least one plugin to uninstall.';

// Uninstall Step 2 - Order.
$string['uninstall_order_heading']   = 'Review uninstall order';
$string['uninstall_order_desc']      = 'Drag and drop to reorder. Plugins at the top will be uninstalled first. The order has been automatically sorted so that dependent plugins are removed before the plugins they depend on.';
$string['btn_auto_sort']             = 'Auto-sort by dependencies';
$string['btn_back']                  = 'Back';
$string['btn_confirm_uninstall']     = 'Confirm Uninstall';
$string['dep_warning']               = 'Warning: dependency order issue';
$string['dep_warning_detail']        = 'This plugin depends on a plugin listed below it. Reorder so dependencies come after their dependents.';
$string['depends_on']                = 'Depends on';
$string['required_by']               = 'Required by';

// Uninstall Step 3 - Confirm.
$string['uninstall_confirm_heading'] = 'Confirm bulk uninstall';
$string['uninstall_confirm_desc']    = 'The following plugins will be permanently uninstalled in the order shown below. This action cannot be undone.';
$string['uninstall_warning']         = 'Warning: Uninstalling a plugin permanently removes its code and database tables. Make sure you have a database backup before proceeding.';
$string['btn_execute_uninstall']     = 'Uninstall All Selected Plugins';
$string['selected_count']            = '{$a} plugin(s) selected';

// Uninstall execution.
$string['uninstall_progress']        = 'Uninstall progress';
$string['uninstalling']              = 'Uninstalling {$a}...';
$string['uninstall_success']         = '{$a} uninstalled successfully.';
$string['uninstall_failed']          = 'Failed to uninstall {$a}.';
$string['uninstall_complete']        = 'Bulk uninstall complete.';
$string['uninstall_complete_desc']   = '{$a->success} plugin(s) uninstalled successfully, {$a->failed} failed.';
$string['view_plugin_overview']      = 'View plugin overview';

// Install Step 1 - Upload.
$string['install_upload_heading']    = 'Upload plugin ZIP files';
$string['install_upload_desc']       = 'Select one or more plugin ZIP files to install. The tool will detect dependencies and suggest an installation order.';
$string['upload_zips']               = 'Plugin ZIP files';
$string['upload_zips_help']          = 'Select one or more Moodle plugin ZIP files. Each ZIP must contain a single folder with a valid version.php file.';
$string['btn_analyze']               = 'Upload & Analyze';
$string['install_disabled']          = 'Plugin installation via the web interface is disabled on this server.';
$string['install_disabled_desc']     = 'The <code>$CFG->disableupdateautodeploy</code> setting is enabled. Plugin files cannot be deployed automatically.';
$string['no_files_uploaded']         = 'No ZIP files were uploaded. Please select at least one plugin ZIP file.';
$string['invalid_zip']               = 'Invalid ZIP file: {$a}. Could not read plugin information.';
$string['zip_no_version']            = 'ZIP file {$a} does not contain a valid version.php file.';
$string['zip_no_component']          = 'Could not determine plugin component from {$a}.';

// Install Step 2 - Order.
$string['install_order_heading']     = 'Review install order';
$string['install_order_desc']        = 'Drag and drop to reorder. Plugins at the top will be installed first. Dependencies must be installed before the plugins that require them.';
$string['btn_confirm_install']       = 'Confirm Install';
$string['already_installed']         = 'Already installed (will upgrade)';
$string['new_install']               = 'New installation';
$string['external_dep']              = 'External dependency (not in upload set)';
$string['external_dep_detail']       = 'This plugin depends on {$a} which is not in the current upload set. Make sure it is already installed.';

// Install Step 3 - Confirm.
$string['install_confirm_heading']   = 'Confirm bulk install';
$string['install_confirm_desc']      = 'The following plugins will be installed/upgraded in the order shown. After deployment, you will be redirected to complete the database upgrade.';
$string['btn_execute_install']       = 'Install All Plugins';
$string['install_warning']           = 'Make sure you have a database backup before installing plugins on a production site.';

// Install execution.
$string['install_deploying']         = 'Deploying {$a}...';
$string['install_success']           = '{$a} deployed successfully.';
$string['install_failed']            = 'Failed to deploy {$a}: {$b}';
$string['install_complete']          = 'Deployment complete. Redirecting to complete database upgrade...';
$string['install_complete_manual']   = 'All plugins deployed. Click below to complete the database upgrade.';
$string['complete_upgrade']          = 'Complete database upgrade';

// Errors.
$string['error_no_permission']       = 'You do not have permission to manage plugins.';
$string['error_sesskey']             = 'Session key mismatch. Please try again.';
$string['error_invalid_component']   = 'Invalid plugin component: {$a}';
$string['error_uninstall']           = 'An error occurred during uninstall of {$a}.';

// Backup tab.
$string['tab_backup']                = 'Backup & Restore';
$string['backup_heading']            = 'Backup Additional Plugins';
$string['backup_desc']               = 'Create a backup archive of all non-core (additional) plugins currently installed. The archive is stored in the Moodle data folder and survives plugin uninstalls and core upgrades.';
$string['backup_status_none']        = 'No backup file found.';
$string['backup_status_exists']      = 'Backup found: {$a->size} created on {$a->date}';
$string['backup_create_btn']         = 'Create Backup';
$string['backup_restore_btn']        = 'Restore from Backup';
$string['backup_plugins_included']   = 'Plugins that will be backed up';
$string['backup_no_plugins']         = 'No additional plugins found to back up.';
$string['backup_create_success']     = 'Backup created successfully containing {$a} plugin(s).';
$string['backup_dir_not_writable']   = 'Backup directory is not writable: <strong>{$a}</strong>. Ensure the web server user (e.g. <code>www-data</code> or <code>apache</code>) has write permission on this directory.';
$string['backup_remove_failed']      = 'Could not remove existing backup file: <strong>{$a}</strong>. It may be owned by a different user. Delete it manually or change its ownership to the web server user (e.g. <code>www-data</code> or <code>apache</code>).';
$string['backup_zip_open_failed']    = 'Could not open ZipArchive for writing. Check that the Moodle data directory is writable by the web server user (e.g. <code>www-data</code> or <code>apache</code>).';
$string['backup_zip_close_failed']   = 'Could not write backup file: <strong>{$a->file}</strong>.<br>Error: <code>{$a->status}</code><br>Temp path: <code>{$a->temp}</code><br>Make sure the web server user (e.g. <code>www-data</code> or <code>apache</code>) has write permission on the Moodle data directory and temp directory.';
$string['backup_move_failed']        = 'Could not move backup file to Moodle data directory: <strong>{$a}</strong>. Check disk space and directory permissions.';
$string['backup_restore_noplugins']  = 'No valid plugins found in the backup archive.';
$string['restore_order_heading']     = 'Review Restore Order';
$string['restore_order_desc']        = 'These plugins were found in the backup. Review the installation order before restoring.';
$string['restore_confirm_heading']   = 'Confirm Restore';
$string['restore_confirm_desc']      = 'The following plugins will be restored from the backup.';
$string['restore_deploying']         = 'Restoring plugins from backup...';
$string['restore_complete_manual']   = 'All plugins restored. Click below to complete the database upgrade.';
