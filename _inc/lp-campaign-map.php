<?php

require_once('googlemaps.php');

function lp_campaign_map($atts = [], $content = null)
{
    if (!is_user_logged_in()) {
        auth_redirect();
    }
    $id = 'campaign-map';
    $extra_script = ".then(() => { addMapSupporterOverlays(document.getElementById('$id')) })" .
        ".then(() => { addMapRoutes(document.getElementById('$id')) })";
    return lp_create_map_element($id, 'campaign-map', true, $atts['lat'], $atts['lng'], $atts['zoom'], $extra_script);
}

function lp_campaign_routes($atts = [], $content = null)
{
    // Return a list of routes.
    // If an editor, include ability to add route.
}

function lp_get_map_routes_json_handler()
{
    if (!array_key_exists('campaign', $_SESSION)) {
        wp_send_json(array('error' => 'No campaign found in $_SESSION'), 500);
        wp_die();
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'lp_signer';
    $address_table = $wpdb->prefix . 'lp_address';
    $lat_center = $_GET['lat_center'];
    $lng_center = $_GET['lng_center'];
    $lat_box_size = $_GET['lat_box_size'];
    $lng_box_size = $_GET['lng_box_size'];
    $extra_details = is_user_logged_in() ? 'signer.id, name, address.latitude AS lat, address.longitude AS lng,' : '';
    $query = "SELECT $extra_details
              FLOOR((address.latitude - %f) / %f) AS lat_box
            , FLOOR((address.longitude - %f) / %f) AS lng_box
            FROM `$table_name` signer
            JOIN `$address_table` address ON address.id = signer.address_id WHERE signer.is_supporter = 1 AND signer.campaign_id = %d";
    $query = $wpdb->prepare(
        $query,
        $lat_center,
        $lat_box_size,
        $lng_center,
        $lng_box_size,
        $_SESSION['campaign']->id
    );

    $result = $wpdb->get_results($query, ARRAY_A);
    wp_send_json($result);
    wp_die();
}
