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
define('LOCAL_PETITION_VERSION', '1.0.0');

// True if we are running in a production environment
define('LP_PRODUCTION', get_cfg_var('environment') === 'production');
define('reCAPTCHA_site_key', get_cfg_var('reCAPTCHA_site_key'));
define('reCAPTCHA_secret', get_cfg_var('reCAPTCHA_secret'));
define('google_maps_api_key', get_cfg_var('google_maps_api_key'));

// Make sure we don't expose any info if called directly
if (!function_exists('add_action')) {
	echo 'Hi there!  I\'m just a plugin, not much I can do when called directly.';
	exit;
}

require_once('_inc/lp-init.php');
add_action('init', 'lp_handle_init');

// This next line is helpful for debugging.  If you want to load the site on a different port,
// redirect_canonical prevents this from working because it redirects back to the canonical url.
// TODO: make this conditional based on detecting that we're using a non-standard port.
remove_filter('template_redirect', 'redirect_canonical');

require_once('_inc/lp-database.php');
register_activation_hook(__FILE__, 'lp_db_install');
register_activation_hook(__FILE__, 'lp_db_install_data');

// https://codex.wordpress.org/Creating_Tables_with_Plugins
// Since 3.1 the activation function registered with register_activation_hook() is not called when a plugin is updated:
add_action('plugins_loaded', 'lp_db_install');

// Register filters
require_once('_inc/lp-menu-items.php');
add_action( 'admin_bar_menu', 'lp_admin_bar_menu', 500 );
add_action( 'admin_menu', 'lp_admin_menu' );

// Register shortcodes
require_once('_inc/lp-render-petition.php');
add_shortcode('local_petition', 'lp_render_petition');

require_once('_inc/lp-render-contact-form.php');
add_shortcode('local_petition_contact_form', 'lp_contact_form');

require_once('_inc/lp-supporter.php');
add_shortcode('supporter_map', 'lp_supporter_map');
add_shortcode('supporter_counter', 'lp_supporter_counter');
add_shortcode('supporter_carousel', 'lp_supporter_carousel');
add_shortcode('supporter_table', 'lp_supporter_table');

require_once('_inc/lp-campaign-map.php');
add_shortcode('campaign_map', 'lp_campaign_map');
add_shortcode('campaign_routes', 'lp_campaign_routes');

// Register AJAX handlers
add_action('wp_ajax_lp_get_supporters_json', 'lp_get_supporters_json_handler');
add_action('wp_ajax_nopriv_lp_get_supporters_json', 'lp_get_supporters_json_handler');
add_action('wp_ajax_lp_get_supporters_map_coordinates_json', 'lp_get_supporters_map_coordinates_json_handler');
add_action('wp_ajax_nopriv_lp_get_supporters_map_coordinates_json', 'lp_get_supporters_map_coordinates_json_handler');

// AJAX handlers only for logged in users
add_action('wp_ajax_lp_get_map_routes', 'lp_get_map_routes_json_handler');
add_action('wp_ajax_lp_add_route', 'lp_add_route_json_handler');
add_action('wp_ajax_lp_update_route', 'lp_update_route_json_handler');
add_action('wp_ajax_lp_get_users', 'lp_get_users_json_handler');
add_action('wp_ajax_lp_update_route_number_position', 'lp_update_route_number_position_json_handler');
add_action('wp_ajax_lp_get_visits', 'lp_get_visits_json_handler');
add_action('wp_ajax_lp_record_route_visit', 'lp_record_route_visit_json_handler');

wp_enqueue_style('local_petition_style', plugins_url('css/local_petition.css', __FILE__), false, LOCAL_PETITION_VERSION);
wp_enqueue_script('jscookie', plugins_url('js/js.cookie.min.js', __FILE__), array(), '3.0.5');
wp_enqueue_script('local_petition_js', plugins_url('js/local_petition.js', __FILE__), array(), LOCAL_PETITION_VERSION);
wp_enqueue_script('local_petition_maps', plugins_url('js/local_petition_maps.js', __FILE__), array(), LOCAL_PETITION_VERSION);
wp_register_script('recaptcha', 'https://www.google.com/recaptcha/api.js?render=' . reCAPTCHA_site_key);
wp_register_script('markerclusterer', 'https://unpkg.com/@googlemaps/markerclusterer/dist/index.min.js');
