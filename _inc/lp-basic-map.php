<?php

require_once('lp-init.php');
require_once('googlemaps.php');
require_once('usps-address-sanitizer.php');

function lp_basic_map($atts = [], $content = null)
{
    if (!set_campaign($atts['campaign'], $output)) {
        return $output;
    }
    //if (!array_key_exists('google-map-id', $atts)) {
    //    return '<p>Required attribute missing: google-map-id</p>';
    //}
    global $basic_map_id;
    $basic_map_id = 'basic-map';
    $extra_script = '';
    $interactive = array_key_exists('interactive', $atts) && $atts['interactive'] == 'yes';

    if (is_user_logged_in()) {
        $extra_script = ".then(() => { addAddItemButton(document.getElementById('$basic_map_id'), ".$atts['map-id'].") })\n";
    }
    if (array_key_exists('map-id', $atts) || array_key_exists('map-id', $_GET)) {
        $map_id = array_key_exists('map-id', $atts) ? $atts['map-id'] : $_GET['map-id'];
        $extra_script .= ".then(() => { loadMapMarkers(document.getElementById('$basic_map_id'), ".$map_id.") })\n";
        if (array_key_exists('localities', $atts) && $atts['localities'] == 'yes') {
            $extra_script .= ".then(() => { loadMapLocalities(document.getElementById('$basic_map_id'), ".$map_id.") })\n";
        }
    }

    $googleMapId = array_key_exists('google-map-id', $atts) ? $atts['google-map-id'] : null;
    $locality = array_key_exists('locality', $atts) ? $atts['locality'] : null;
    $mapTypeId = array_key_exists('maptypeid', $atts) ? $atts['maptypeid'] : null;
    return lp_create_map_element($basic_map_id, 'campaign-map', true, $locality, $atts['lat'], $atts['lng'], $atts['zoom'], $googleMapId, $mapTypeId, $extra_script, $interactive);
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
    foreach ($markers as $idx => $values) {
        $markers[$idx]['latitude'] = floatval($values['latitude']);
        $markers[$idx]['longitude'] = floatval($values['longitude']);
        $markers[$idx]['radius'] = intval($values['radius']);
    }
    wp_send_json($markers);
    wp_die();
}

function lp_place_map_item_json_handler() {
    if (!array_key_exists('campaign', $_SESSION)) {
        wp_send_json(array('error' => 'No campaign found in $_SESSION'), 500);
        wp_die();
    }

    if (!is_user_logged_in()) {
        wp_send_json(array('error' => 'User not logged in'), 401);
        wp_die();
    }

    $formatted_address = wp_unslash($_GET['address']);
    try {
        $address = parse_address_with_commas($formatted_address);
    } catch (Exception $e) {
        wp_send_json(array('error' => 'Address parsing failed', 'details' => $e->getMessage()), 400);
        wp_die();
    }
    $sanitized_address = sanitize_address($address);
    if (array_key_exists('Error', $sanitized_address)) {
        wp_send_json(array('error' => 'Address sanitization failed', 'details' => $sanitized_address), 400);
        wp_die();
    }
    $address_id = store_address($sanitized_address);
    $coordinates = geocode($address);
    if ($coordinates)
        update_coordinates($address_id, $coordinates);

    $values = array(
        'name' => wp_unslash($_GET['name']),
        'address_id' => $address_id,
        'map_id' => $_GET['map_id'],
    );

    global $wpdb;
    if ($_GET['type'] == 'Locality') {
        $table_name = $wpdb->prefix . 'lp_map_localities';
        $values['color'] = wp_unslash($_GET['color']);
    }
    else if ($_GET['type'] == 'Marker') {
        $table_name = $wpdb->prefix . 'lp_marker';
        $values['icon'] = wp_unslash($_GET['markerType']);
        $values['radius'] = wp_unslash($_GET['radius']);
        $values['radius_color'] = wp_unslash($_GET['color']);
        if (array_key_exists('locality_id', $_GET) && $_GET['locality_id'] != '')
            $values['locality_id'] = wp_unslash($_GET['locality_id']);
    } else {
        wp_send_json(array('error' => 'Invalid type: ' . $_GET['type']), 400);
        wp_die();
    }

    $result = $wpdb->insert($table_name, $values);
    if ($result === false) {
        throw new Exception('Failed to insert into ' . $table_name);
    }
    if ($_GET['type'] == 'Locality')
        lp_load_localities_json_handler(id: intval($wpdb->insert_id));
    else if ($_GET['type'] == 'Marker')
        lp_load_markers_json_handler(id: intval($wpdb->insert_id));
}

function lp_delete_marker_json_handler() {
    if (!array_key_exists('campaign', $_SESSION)) {
        wp_send_json(array('error' => 'No campaign found in $_SESSION'), 500);
        wp_die();
    }

    if (!is_user_logged_in()) {
        wp_send_json(array('error' => 'User not logged in'), 401);
        wp_die();
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'lp_marker';
    $values = array(
        'id' => $_GET['id']
    );
    $result = $wpdb->delete($table_name, $values);
    if ($result === false) {
        throw new Exception('Failed to delete from ' . $table_name);
    }
    wp_send_json(true);
    wp_die();
}

function lp_load_localities_json_handler($id = null) {
    if (!array_key_exists('campaign', $_SESSION)) {
        wp_send_json(array('error' => 'No campaign found in $_SESSION'), 500);
        wp_die();
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'lp_map_localities';
    $address_table = $wpdb->prefix . 'lp_address';

    $query = "SELECT locality.*, address.line_1, address.line_2, address.latitude, address.longitude, address.city, address.`state`
            FROM `$table_name` locality
            JOIN `$address_table` address ON address.id = locality.address_id
            WHERE locality.map_id = %d";
    $params = array($_GET['map_id']);

    if ($id) {
        $query .= " AND locality.id = %d";
        $params[] = $id;
    }
    $query = $wpdb->prepare($query, $params);
    $localities = $wpdb->get_results($query, ARRAY_A);
    foreach ($localities as $idx => $values) {
        $localities[$idx]['latitude'] = floatval($values['latitude']);
        $localities[$idx]['longitude'] = floatval($values['longitude']);
    }
    wp_send_json($localities);
    wp_die();
}
