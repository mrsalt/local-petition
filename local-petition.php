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

 // Make sure we don't expose any info if called directly
if ( !function_exists( 'add_action' ) ) {
	echo 'Hi there!  I\'m just a plugin, not much I can do when called directly.';
	exit;
}

// This next line is helpful for debugging.  If you want to load the site on a different port,
// redirect_canonical prevents this from working because it redirects back to the canonical url.
// TODO: make this conditional based on detecting that we're using a non-standard port.
remove_filter('template_redirect','redirect_canonical');

require_once('_inc/lp-database.php');
register_activation_hook(__FILE__, 'lp_db_install');
register_activation_hook(__FILE__, 'lp_db_install_data');

// https://codex.wordpress.org/Creating_Tables_with_Plugins
// Since 3.1 the activation function registered with register_activation_hook() is not called when a plugin is updated:
add_action('plugins_loaded', 'lp_db_update_check');

// Register shortcodes
require_once('_inc/lp-render-petition.php');
add_shortcode('local_petition','lp_render_petition');