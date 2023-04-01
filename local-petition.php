<?php

/**
 * Local Petition
 *
 * @package           LocalPetition
 * @author            Mark Salisbury
 * @copyright         2023 Citizens for a Library.org
 * @license           GPL-3.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Local Petition
 * Plugin URI:        https://github.com/mrsalt/local-petition
 * Description:       Local Petition is a WordPress plugin which allows you to create a petition which shows where signers live (but does not identify them by name on a map).
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Mark Salisbury
 * Author URI:        https://github.com/mrsalt/me
 * Text Domain:       local-petition
 * License:           GPL v3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Update URI:        https://github.com/mrsalt/local-petition
 */

require_once('lp-database.php');
register_activation_hook(__FILE__, 'lp_db_install');
register_activation_hook(__FILE__, 'lp_db_install_data');

// https://codex.wordpress.org/Creating_Tables_with_Plugins
// Since 3.1 the activation function registered with register_activation_hook() is not called when a plugin is updated:
add_action('plugins_loaded', 'lp_db_update_check');
