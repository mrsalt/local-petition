<?php

// Census datasets the map can show.  The ids are used by js/census.js.
// 'path' is relative to https://api.census.gov/data/ and 'variable' is the
// column holding the number we want at the block group level.
const LP_CENSUS_DATASETS = array(
    'dec2020_population' => array('path' => '2020/dec/pl', 'variable' => 'P1_001N'),
    'acs2023_population' => array('path' => '2023/acs/acs5', 'variable' => 'B01003_001E'),
    'acs2023_under18' => array('path' => '2023/acs/acs5', 'variable' => 'B09001_001E'),
    'acs2023_households' => array('path' => '2023/acs/acs5', 'variable' => 'B11001_001E'),
);

// Returns {GEOID: value} for every block group in one county.  The Census API
// requires a key (set census_api_key in php.ini), so the browser asks us
// instead of calling the Census API directly.  Results are cached.
function lp_census_population_json_handler() {
    $api_key = get_cfg_var('census_api_key');
    if (!$api_key) {
        wp_send_json(array('error' => 'No census_api_key set in php.ini'), 500);
        wp_die();
    }

    $dataset_id = isset($_GET['dataset']) ? $_GET['dataset'] : '';
    $state = isset($_GET['state']) ? $_GET['state'] : '';
    $county = isset($_GET['county']) ? $_GET['county'] : '';
    if (!array_key_exists($dataset_id, LP_CENSUS_DATASETS) || !preg_match('/^\d{2}$/', $state) || !preg_match('/^\d{3}$/', $county)) {
        wp_send_json(array('error' => 'Invalid dataset, state or county'), 400);
        wp_die();
    }
    $dataset = LP_CENSUS_DATASETS[$dataset_id];

    $cache_key = 'lp_census_' . md5($dataset_id . $state . $county);
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        wp_send_json($cached);
        wp_die();
    }

    $url = 'https://api.census.gov/data/' . $dataset['path'] .
        '?get=' . $dataset['variable'] .
        '&for=' . rawurlencode('block group:*') .
        '&in=' . rawurlencode("state:$state county:$county tract:*") .
        '&key=' . rawurlencode($api_key);
    $response = wp_remote_get($url, array('timeout' => 30));
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) != 200) {
        wp_send_json(array('error' => 'Census API request failed'), 502);
        wp_die();
    }
    $rows = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($rows) || count($rows) < 1) {
        wp_send_json(array('error' => 'Unexpected Census API response'), 502);
        wp_die();
    }

    $header = array_flip($rows[0]);
    $result = array();
    foreach (array_slice($rows, 1) as $row) {
        $geoid = $row[$header['state']] . $row[$header['county']] . $row[$header['tract']] . $row[$header['block group']];
        // The Census API uses large negative numbers for "not available".
        $result[$geoid] = max(0, intval($row[$header[$dataset['variable']]]));
    }
    set_transient($cache_key, $result, 30 * DAY_IN_SECONDS);
    wp_send_json($result);
    wp_die();
}
