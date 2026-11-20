<?php

// Events Calendar plugin: events are posts of type 'tribe_events', start date
// stored in the '_EventStartDate' meta ('Y-m-d H:i:s'). Meetings are the events
// carrying the 'meeting' term, either as an Event Category (tribe_events_cat,
// what the Events Calendar editor offers) or as a regular post tag.
const LP_MEETING_EVENT_POST_TYPE = 'tribe_events';
const LP_MEETING_TAG = 'meeting';
const LP_MEETING_TAXONOMIES = ['tribe_events_cat', 'post_tag'];

require_once('address-sanitizer.php');

/** IDs of the 'meeting' term in each taxonomy that has one, keyed by taxonomy. */
function lp_attendance_meeting_terms()
{
    $terms = [];
    foreach (LP_MEETING_TAXONOMIES as $taxonomy) {
        if (!taxonomy_exists($taxonomy)) {
            continue;
        }
        $term = get_term_by('slug', LP_MEETING_TAG, $taxonomy) ?: get_term_by('name', LP_MEETING_TAG, $taxonomy);
        if ($term) {
            $terms[$taxonomy] = $term->term_id;
        }
    }
    return $terms;
}

/** Shapes a signer or person (with address) row for the attendance page. */
function lp_attendance_signer_row($row)
{
    $street = trim($row['line_1'] . ' ' . $row['line_2']);
    $locality = trim($row['city'] . ($row['state'] ? ', ' . $row['state'] : '') . ' ' . $row['zip']);
    return [
        'kind' => $row['kind'], // 'signer' (CFAL member) or 'person' (non-CFAL member)
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'city' => $row['city'],
        'address' => implode(', ', array_filter([$street, $locality])),
        'email' => $row['email'],
        'phone' => $row['phone'],
    ];
}

/**
 * SQL selecting signers and people (non-CFAL members) in the same shape: everyone, or
 * (with $for_event, which needs the event id passed twice to prepare()) just those
 * recorded as attending one event.
 */
function lp_attendance_people_sql($for_event)
{
    global $wpdb;
    $signer_table = $wpdb->prefix . 'lp_signer';
    $person_table = $wpdb->prefix . 'lp_person';
    $address_table = $wpdb->prefix . 'lp_address';
    $attendance_table = $wpdb->prefix . 'lp_attendance';
    $cols = function ($t) {
        return "$t.name, $t.email, $t.phone, address.line_1, address.line_2, address.city, address.state, address.zip";
    };
    $signer_cols = $cols('signer');
    $person_cols = $cols('person');
    if ($for_event) {
        return "SELECT 'signer' AS kind, signer.id, $signer_cols
                FROM `$attendance_table` att
                JOIN `$signer_table` signer ON signer.id = att.signer_id
                LEFT JOIN `$address_table` address ON address.id = signer.address_id
                WHERE att.event_id = %d
                UNION ALL
                SELECT 'person', person.id, $person_cols
                FROM `$attendance_table` att
                JOIN `$person_table` person ON person.id = att.person_id
                LEFT JOIN `$address_table` address ON address.id = person.address_id
                WHERE att.event_id = %d
                ORDER BY name";
    }
    return "SELECT 'signer' AS kind, signer.id, $signer_cols
            FROM `$signer_table` signer
            LEFT JOIN `$address_table` address ON address.id = signer.address_id
            UNION ALL
            SELECT 'person', person.id, $person_cols
            FROM `$person_table` person
            LEFT JOIN `$address_table` address ON address.id = person.address_id
            ORDER BY name";
}

function lp_attendance_candidates()
{
    global $wpdb;
    $rows = $wpdb->get_results(lp_attendance_people_sql(false), ARRAY_A);
    return array_map('lp_attendance_signer_row', $rows ?: []);
}

/** Meeting events, most recent first. */
function lp_attendance_events()
{
    $tax_query = ['relation' => 'OR'];
    foreach (lp_attendance_meeting_terms() as $taxonomy => $term_id) {
        $tax_query[] = ['taxonomy' => $taxonomy, 'field' => 'term_id', 'terms' => [$term_id]];
    }
    if (count($tax_query) === 1) {
        return [];
    }
    $query = new WP_Query([
        'post_type' => LP_MEETING_EVENT_POST_TYPE,
        'tax_query' => $tax_query,
        'post_status' => ['publish', 'future'],
        'posts_per_page' => -1,
        'meta_key' => '_EventStartDate',
        'orderby' => 'meta_value',
        'order' => 'DESC',
        'no_found_rows' => true,
        'suppress_filters' => true, // keep The Events Calendar from restricting the query (e.g. to upcoming events)
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
    $events = lp_attendance_events();
    wp_localize_script('lp_attendance', 'lpAttendance', array(
        'candidates' => lp_attendance_candidates(),
        'events' => $events,
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
    $ids = function ($key) {
        if (!isset($_POST[$key]) || !is_array($_POST[$key])) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('absint', $_POST[$key]))));
    };
    return [$event_id, $ids('signer_ids'), $ids('person_ids')];
}

function lp_attendance_get_handler()
{
    global $wpdb;
    list($event_id) = lp_attendance_ajax_init();
    $rows = $wpdb->get_results($wpdb->prepare(lp_attendance_people_sql(true), $event_id, $event_id), ARRAY_A);
    wp_send_json_success(array_map('lp_attendance_signer_row', $rows ?: []));
}

function lp_attendance_save_handler()
{
    global $wpdb;
    list($event_id, $signer_ids, $person_ids) = lp_attendance_ajax_init();
    $attendance_table = $wpdb->prefix . 'lp_attendance';
    // Upsert: new (event, attendee) pairs are inserted, existing ones are refreshed.
    $sources = [
        ['signer_id', $wpdb->prefix . 'lp_signer', $signer_ids],
        ['person_id', $wpdb->prefix . 'lp_person', $person_ids],
    ];
    foreach ($sources as list($column, $source_table, $ids)) {
        if (!$ids) {
            continue;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO `$attendance_table` (event_id, $column, recorded_by)
             SELECT %d, id, %d FROM `$source_table` WHERE id IN ($placeholders)
             ON DUPLICATE KEY UPDATE recorded_by = VALUES(recorded_by)",
            array_merge([$event_id, get_current_user_id()], $ids)
        ));
        if ($result === false) {
            wp_send_json_error('Database error', 500);
        }
    }
    wp_send_json_success(['saved' => count($signer_ids) + count($person_ids)]);
}

function lp_attendance_remove_handler()
{
    global $wpdb;
    list($event_id, $signer_ids, $person_ids) = lp_attendance_ajax_init();
    $attendance_table = $wpdb->prefix . 'lp_attendance';
    foreach ([['signer_id', $signer_ids], ['person_id', $person_ids]] as list($column, $ids)) {
        if (!$ids) {
            continue;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM `$attendance_table` WHERE event_id = %d AND $column IN ($placeholders)",
            array_merge([$event_id], $ids)
        ));
        if ($result === false) {
            wp_send_json_error('Database error', 500);
        }
    }
    wp_send_json_success(['removed' => count($signer_ids) + count($person_ids)]);
}

/** Creates a non-CFAL member (lp_person row), with an optional verified address. */
function lp_attendance_add_person_handler()
{
    global $wpdb;
    check_ajax_referer('lp_attendance', 'nonce');
    if (!current_user_can('moderate_comments')) {
        wp_send_json_error('Not authorized', 403);
    }
    $field = function ($key) {
        return isset($_POST[$key]) ? trim(sanitize_text_field(wp_unslash($_POST[$key]))) : '';
    };

    $name = $field('name');
    $email = $field('email');
    $phone = $field('phone');
    $info = isset($_POST['info']) ? sanitize_textarea_field(wp_unslash($_POST['info'])) : '';
    if ($name === '') {
        wp_send_json_error('Name is required.', 400);
    }
    if (mb_strlen($name) > 50 || mb_strlen($email) > 50 || mb_strlen($phone) > 20) {
        wp_send_json_error('Name and email can be at most 50 characters and phone at most 20.', 400);
    }
    if ($email !== '' && !is_email($email)) {
        wp_send_json_error('Email address is not valid.', 400);
    }

    $address = [
        'line_1' => $field('line_1'),
        'line_2' => $field('line_2'),
        'city' => $field('city'),
        'state' => $field('state'),
        'zip' => $field('zip'),
    ];
    $address_id = null;
    if (array_filter($address)) {
        if ($address['line_1'] === '' || $address['city'] === '' || $address['state'] === '') {
            wp_send_json_error('An address needs a street, city and state (or leave all address fields empty).', 400);
        }
        $sanitized = sanitize_address($address);
        if (array_key_exists('Error', $sanitized)) {
            wp_send_json_error($sanitized['Error'] . ' Fix it, or clear the address fields to add the person without one.', 400);
        }
        $address_id = store_address($sanitized);
        if (!$address_id) {
            wp_send_json_error('Could not save the address.', 500);
        }
        if (!empty($sanitized['coordinates'])) {
            update_coordinates($address_id, $sanitized['coordinates']);
        }
    }

    $person_table = $wpdb->prefix . 'lp_person';
    $inserted = $wpdb->insert($person_table, [
        'name' => $name,
        'address_id' => $address_id,
        'email' => $email !== '' ? $email : null,
        'phone' => $phone !== '' ? $phone : null,
        'info' => $info !== '' ? $info : null,
    ]);
    if (!$inserted) {
        wp_send_json_error('Database error', 500);
    }
    $person_id = (int) $wpdb->insert_id;

    $address_table = $wpdb->prefix . 'lp_address';
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT 'person' AS kind, person.id, person.name, person.email, person.phone,
                address.line_1, address.line_2, address.city, address.state, address.zip
         FROM `$person_table` person
         LEFT JOIN `$address_table` address ON address.id = person.address_id
         WHERE person.id = %d",
        $person_id
    ), ARRAY_A);
    wp_send_json_success(lp_attendance_signer_row($row));
}

add_action('wp_ajax_lp_attendance_get', 'lp_attendance_get_handler');
add_action('wp_ajax_lp_attendance_save', 'lp_attendance_save_handler');
add_action('wp_ajax_lp_attendance_remove', 'lp_attendance_remove_handler');
add_action('wp_ajax_lp_attendance_add_person', 'lp_attendance_add_person_handler');

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
            #lp-attendance-scroll { min-height: 200px; overflow-y: auto; border: 1px solid #c3c4c7; margin-bottom: 1em; background: #fff; }
            #lp-attendance-list { border: 0; }
            #lp-attendance-list thead th { position: sticky; top: 0; z-index: 1; background: #f6f7f7; box-shadow: 0 1px 0 #c3c4c7; }
            #lp-attendance-list td, #lp-attendance-list th { text-align: left; vertical-align: top; }
            #lp-attendance-list .check-column { width: 2em; }
            #lp-attendance-list .unsaved { color: #996800; font-style: italic; }
            #lp-attendance-status { margin-left: 1em; }
            #lp-person-dialog { max-width: 32em; }
            #lp-person-dialog fieldset { margin: 0 0 1em; }
            #lp-attendance-candidates li.add-person { font-style: italic; }
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
        <div id="lp-attendance-scroll">
            <table id="lp-attendance-list" class="widefat striped">
                <thead><tr><th class="check-column"></th><th>Name</th><th>Address</th><th>Email</th><th>Phone</th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
        <p class="description">* Non-CFAL member (has not signed the petition)</p>
        <p id="lp-attendance-actions">
            <button type="button" class="button button-primary" id="lp-attendance-save">Save</button>
            <button type="button" class="button" id="lp-attendance-remove" disabled>Remove</button>
            <span id="lp-attendance-status" role="status" aria-live="polite"></span>
        </p>
        <dialog id="lp-person-dialog">
            <form method="dialog" id="lp-person-form">
                <h2>Add non-CFAL member</h2>
                <p>
                    <label for="lp-person-name"><strong>Name</strong></label><br>
                    <input type="text" id="lp-person-name" name="name" class="regular-text" maxlength="50" required>
                </p>
                <p>
                    <label for="lp-person-email">Email</label><br>
                    <input type="email" id="lp-person-email" name="email" class="regular-text" maxlength="50">
                </p>
                <p>
                    <label for="lp-person-phone">Phone</label><br>
                    <input type="tel" id="lp-person-phone" name="phone" class="regular-text" maxlength="20">
                </p>
                <fieldset>
                    <legend>Address (optional; it is verified when entered)</legend>
                    <p><input type="text" name="line_1" placeholder="Street address" class="regular-text" maxlength="40"></p>
                    <p><input type="text" name="line_2" placeholder="Apt / unit" class="regular-text" maxlength="40"></p>
                    <p>
                        <input type="text" name="city" placeholder="City" maxlength="20">
                        <input type="text" name="state" placeholder="State" size="3" maxlength="2">
                        <input type="text" name="zip" placeholder="Zip" size="6" maxlength="5">
                    </p>
                </fieldset>
                <p>
                    <label for="lp-person-info">General info</label><br>
                    <textarea id="lp-person-info" name="info" rows="3" class="large-text"></textarea>
                </p>
                <p id="lp-person-error" role="alert" style="color:#d63638"></p>
                <p>
                    <button type="submit" class="button button-primary" id="lp-person-add">Add</button>
                    <button type="button" class="button" id="lp-person-cancel">Cancel</button>
                </p>
            </form>
        </dialog>
    </div>
    <?php
}
