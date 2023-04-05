<?php

function geocode($address)
{
    $api_key = get_cfg_var('google_maps_api_key');
    if (!$api_key)
        throw new Exception('No google_maps_api_key set in php.ini');

    $address_string = $address['line_1'];
    //if ($address['line_2'])
    //    $address_string .= ', '.$address['line_2'];
    $address_string .= ', ' . $address['city'];
    $address_string .= ', ' . $address['state'];
    $encoded_address = urlencode($address_string);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_URL => 'https://maps.googleapis.com/maps/api/geocode/json?address=' . $encoded_address . '&key=' . $api_key,
    ]);
    $result = curl_exec($ch);
    if ($result === false) {
        throw new Exception('curl_exec() returned false: curl_error=' . curl_error($ch) . ', curl_get_info=' . var_export(curl_getinfo($ch), true));
    }
    if (curl_getinfo($ch, CURLINFO_RESPONSE_CODE) != 200) {
        error_log('Geocode request failed.  curl_get_info=' . var_export(curl_getinfo($ch), true) . ', result = ' . var_export($result, true));
        curl_close($ch);
        return false;
    }
    curl_close($ch);

    error_log($result);
    $json = json_decode($result);
    if ($json->status != 'OK') {
        error_log('Geocode request failed.  $json =' . $result);
        error_log('Geocode request failed.  var_export($json) =' . var_export($json, true));
        return false;
    }
    $location = $json->results[0]->geometry->location;
    $ret = array(
        'latitude' => $location->lat,
        'longitude' => $location->lng
    );
    $address_components = $json->results[0]->address_components;
    foreach ($address_components as $component) {
        if (in_array('neighborhood', $component->types)) {
            $ret['neighborhood'] = $component->long_name;
        }
    }
    return $ret;
}
