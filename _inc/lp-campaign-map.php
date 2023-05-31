<?php

require_once('lp-init.php');
require_once('googlemaps.php');
require_once('usps-address-sanitizer.php');

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
    // mapId could be configured in admin pages
    $mapId = '8c6c1d4242e1a575';
    return lp_create_map_element($campaign_map_id, 'campaign-map', true, $atts['lat'], $atts['lng'], $atts['zoom'], $mapId, $extra_script);
}

function load_route_info($id = null)
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

    $query = "SELECT route.*, users_created_by.display_name 'created_by', users_assigned_to.display_name 'assigned_to'
            FROM `$table_name` route
            JOIN `$wp_user_table` users_created_by ON users_created_by.id = route.created_by_wp_user_id
            LEFT JOIN `$wp_user_table` users_assigned_to ON users_assigned_to.id = route.assigned_to_wp_user_id
            WHERE route.campaign_id = %d";
    $params = array($_SESSION['campaign']->id);

    if (isset($id)) {
        $query .= " AND route.id = %d";
        $params[] = $id;
    }
    $query = $wpdb->prepare($query, $params);
    return array(
        'user_id' => wp_get_current_user()->ID,
        'is_editor' => current_user_can('edit_posts'),
        'routes' => $wpdb->get_results($query, ARRAY_A)
    );
}

function lp_campaign_routes($atts = [], $content = null)
{
    if (!set_campaign($atts['campaign'], $output)) {
        return $output;
    }
    if (!is_user_logged_in()) {
        return "<p>Please <a href=\"/wp-login.php\">login</a> to access this page</p>";
    }
    // Return a list of routes.
    // If an editor, include ability to add route.
    global $campaign_map_id;
    $content = '';
    if (current_user_can('edit_posts'))
        $content .= "<button onclick=\"beginAddingRoute.call(this, document.getElementById('$campaign_map_id'))\">Add Route</button>";

    $content .= '<div id="existing-routes"></div>';
    return $content;
}

function lp_get_map_routes_json_handler()
{
    wp_send_json(load_route_info());
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
    if (array_key_exists('number_position', $_GET))
        $values['number_position'] = wp_unslash($_GET['number_position']);
    $result = $wpdb->insert($table_name, $values);
    if ($result === false) {
        throw new Exception('Failed to insert into ' . $table_name);
    }
    wp_send_json(load_route_info(id: intval($wpdb->insert_id)));
    wp_die();
}

function lp_update_route_json_handler()
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
    if ($_GET['route_action'] == 'assign') {
        $user_id = current_user_can('edit_posts') ? $_GET['user_id'] : wp_get_current_user()->ID;
        $result = $wpdb->update(
            $table_name,
            array(
                'assigned_to_wp_user_id' => $user_id,
                'status' => 'Assigned'
            ),
            array(
                'id' => $_GET['id']
            )
        );
    } else if ($_GET['route_action'] == 'unassign') {
        $result = $wpdb->update(
            $table_name,
            array(
                'assigned_to_wp_user_id' => null,
                'status' => 'Unassigned'
            ),
            array(
                'id' => $_GET['id']
            )
        );
    } else if ($_GET['route_action'] == 'delete') {
        $result = $wpdb->delete(
            $table_name,
            array(
                'id' => $_GET['id']
            )
        );
    } else if ($_GET['route_action'] == 'complete') {
        $result = $wpdb->update(
            $table_name,
            array(
                'status' => 'Complete'
            ),
            array(
                'id' => $_GET['id']
            )
        );
    }
    if ($result === false) {
        throw new Exception('Failed to ' . $_GET['route_action'] . ', ' . $table_name);
    }
    // send updated version back:
    wp_send_json(load_route_info(id: intval($_GET['id'])));
    wp_die();
}

function lp_update_route_number_position_json_handler()
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
    $result = $wpdb->update(
        $table_name,
        array(
            'number_position' => wp_unslash($_GET['position'])
        ),
        array(
            'id' => $_GET['id']
        )
    );
    if ($result === false) {
        throw new Exception('Failed to update position, ' . $table_name);
    }
    exit(0);
}

function get_visit($campaign_id, $address_id)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'lp_residence_visit';
    $query = prepare_query("SELECT address_id FROM {$table_name} WHERE campaign_id = %d AND address_id = %d", $campaign_id, $address_id);
    $results = $wpdb->get_results($query);
    if (count($results) == 0) {
        return null;
    }
    return $results[0]->address_id;
}

function load_visit_info($id = null)
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
    $table_name = $wpdb->prefix . 'lp_residence_visit';
    $wp_user_table = $wpdb->prefix . 'users';
    $address_table = $wpdb->prefix . 'lp_address';

    $query = "SELECT visit.*, users_created_by.display_name 'created_by', address.line_1, address.line_2, address.latitude, address.longitude
            FROM `$table_name` visit
            JOIN `$wp_user_table` users_created_by ON users_created_by.id = visit.created_by_wp_user_id
            JOIN `$address_table` address ON address.id = visit.address_id
            WHERE visit.campaign_id = %d";
    $params = array($_SESSION['campaign']->id);

    if (isset($id)) {
        $query .= " AND visit.address_id = %d";
        $params[] = $id;
    }
    $query = $wpdb->prepare($query, $params);
    $visits = $wpdb->get_results($query, ARRAY_A);
    foreach ($visits as $idx => $values) {
        $visits[$idx]['latitude'] = floatval($values['latitude']);
        $visits[$idx]['longitude'] = floatval($values['longitude']);
    }
    return array(
        'user_id' => wp_get_current_user()->ID,
        'is_editor' => current_user_can('edit_posts'),
        'visits' => $visits
    );
}

function lp_get_visits_json_handler()
{
    wp_send_json(load_visit_info());
    wp_die();
}

function lp_record_route_visit_json_handler()
{
    if (!array_key_exists('campaign', $_SESSION)) {
        wp_send_json(array('error' => 'No campaign found in $_SESSION'), 500);
        wp_die();
    }

    if (!is_user_logged_in()) {
        wp_send_json(array('error' => 'User not logged in'), 401);
        wp_die();
    }

    // Do we already have this address in our DB?
    //13319 W Silverbrook Dr, Boise, ID 83713, USA
    $formatted_address = wp_unslash($_GET['formatted_address']);
    $parts = explode(', ', $formatted_address);
    if (count($parts) == 4)
        $zip_part = 2;
    else if (count($parts) == 5)
        $zip_part = 3;
    else
        throw new Exception('Address format unexpected: ' + $formatted_address);
    $city = strtoupper($parts[$zip_part - 1]);
    $sep = strpos($parts[$zip_part], ' ');
    if ($sep === false)
        throw new Exception('Address format unexpected: ' + $formatted_address);
    $state = substr($parts[$zip_part], 0, $sep);
    $zip = substr($parts[$zip_part], $sep + 1);

    $address = array('line_1' => strtoupper($parts[0]), 'line_2' => count($parts) == 5 ? strtoupper($parts[1]) : null, 'city' => $city, 'state' => $state, 'zip' => $zip);
    $address_id = get_address_id($address);

    if ($address_id) {
        $visit_id = get_visit($_SESSION['campaign']->id, $address_id);
    } else {
        $visit_id = null;
        $address_id = store_address($address);
        $coordinates = geocode($address);
        if ($coordinates)
            update_coordinates($address_id, $coordinates);
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'lp_residence_visit';
    if ($visit_id) {
        $result = $wpdb->update($table_name, array(
            'status' => $_GET['status']
        ), array(
            'campaign_id' => $_SESSION['campaign']->id,
            'address_id' => $address_id
        ));
        if ($result === false) {
            throw new Exception('Failed to update ' . $table_name);
        }
    } else {
        $values = array(
            'campaign_id' => $_SESSION['campaign']->id,
            'created_by_wp_user_id' => wp_get_current_user()->ID,
            'address_id' => $address_id,
            'status' => $_GET['status']
        );
        if (array_key_exists('route_id', $_GET))
            $values['route_id'] = $_GET['route_id'];
        $result = $wpdb->insert($table_name, $values);
        if ($result === false) {
            throw new Exception('Failed to insert into ' . $table_name);
        }
        $visit_id = $address_id;
    }
    wp_send_json(load_visit_info(id: $visit_id));
    wp_die();
}
