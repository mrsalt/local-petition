<?php

// Events Calendar plugin: events are posts of type 'tribe_events', start date
// stored in the '_EventStartDate' meta ('Y-m-d H:i:s'). Meetings are the events
// carrying the 'meeting' tag.
const LP_MEETING_EVENT_POST_TYPE = 'tribe_events';
const LP_MEETING_TAG = 'meeting';

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

/** Meeting events, most recent first. */
function lp_attendance_events()
{
    $query = new WP_Query([
        'post_type' => LP_MEETING_EVENT_POST_TYPE,
        'tag' => LP_MEETING_TAG,
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'meta_key' => '_EventStartDate',
        'orderby' => 'meta_value',
        'order' => 'DESC',
        'no_found_rows' => true,
    ]);
    $events = [];
    foreach ($query->posts as $post) {
        $start = get_post_meta($post->ID, '_EventStartDate', true);
        $timestamp = strtotime($start);
        if (!$timestamp) {
            continue;
        }
        $events[] = [
            'id' => $post->ID,
            'title' => html_entity_decode(wp_strip_all_tags(get_the_title($post)), ENT_QUOTES, 'UTF-8'),
            'date' => gmdate('Y-m-d', $timestamp),
            'year' => (int) gmdate('Y', $timestamp),
            'label' => gmdate('M j, Y', $timestamp),
        ];
    }
    return $events;
}

function lp_enqueue_attendance_assets()
{
    if (!isset($_GET['page']) || $_GET['page'] !== 'lp-attendance') {
        return;
    }
    wp_enqueue_script('lp_attendance', plugins_url('js/lp-attendance.js', dirname(__FILE__)), array(), LOCAL_PETITION_VERSION, true);
    wp_localize_script('lp_attendance', 'lpAttendance', array(
        'candidates' => lp_attendance_candidates(),
        'events' => lp_attendance_events(),
        'currentYear' => (int) wp_date('Y'),
        'today' => wp_date('Y-m-d'),
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('lp_attendance'),
    ));
}
add_action('admin_enqueue_scripts', 'lp_enqueue_attendance_assets');

function lp_attendance_ajax_init()
{
    check_ajax_referer('lp_attendance', 'nonce');
    if (!current_user_can('moderate_comments')) {
        wp_send_json_error('Not authorized', 403);
    }
    $event_id = isset($_POST['event_id']) ? absint($_POST['event_id']) : 0;
    if (!$event_id || get_post_type($event_id) !== LP_MEETING_EVENT_POST_TYPE) {
        wp_send_json_error('Unknown event', 400);
    }
    $signer_ids = [];
    if (isset($_POST['signer_ids']) && is_array($_POST['signer_ids'])) {
        $signer_ids = array_values(array_unique(array_filter(array_map('absint', $_POST['signer_ids']))));
    }
    return [$event_id, $signer_ids];
}

function lp_attendance_get_handler()
{
    global $wpdb;
    list($event_id) = lp_attendance_ajax_init();
    $attendance_table = $wpdb->prefix . 'lp_attendance';
    $signer_table = $wpdb->prefix . 'lp_signer';
    $address_table = $wpdb->prefix . 'lp_address';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT signer.id, signer.name, address.city
         FROM `$attendance_table` attendance
         JOIN `$signer_table` signer ON signer.id = attendance.signer_id
         LEFT JOIN `$address_table` address ON address.id = signer.address_id
         WHERE attendance.event_id = %d
         ORDER BY attendance.id",
        $event_id
    ), ARRAY_A);
    wp_send_json_success(array_map(function ($row) {
        return ['id' => (int) $row['id'], 'name' => $row['name'], 'city' => $row['city']];
    }, $rows ?: []));
}

function lp_attendance_save_handler()
{
    global $wpdb;
    list($event_id, $signer_ids) = lp_attendance_ajax_init();
    if ($signer_ids) {
        $attendance_table = $wpdb->prefix . 'lp_attendance';
        $signer_table = $wpdb->prefix . 'lp_signer';
        $placeholders = implode(',', array_fill(0, count($signer_ids), '%d'));
        // Upsert: new (event, signer) pairs are inserted, existing ones are refreshed.
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO `$attendance_table` (event_id, signer_id, recorded_by)
             SELECT %d, id, %d FROM `$signer_table` WHERE id IN ($placeholders)
             ON DUPLICATE KEY UPDATE recorded_by = VALUES(recorded_by)",
            array_merge([$event_id, get_current_user_id()], $signer_ids)
        ));
        if ($result === false) {
            wp_send_json_error('Database error', 500);
        }
    }
    wp_send_json_success(['saved' => count($signer_ids)]);
}

function lp_attendance_remove_handler()
{
    global $wpdb;
    list($event_id, $signer_ids) = lp_attendance_ajax_init();
    if ($signer_ids) {
        $attendance_table = $wpdb->prefix . 'lp_attendance';
        $placeholders = implode(',', array_fill(0, count($signer_ids), '%d'));
        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM `$attendance_table` WHERE event_id = %d AND signer_id IN ($placeholders)",
            array_merge([$event_id], $signer_ids)
        ));
        if ($result === false) {
            wp_send_json_error('Database error', 500);
        }
    }
    wp_send_json_success(['removed' => count($signer_ids)]);
}

add_action('wp_ajax_lp_attendance_get', 'lp_attendance_get_handler');
add_action('wp_ajax_lp_attendance_save', 'lp_attendance_save_handler');
add_action('wp_ajax_lp_attendance_remove', 'lp_attendance_remove_handler');

function lp_attendance()
{
    if (!current_user_can('moderate_comments')) {
        echo 'You do not have access to this page.';
        return;
    }
    ?>
    <div class="wrap">
        <h1>Meeting Attendance</h1>
        <style>
            #lp-attendance-combo { position: relative; width: 25em; max-width: 100%; margin: 1em 0; }
            #lp-attendance-name { width: 100%; box-sizing: border-box; }
            #lp-attendance-combo.open #lp-attendance-name { border-bottom-left-radius: 0; border-bottom-right-radius: 0; }
            #lp-attendance-candidates { display: none; position: absolute; z-index: 10; top: 100%; left: 0; right: 0; margin: -1px 0 0; padding: 0;
                list-style: none; background: #fff; border: 1px solid #8c8f94; border-top-color: #dcdcde; border-radius: 0 0 4px 4px; box-shadow: 0 3px 6px rgba(0,0,0,.12); }
            #lp-attendance-combo.open #lp-attendance-candidates { display: block; }
            #lp-attendance-candidates li { margin: 0; padding: 6px 10px; cursor: pointer; }
            #lp-attendance-candidates li.active, #lp-attendance-candidates li[role=option]:hover { background: #2271b1; color: #fff; }
            #lp-attendance-candidates li.more { color: #646970; cursor: default; font-style: italic; }
            #lp-attendance-list { list-style: none; margin: 0 0 1em; padding: 0; }
            #lp-attendance-list li { margin: 0 0 4px; }
            #lp-attendance-list .unsaved { color: #996800; font-style: italic; }
            #lp-attendance-status { margin-left: 1em; }
        </style>
        <p>
            <label for="lp-attendance-year"><strong>Year</strong></label>
            <select id="lp-attendance-year"></select>
            <label for="lp-attendance-event" style="margin-left:1em"><strong>Meeting</strong></label>
            <select id="lp-attendance-event"></select>
        </p>
        <label for="lp-attendance-name"><strong>Name</strong></label>
        <div id="lp-attendance-combo">
            <input type="text" id="lp-attendance-name" autocomplete="off" autofocus role="combobox" aria-expanded="false" aria-controls="lp-attendance-candidates"
                   placeholder="Start typing a name, press Enter when only one match remains">
            <ul id="lp-attendance-candidates" role="listbox"></ul>
        </div>
        <h2>Attending (<span id="lp-attendance-count">0</span>)</h2>
        <ul id="lp-attendance-list"></ul>
        <p>
            <button type="button" class="button button-primary" id="lp-attendance-save">Save</button>
            <button type="button" class="button" id="lp-attendance-remove" disabled>Remove</button>
            <span id="lp-attendance-status" role="status" aria-live="polite"></span>
        </p>
    </div>
    <?php
}
