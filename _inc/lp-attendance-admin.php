<?php

function lp_attendance_candidates()
{
    global $wpdb;
    $signer_table = $wpdb->prefix . 'lp_signer';
    $address_table = $wpdb->prefix . 'lp_address';
    $rows = $wpdb->get_results(
        "SELECT signer.id, signer.name, address.city
         FROM `$signer_table` signer
         LEFT JOIN `$address_table` address ON address.id = signer.address_id
         ORDER BY signer.name",
        ARRAY_A
    );
    return array_map(function ($row) {
        return ['id' => (int) $row['id'], 'name' => $row['name'], 'city' => $row['city']];
    }, $rows ?: []);
}

function lp_enqueue_attendance_assets()
{
    if (!isset($_GET['page']) || $_GET['page'] !== 'lp-attendance') {
        return;
    }
    wp_enqueue_script('lp_attendance', plugins_url('js/lp-attendance.js', dirname(__FILE__)), array(), LOCAL_PETITION_VERSION, true);
    wp_localize_script('lp_attendance', 'lpAttendance', array('candidates' => lp_attendance_candidates()));
}
add_action('admin_enqueue_scripts', 'lp_enqueue_attendance_assets');

function lp_attendance()
{
    if (!current_user_can('moderate_comments')) {
        echo 'You do not have access to this page.';
        return;
    }
    ?>
    <div class="wrap">
        <h1>Attendance</h1>
        <p>
            <label for="lp-attendance-name"><strong>Name</strong></label><br>
            <input type="text" id="lp-attendance-name" class="regular-text" autocomplete="off" autofocus
                   placeholder="Start typing a name, press Enter when only one match remains">
        </p>
        <ul id="lp-attendance-candidates" style="margin:0 0 1em;max-width:25em"></ul>
        <h2>Attending (<span id="lp-attendance-count">0</span>)</h2>
        <ol id="lp-attendance-list"></ol>
    </div>
    <?php
}
