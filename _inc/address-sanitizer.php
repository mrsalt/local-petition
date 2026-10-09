<?php

require_once('googlemaps.php');

// Validate and normalize an address using the Google Geocoding API.
// Returns the normalized address (line_1, line_2, city, state, zip, zip_ext) plus a
// 'coordinates' entry (latitude, longitude, neighborhood), or array('Error' => message).
function sanitize_address($address)
{
    $address_string = $address['line_1'];
    if (!empty($address['line_2']))
        $address_string .= ', ' . $address['line_2'];
    $address_string .= ', ' . $address['city'] . ', ' . $address['state'];
    if (!empty($address['zip']))
        $address_string .= ' ' . $address['zip'];

    $json = google_geocode_request($address_string);
    if ($json === false || $json->status != 'OK' || empty($json->results)) {
        $status = $json ? $json->status : 'request failed';
        return array('Error' => 'Address could not be verified (' . $status . ')', 'params' => $address);
    }

    $result = $json->results[0];
    if (!empty($result->partial_match)) {
        return array('Error' => 'Address could not be verified exactly.  Please check it and try again.', 'params' => $address);
    }

    $components = array();
    foreach ($result->address_components as $component) {
        foreach ($component->types as $type) {
            $components[$type] = $component;
        }
    }
    foreach (array('street_number', 'route', 'locality', 'administrative_area_level_1', 'postal_code') as $required) {
        if (!isset($components[$required])) {
            return array('Error' => 'Address is not a complete street address (missing ' . $required . ')', 'params' => $address);
        }
    }

    $line_2 = !empty($address['line_2']) ? strtoupper($address['line_2']) : null;
    if (isset($components['subpremise']))
        $line_2 = strtoupper($components['subpremise']->long_name);

    return array(
        'line_1' => strtoupper($components['street_number']->long_name . ' ' . $components['route']->short_name),
        'line_2' => $line_2,
        'city' => strtoupper($components['locality']->long_name),
        'state' => strtoupper($components['administrative_area_level_1']->short_name),
        'zip' => $components['postal_code']->long_name,
        'zip_ext' => isset($components['postal_code_suffix']) ? $components['postal_code_suffix']->long_name : null,
        'coordinates' => geocode_result_to_coordinates($result)
    );
}


function get_address_id($address)
{
    global $wpdb;
    $address_table_name = $wpdb->prefix . 'lp_address';
    if (array_key_exists('zip', $address))
        $query = prepare_query("SELECT id FROM {$address_table_name} WHERE line_1 = %s AND line_2 = %s AND zip = %s", $address['line_1'], $address['line_2'], $address['zip']);
    else
        $query = prepare_query("SELECT id FROM {$address_table_name} WHERE line_1 = %s AND line_2 = %s AND city = %s AND `state` = %s", $address['line_1'], $address['line_2'], $address['city'], $address['state']);
    $results = $wpdb->get_results($query);
    if (count($results) == 0) {
        return null;
    }
    return intval($results[0]->id);
}

function store_address($address, $normalized_id = null)
{
    $id = get_address_id($address);
    if ($id != null) return $id;

    $values = array(
        'line_1' => $address['line_1'],
        'line_2' => $address['line_2'],
        'city'   => $address['city'],
        'state'  => $address['state'],
        'zip'    => $address['zip'] ?? null,
        'zip_ext' => $address['zip_ext'] ?? null,
        'normalized_id' => $normalized_id
    );

    global $wpdb;
    $address_table_name = $wpdb->prefix . 'lp_address';
    $wpdb->insert(
        $address_table_name,
        $values
    );
    return intval($wpdb->insert_id);
}

function update_coordinates($address_id, $coordinates)
{
    global $wpdb;
    $address_table_name = $wpdb->prefix . 'lp_address';
    $wpdb->update(
        $address_table_name,
        $coordinates,
        array('id' => $address_id)
    );
}

// Number of parts = number of commas plus one.
// 3-4 parts:
// 13319 W Silverbrook Dr, Boise, ID[,] 83713
// 4-5 parts:
// 13319 W Silverbrook Dr, Boise, ID[,] 83713, USA <-- we assume this format if there are 4 parts
// 13319 W Silverbrook Dr, Apt B, Boise, ID[,] 83713
// 5-6 parts:
// 13319 W Silverbrook Dr, Apt B, Boise, ID[,] 83713, USA
function parse_address_with_commas($formatted_address)
{
    // Single pattern: line1[, line2], city, ST[,]? ZIP [, country]
    $pattern = '/^\s*(.+?)\s*(?:,\s*(.+?)\s*)?,\s*([^,]+?)\s*,\s*([A-Za-z]{2})\s*,?\s*(\d{5}(?:-\d{4})?)\s*(?:,.*)?$/u';

    if (preg_match($pattern, $formatted_address, $m)) {
        $line_1 = strtoupper($m[1]);
        $line_2 = isset($m[2]) && $m[2] !== '' ? strtoupper($m[2]) : null;
        $city = strtoupper($m[3]);
        $state = strtoupper($m[4]);
        $zip = $m[5];

        return array(
            'line_1' => $line_1,
            'line_2' => $line_2,
            'city' => $city,
            'state' => $state,
            'zip' => $zip
        );
    }

    throw new Exception('Address format invalid.  Expected format: line1[, line2], city, ST ZIP');
}
