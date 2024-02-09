<?php

require_once('lp-tables-admin.php');

function lp_query_messages($limit = null, $offset = 0, $apply_filters = true, $count_only = false)
{
    global $wpdb;

    $filters = ['Status' => 'status', 'Updated By' => 'users.display_name'];

    $table_name = $wpdb->prefix . 'lp_contact_request';
    $wp_user_table = $wpdb->prefix . 'users';
    $query = "SELECT ";

    if ($count_only) {
        $query .= "COUNT(*) 'Count' ";
    } else {
        $query .= "`$table_name`.id 'ID', created 'Submitted', status 'Status', users.display_name 'Updated By' , name 'Name', email 'Email', comments 'Comments' ";
    }
    $query .= "FROM `$table_name`
               LEFT JOIN `$wp_user_table` users ON users.id = updated_id";
    if ($apply_filters) {
        $where = build_where('messages', $filters);
        if ($where)
            $query .= " WHERE $where";
    }
    if (!$count_only && $limit !== null) {
        $query .= " LIMIT $limit OFFSET $offset";
    }

    //echo "<pre>apply_filters = $apply_filters, count_only = $count_only\n$query</pre>";
    return $wpdb->get_results($query, $count_only ? OBJECT : ARRAY_A);
}

function lp_review_messages()
{
    if (!is_user_logged_in()) {
        echo 'You need to sign in to access this page.';
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $ids = array_keys($_POST['user-id']);
        if (count($ids) > 0) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'lp_contact_request';
            if (array_key_exists('new_status', $_POST) && $_POST['new_status'] !== '-- No Change --') {
                $query = "UPDATE `$table_name` SET status = '" . $_POST['new_status'] . "', updated_id = " . wp_get_current_user()->ID . ' WHERE id IN (' . implode(',', $ids) . ')';
                $wpdb->get_results($query);
            }
        }
    }

    $unfiltered = lp_query_messages(apply_filters: false);

    $limit = array_key_exists('limit', $_GET) ? intval($_GET['limit']) : 25;
    $offset = array_key_exists('offset', $_GET) ? intval($_GET['offset']) : 0;
    $result = lp_query_messages($limit, $offset, apply_filters: true);

    $unique_values = ['ID', 'Submitted', 'Name', 'Email', 'Comments'];
    $hidden_columns = [];
    $header_output = false;
    $count = 0;

    foreach ($result as $values) {
        if (!$header_output) {
            echo build_filters('messages', $unfiltered, $unique_values, $hidden_columns);
            echo '<form method="post">';
            echo '<table class="lp-table">';
            echo '<tr class="lp-table-header-row">';
            echo '<th><input type="checkbox" onclick="toggle_checkbox(this)"></th>';
            foreach ($values as $key => $value) {
                if (in_array($key, $hidden_columns)) continue;
                echo '<th class="lp-table-header">' . esc_html($key) . '</th>';
            }
            echo '</tr>' . "\n";
            $header_output = true;
        }
        echo '<tr class="lp-table-row' . ($count++ % 2 == 0 ? ' lp-even-row' : ' lp-odd-row') . '">';
        echo '<td class="lp-table-data"><input type="checkbox" name="user-id[' . $values['ID'] . ']"></td>';
        foreach ($values as $key => $value) {
            if (in_array($key, $hidden_columns)) continue;
            echo '<td class="lp-table-data">';
            $value = display_value($key, $value);
            if ($key == 'Comments') {
                echo '<div class="comments">';
                echo esc_html($value);
                echo '</div>';
            }
            else {
                echo esc_html($value);
            }
            echo '</td>';
        }
        echo '</tr>' . "\n";
    }
    $result = lp_query_messages(count_only: true);
    $total = intval($result[0]->Count);
    if (count($result) == 0) {
        echo '<br><p>There are no messages that match the current criteria.</p>';
        return;
    }
    echo '</table>';

    echo '<br/>';
    echo '<div>';

    echo 'Change status of selected to: <select name="new_status">' .
        '<option>-- No Change --</option>' .
        '<option>Unread</option>' .
        '<option>Read</option>' .
        '<option>Response Sent</option>' .
        '<option>Will Not Respond</option>' .
        '</select>';

    echo ' ';
    echo '<input type="submit"></div>';
    echo '</form>';
    echo '<div>' . $total . ' results</div>';
    echo '<div>Page ';
    for ($page = 0; $page * $limit < $total; $page++) {
        $o = $page * $limit;
        echo '&nbsp;&nbsp;';
        if ($offset == $o) {
            echo '<b>' . ($page + 1) . '</b>';
        } else {
            $query = http_build_query(array_merge($_GET, array('offset' => $o)));
            echo '<a href="?' . $query . '">' . ($page + 1) . '</a>';
        }
    }
    echo '</div>';

    echo '<div>Results per page: <select onchange="update_filter(\'limit\', this.value)">';
    foreach (array(25, 50, 100, 250, 500, 1000) as $l) {
        echo '<option value="' . $l . '"' . ($limit == $l ? ' selected' : '') . '>' . $l . '</option>';
    }
    echo '</select></div>';
}
