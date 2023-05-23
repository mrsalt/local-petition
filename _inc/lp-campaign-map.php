<?php

require_once('lp-init.php');
require_once('googlemaps.php');

function lp_campaign_map($atts = [], $content = null)
{
    if (!set_campaign($atts['campaign'], $output)) {
        return $output;
    }
    if (!is_user_logged_in()) {
        return "<p>Please <a href=\"/wp-login.php\">login</a> to access this page</p>";
        //auth_redirect();
    }
    global $campaign_map_id;
    $campaign_map_id = 'campaign-map';
    $extra_script = ".then(() => { addMapSupporterOverlays(document.getElementById('$campaign_map_id')) })" .
        ".then(() => { addMapRoutes(document.getElementById('$campaign_map_id')) })";
    return lp_create_map_element($campaign_map_id, 'campaign-map', true, $atts['lat'], $atts['lng'], $atts['zoom'], $extra_script);
}

function load_routes($id = null)
{
    if (!array_key_exists('campaign', $_SESSION)) {
        wp_send_json(array('error' => 'No campaign found in $_SESSION'), 500);
        wp_die();
    }

    if (!is_user_logged_in()) {
        wp_send_json(array('error' => 'User not logged in'), 500);
        wp_die();
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'lp_route';
    $wp_user_table = $wpdb->prefix . 'users';

    $query = "SELECT route.*, users_created_by.display_name
            FROM `$table_name` route
            JOIN `$wp_user_table` users_created_by ON users_created_by.id = route.created_by_wp_user_id
            WHERE route.campaign_id = %d";
    $params = array($_SESSION['campaign']->id);

    if (isset($id)) {
        $query .= " AND route.id = %d";
        $params[] = $id;
    }
    $query = $wpdb->prepare($query, $params);
    return $wpdb->get_results($query, ARRAY_A);
}

function lp_campaign_routes($atts = [], $content = null)
{
    if (!set_campaign($atts['campaign'], $output)) {
        return $output;
    }
    // Return a list of routes.
    // If an editor, include ability to add route.
    global $campaign_map_id;
    $content = '';
    if (current_user_can('edit_posts'))
        $content .= "<button onclick=\"beginAddingRoute.call(this, document.getElementById('$campaign_map_id'))\">Add Route</button>";

    $content .= '<pre>' . json_encode(load_routes()) . '</pre>';
    return $content;
}

function lp_get_map_routes_json_handler()
{
    wp_send_json(load_routes());
    wp_die();
}

function lp_add_route_json_handler()
{
    if (!array_key_exists('campaign', $_SESSION)) {
        wp_send_json(array('error' => 'No campaign found in $_SESSION'), 500);
        wp_die();
    }

    if (!is_user_logged_in()) {
        wp_send_json(array('error' => 'User not logged in'), 401);
        wp_die();
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'lp_route';
    $bounds = wp_unslash($_GET['bounds']);
    $east = null;
    $west = null;
    $north = null;
    $south = null;
    foreach (json_decode($bounds) as $coord) {
        if (!isset($east)) {
            $east = $coord->lng;
            $west = $coord->lng;
            $north = $coord->lat;
            $south = $coord->lat;
            continue;
        }
        if ($coord->lng < $west) $west = $coord->lng;
        else if ($coord->lng > $east) $east = $coord->lng;
        if ($coord->lat < $south) $south = $coord->lat;
        else if ($coord->lat > $north) $north = $coord->lat;
    }

    $values = array(
        'campaign_id' => $_SESSION['campaign']->id,
        'created_by_wp_user_id' => wp_get_current_user()->ID,
        'number_residences' => $_GET['residences'],
        'neighborhood' => wp_unslash($_GET['neighborhood']),
        'bounds' => $bounds,
        'east' => $east,
        'west' => $west,
        'north' => $north,
        'south' => $south
    );
    $result = $wpdb->insert($table_name, $values);
    if ($result === false) {
        throw new Exception('Failed to insert into ' . $table_name);
    }
    wp_send_json(load_routes(id: intval($wpdb->insert_id)));
    wp_die();
}
