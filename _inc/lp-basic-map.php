<?php

require_once('lp-init.php');
require_once('googlemaps.php');
require_once('usps-address-sanitizer.php');

function lp_basic_map($atts = [], $content = null)
{
    if (!set_campaign($atts['campaign'], $output)) {
        return $output;
    }
    if (!array_key_exists('map-id', $atts)) {
        return '<p>Required attribute missing: map-id</p>';
    }
    if (!array_key_exists('google-map-id', $atts)) {
        return '<p>Required attribute missing: google-map-id</p>';
    }
    global $basic_map_id;
    $basic_map_id = 'basic-map';
    $extra_script = '';
    if (is_user_logged_in())
        $extra_script = ".then(() => { addAddMarkerButton(document.getElementById('$basic_map_id'), ".$atts['map-id'].") })\n";
    $extra_script .= ".then(() => { loadMapMarkers(document.getElementById('$basic_map_id'), ".$atts['map-id'].") })\n";
    $googleMapId = $atts['google-map-id'];
    return lp_create_map_element($basic_map_id, 'campaign-map', true, $atts['lat'], $atts['lng'], $atts['zoom'], $googleMapId, $extra_script);
}

function lp_load_markers_json_handler($id = null) {
    if (!array_key_exists('campaign', $_SESSION)) {
        wp_send_json(array('error' => 'No campaign found in $_SESSION'), 500);
        wp_die();
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'lp_marker';
    $address_table = $wpdb->prefix . 'lp_address';

    $query = "SELECT marker.*, address.line_1, address.line_2, address.latitude, address.longitude, address.city, address.`state`
            FROM `$table_name` marker
            JOIN `$address_table` address ON address.id = marker.address_id
            WHERE marker.map_id = %d";
    $params = array($_GET['map_id']);

    if ($id) {
        $query .= " AND marker.id = %d";
        $params[] = $id;
    }
    $query = $wpdb->prepare($query, $params);
    $markers = $wpdb->get_results($query, ARRAY_A);
    foreach ($visits as $idx => $values) {
        $markers[$idx]['latitude'] = floatval($values['latitude']);
        $markers[$idx]['longitude'] = floatval($values['longitude']);
    }
    wp_send_json($markers);
    wp_die();
}

function lp_place_marker_json_handler() {
    if (!array_key_exists('campaign', $_SESSION)) {
        wp_send_json(array('error' => 'No campaign found in $_SESSION'), 500);
        wp_die();
    }

    if (!is_user_logged_in()) {
        wp_send_json(array('error' => 'User not logged in'), 401);
        wp_die();
    }

    $formatted_address = wp_unslash($_GET['address']);
    $address = parse_address_with_commas($formatted_address);
    $sanitized_address = sanitize_address($address);
    $address_id = store_address($address);
    $coordinates = geocode($address);
    if ($coordinates)
        update_coordinates($address_id, $coordinates);

    global $wpdb;
    $table_name = $wpdb->prefix . 'lp_marker';
    $values = array(
        'name' => wp_unslash($_GET['name']),
        'address_id' => $address_id,
        'map_id' => $_GET['map_id'],
        'icon' => wp_unslash($_GET['type'])
    );
    $result = $wpdb->insert($table_name, $values);
    if ($result === false) {
        throw new Exception('Failed to insert into ' . $table_name);
    }
    lp_load_markers_json_handler(id: intval($wpdb->insert_id));
}

