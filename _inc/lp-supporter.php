<?php

require_once('googlemaps.php');

function lp_supporter_map($atts = [], $content = null)
{
    $id = lp_get_map_id();
    list($gridLat, $gridLng) = explode(",", $atts['gridcenter']);
    list($latStep, $lngStep) = explode(",", $atts['gridstep']);
    $minSupporters = array_key_exists('minsupporters', $atts) ? $atts['minsupporters'] : 'null';
    $extra_script = ".then(() => { addMapSupporterOverlays(document.getElementById('$id'), $gridLat, $gridLng, $latStep, $lngStep, $minSupporters) })";
    return lp_create_map_element($id, 'supporter-map', is_user_logged_in(), $atts['lat'], $atts['lng'], $atts['zoom'], $extra_script);
}

function lp_get_supporters_map_coordinates_json_handler()
{
    if (!array_key_exists('campaign', $_SESSION)) {
        wp_send_json(array('error' => 'No campaign found in $_SESSION'), 500);
        wp_die();
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'lp_signer';
    $address_table = $wpdb->prefix . 'lp_address';
    $extra_details = is_user_logged_in() ? 'signer.id, name, address.latitude AS lat, address.longitude AS lng' : '';
    if ($_GET['lat_center'] !== 'undefined' && $_GET['lat_box_size'] !== 'undefined') {
        if ($extra_details) $extra_details .= ',';
        $query = "SELECT $extra_details
                FLOOR((address.latitude - %f) / %f) AS lat_box
                , FLOOR((address.longitude - %f) / %f) AS lng_box
                FROM `$table_name` signer
                JOIN `$address_table` address ON address.id = signer.address_id WHERE signer.is_supporter = 1 AND signer.campaign_id = %d";
        $query = $wpdb->prepare(
            $query,
            $_GET['lat_center'],
            $_GET['lat_box_size'],
            $_GET['lng_center'],
            $_GET['lng_box_size'],
            $_SESSION['campaign']->id
        );
    } else if ($extra_details) {
        $query = "SELECT $extra_details
                FROM `$table_name` signer
                JOIN `$address_table` address ON address.id = signer.address_id WHERE signer.is_supporter = 1 AND signer.campaign_id = %d";
        $query = $wpdb->prepare(
            $query,
            $_SESSION['campaign']->id
        );
    }
    $result = $wpdb->get_results($query, ARRAY_A);
    wp_send_json($result);
    wp_die();
}

function lp_supporter_counter($atts = [], $content = null)
{
    global $wpdb;
    $query = $atts['query'];
    $query = str_replace('${wpdb}', $wpdb->prefix, $query);
    $query = str_replace("lt;", "<", $query);
    $message = htmlspecialchars($atts['message']);

    $result = $wpdb->get_results($wpdb->prepare($query));
    if (!is_array($result)) {
        return '<error>Query failed: ' . $query . '</error>';
    }
    foreach ($result[0] as $val) {
        $count = $val;
        break;
    }
    if (array_key_exists('min', $atts)) {
        $min = intval($atts['min']);
        if ($count < $min) {
            if (is_user_logged_in()) {
                $message .= '<p style="color: lightgray"><i>This counter is hidden from non-logged in users until the value = ' . $min . '</i></p>';
            } else {
                return;
            }
        }
    }
    $id = 'counter-' . md5($query);
    $script = '';
    if ($count > 0) {
        $timeInterval = 1000 / $count;
        $script = "<script>animateCounter(document.getElementById('$id'), $count, $timeInterval);</script>";
    }
    return
        "\n<div class=\"counter-box\">
      <div class=\"counter\" id=\"$id\">" . $count . "</div>
      <div class=\"message\">" . $message . "</div>
    </div>" . $script;
}

function lp_supporter_carousel($atts = [], $content = null)
{
    if (!isset($atts['campaign'])) {
        return 'Error: no "campaign" attribute found in shortcode <b>supporter_carousel</b>';
    }

    $output = '';
    if (!set_campaign($atts['campaign'], $output)) {
        return $output;
    }

    static $supporter_boxes = 0;
    $supporter_boxes++;
    $id = "supporter-box-$supporter_boxes";

    // We want to show all the available: picture, name, title, comments.  Show blank image if no picture uploaded.
    // Return frame, and make request to get list of supporters via JSON.
    return "\n<div id=\"$id\" class=\"supporter-carousel\">
              <image class=\"supporter-photo\"></image>
              <div class=\"nav-controls\"><span id=\"previous\">&#x23EE;</span><span id=\"play_pause\">&#x23EF;</span><span id=\"next\">&#x23ED;</span></div>
              <div class=\"supporter-name\"></div>
              <div class=\"supporter-title\"></div>
              <div class=\"supporter-comments\"></div>
            </div>
            <script>loadSupporterBox(document.getElementById('$id'), '" . $_SESSION['campaign']->slug . "')</script>";
}

function lp_get_supporters_json_handler()
{
    if (!array_key_exists('campaign', $_SESSION)) {
        wp_send_json(array('error' => 'No campaign found in $_SESSION'), 500);
        wp_die();
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'lp_signer';
    $query = "SELECT id, name, title, comments, photo_file
              FROM $table_name
              WHERE share_granted = 1 AND campaign_id = %d
                AND status = 'Approved'
                AND ((comments IS NOT NULL AND comments != '') OR
                     (photo_file IS NOT NULL AND photo_file != '') OR
                     (title IS NOT NULL AND title != ''))";
    $result = $wpdb->get_results($wpdb->prepare($query, $_SESSION['campaign']->id), ARRAY_A);
    shuffle($result);
    wp_send_json($result);
    wp_die();
}

function lp_get_users_json_handler()
{
    if (!array_key_exists('campaign', $_SESSION)) {
        wp_send_json(array('error' => 'No campaign found in $_SESSION'), 500);
        wp_die();
    }

    if (!current_user_can('edit_posts')) {
        wp_send_json(array('error' => 'Accessing list of users requires edit_posts permission'), 403);
        wp_die();
    }

    global $wpdb;
    $users = $wpdb->prefix . 'users';
    $query = "SELECT ID, display_name
              FROM `$users`
              ORDER BY display_name";
    $result = $wpdb->get_results($wpdb->prepare($query), ARRAY_A);
    shuffle($result);
    wp_send_json($result);
    wp_die();
}
