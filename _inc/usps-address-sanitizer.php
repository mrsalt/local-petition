<?php

// Make a request to $url with $params (associative array).
function make_request($method, $url, $params, $headers = array())
{
    $ch = curl_init();
    $curl_opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => http_build_query($params)
    ];
    if (!empty($headers)) {
        $formatted_headers = array();
        foreach ($headers as $key => $value) {
            $formatted_headers[] = $key . ': ' . $value;
        }
        $curl_opts[CURLOPT_HTTPHEADER] = $formatted_headers;
    }
    if ($method === 'GET') {
        $curl_opts[CURLOPT_URL] = $url . '?' . http_build_query($params);
    } else {
        $curl_opts[CURLOPT_POSTFIELDS] = http_build_query($params);
    }
    curl_setopt_array($ch, $curl_opts);
    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($result === false) {
        throw new Exception('curl_exec() returned false: curl_error=' . curl_error($ch) . ', curl_get_info=' . var_export(curl_getinfo($ch), true));
    }
    return array(
        'http_code' => $http_code,
        'response' => $result
    );
}

function check_result($result)
{
    if ($result['http_code'] < 200 || $result['http_code'] >= 300) {
        throw new Exception('Request failed with HTTP code ' . $result['http_code'] . ', result = ' . var_export($result['response'], true));
    }
    return $result['response'];
}

function get_oauth_token()
{
    $temp_dir = sys_get_temp_dir();
    $oauth_file = $temp_dir . DIRECTORY_SEPARATOR . 'usps_oauth.json';
    // Get current time in epoch format
    $now = time();

    if (file_exists($oauth_file)) {
        $json_str = file_get_contents($oauth_file);
        $oauth_obj = json_decode($json_str, true); // associative array
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Error parsing usps_oauth.json: ' . json_last_error_msg());
        }

        if (isset($oauth_obj['issue_time'])) {
            // If issue_time is less than 7 hours ago, it should still be good.  Technically
            // it's supposed to be good for 8 hours.
            if ($oauth_obj['issue_time'] < ($now + 7 * 3600)) {
                return $oauth_obj['access_token'];
            }
        }
    }

    $usps_consumer_key = get_cfg_var('usps_consumer_key');
    if (!$usps_consumer_key)
        throw new Exception('No usps_consumer_key set in php.ini');
    $usps_consumer_secret = get_cfg_var('usps_consumer_secret');
    if (!$usps_consumer_secret)
        throw new Exception('No usps_consumer_secret set in php.ini');

    $payload = array(
        'grant_type' => 'client_credentials',
        'client_id' => $usps_consumer_key,
        'client_secret' => $usps_consumer_secret,
        'scope' => 'addresses'
    );

    $url = 'https://apis.usps.com/oauth2/v3/token';
    $response = make_request('POST', $url, $payload, array('Content-Type' => 'application/x-www-form-urlencoded'));
    $response = check_result($response);
    $obj = json_decode($response, true); // associative array

    $json_str = json_encode(array(
        'access_token' => $obj,
        'issue_time' => $now
    ));
    file_put_contents($oauth_file, $json_str);
    return $obj;
}

function sanitize_address($address)
{
    $usps_oauth = get_oauth_token();
    $url = 'https://apis.usps.com/addresses/v3/address';
    $params = array(
        'streetAddress' => $address['line_1'],
        'secondaryAddress' => $address['line_2'],
        'city' => $address['city'],
        'state' => $address['state']
    );
    if (array_key_exists('zip', $address)) {
        $params['ZIPCode'] = $address['zip'];
    }
    $result = make_request('GET', $url, $params, $headers = array('Authorization' => 'Bearer ' . $usps_oauth['access_token'], 'Content-Type' => 'application/x-www-form-urlencoded'));
    $response = json_decode($result['response'], true); // associative array
    if ($result['http_code'] != 200) {
        return array('Error' => $response['error']['message']);
    }
    $address = $response['address'];
    return array(
        'line_1' => $address['streetAddress'],
        'line_2' => $address['secondaryAddress'],
        'city' => $address['city'],
        'state' => $address['state'],
        'zip' => $address['ZIPCode'],
        'zip_ext' => $address['ZIPPlus4']
    );
}

function get_address_id($address)
{
    global $wpdb;
    $address_table_name = $wpdb->prefix . 'lp_address';
    $query = prepare_query("SELECT id FROM {$address_table_name} WHERE line_1 = %s AND line_2 = %s AND zip = %s", $address['line_1'], $address['line_2'], $address['zip']);
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
        'zip'    => $address['zip'],
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
// 3 parts:
// 13319 W Silverbrook Dr, Boise, ID 83713
// 4 parts:
// 13319 W Silverbrook Dr, Boise, ID 83713, USA <-- we assume this format if there are 4 parts
// 13319 W Silverbrook Dr, Apt B, Boise, ID 83713
// 5 parts:
// 13319 W Silverbrook Dr, Apt B, Boise, ID 83713, USA
function parse_address_with_commas($formatted_address)
{
    $parts = explode(', ', $formatted_address);
    if (count($parts) == 3 || count($parts) == 4)
        $zip_part = 2;
    else if (count($parts) == 5)
        $zip_part = 3;
    else
        throw new Exception('Address format unexpected: ' + $formatted_address);
    $city = strtoupper($parts[$zip_part - 1]);
    $sep = strpos($parts[$zip_part], ' ');
    if ($sep !== false) {
        $state = substr($parts[$zip_part], 0, $sep);
        $zip = substr($parts[$zip_part], $sep + 1);
    } else {
        $state = $parts[$zip_part];
        $zip = '';
    }

    return array(
        'line_1' => strtoupper($parts[0]),
        'line_2' => count($parts) == 5 ? strtoupper($parts[1]) : null,
        'city' => $city,
        'state' => $state,
        'zip' => $zip
    );
}
