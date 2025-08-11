<?php

require_once('lp-tables-admin.php');

function lp_query_signers($limit = null, $offset = 0, $apply_filters = true, $count_only = false)
{
    global $wpdb;

    $filters = ['CampaignStatus' => 'campaign.status', 'Campaign' => 'campaign.name', 'Status' => 'signer.status', 'Email Status' => 'signer.email_status', 'Share Public' => 'signer.share_granted', 'Helper' => 'signer.is_helper', 'Supporter' => 'signer.is_supporter', 'Age' => 'signer.age', 'Collected By' => 'signer_proxy.name', 'Entered By' => 'users.display_name'];

    $table_name = $wpdb->prefix . 'lp_signer';
    $campaign_table = $wpdb->prefix . 'lp_campaign';
    $address_table = $wpdb->prefix . 'lp_address';
    $proxy_table = $wpdb->prefix . 'lp_proxy_signature';
    $wp_user_table = $wpdb->prefix . 'users';
    $query = "SELECT ";

    if ($count_only) {
        $query .= "COUNT(*) 'Count' ";
    } else {
        $query .= "campaign.name 'Campaign', campaign.slug,
                     signer.id 'ID', signer.status 'Status', signer.created 'Created', signer.name 'Name', signer.age 'Age', signer.photo_file 'Photo', signer.title 'Title', signer.email 'Email', signer.email_status 'Email Status', signer.phone 'Phone', signer.comments 'Comments', signer.share_granted 'Share Public', signer.is_helper 'Helper', signer.is_supporter 'Supporter',
                     address.line_1 'Line 1', address.line_2 'Line 2', address.city 'City', address.state 'State', address.neighborhood 'Neighborhood',
                     signer_proxy.name 'Collected By', users.display_name 'Entered By' ";
    }
    $query .= "FROM `$table_name` signer
              JOIN `$campaign_table` campaign ON campaign.id = signer.campaign_id
              JOIN `$address_table` address ON address.id = signer.address_id
              LEFT JOIN `$proxy_table` `proxy` ON `proxy`.signer_id = signer.id AND `proxy`.campaign_id = campaign.id
              LEFT JOIN `$table_name` signer_proxy ON signer_proxy.id = `proxy`.collected_by
              LEFT JOIN `$wp_user_table` users ON users.id = `proxy`.wp_user_id";
    if ($apply_filters) {
        $where = build_where('signers', $filters, ['CampaignStatus' => 'Active']);
        $query .= " WHERE $where";
    }
    if (!$count_only && $limit !== null) {
        $query .= " LIMIT $limit OFFSET $offset";
    }

    //echo "<pre>$query</pre>";
    return $wpdb->get_results($query, $count_only ? OBJECT : ARRAY_A);
}

function lp_review_signers()
{
    if (!is_user_logged_in()) {
        echo 'You need to sign in to access this page.';
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $users = array_keys($_POST['user-id']);
        if (count($users) > 0) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'lp_signer';
            if (array_key_exists('new_status', $_POST) && $_POST['new_status'] !== '-- No Change --') {
                $query = "UPDATE `$table_name` SET status = '" . $_POST['new_status'] . "', approved_id = " . wp_get_current_user()->ID . ' WHERE id IN (' . implode(',', $users) . ')';
                $wpdb->get_results($query);
            }
            if (array_key_exists('new_email_status', $_POST) && $_POST['new_email_status'] !== '-- No Change --') {
                $query = "UPDATE `$table_name` SET email_status = '" . $_POST['new_email_status'] . "' WHERE id IN (" . implode(',', $users) . ')';
                $wpdb->get_results($query);
            }
        }
    }

    $unfiltered = lp_query_signers(apply_filters: false);

    $limit = array_key_exists('limit', $_GET) ? intval($_GET['limit']) : 25;
    $offset = array_key_exists('offset', $_GET) ? intval($_GET['offset']) : 0;
    $result = lp_query_signers($limit, $offset, apply_filters: true);

    $unique_values = ['ID', 'Created', 'Name', 'Photo', 'Title', 'Email', 'Phone', 'Comments', 'Line 1', 'Line 2'];
    $hidden_columns = ['slug'];
    $header_output = false;
    $count = 0;
    $update_status = array_key_exists('Status', $_GET);

    foreach ($result as $values) {
        if (!$header_output) {
            echo build_filters('signers', $unfiltered, $unique_values, $hidden_columns);
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
            if ($key == 'Photo') {
                if ($value)
                    $img_url = '/wp-content/uploads/local-petition/' . $values['slug'] . '/' . $values['ID'] . '/' . $value;
                else
                    $img_url = '/wp-content/plugins/local-petition/images/placeholder-image-person.png';
                echo '<img src="' . $img_url . '" style="width: 100px;">';
            } else {
                echo esc_html($value);
            }
            echo '</td>';
        }
        echo '</tr>' . "\n";
    }
    $result = lp_query_signers(count_only: true);
    $total = intval($result[0]->Count);
    if (count($result) == 0) {
        echo '<br><p>There are no users that match the current criteria.</p>';
        return;
    }
    echo '</table>';

    echo '<br/>';
    echo '<div>';
    if ($update_status) {
        echo 'Change status of selected to: <select name="new_status">' .
            '<option>-- No Change --</option>' .
            '<option>Unreviewed</option>' .
            '<option>Approved</option>' .
            '<option>Quarantined</option>' .
            '</select>';
    }
    echo ' change email to: <select name="new_email_status">' .
    '<option>-- No Change --</option>' .
    '<option>Unknown</option>' .
    '<option>Valid</option>' .
    '<option>Full</option>' .
    '<option>Invalid</option>' .
    '<option>Unsubscribed</option>' .
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
    echo '<a href="/wp-admin/admin-ajax.php?action=lp_fetch_signers" download="signers.csv">Download</a>';
}

function lp_fetch_signers_json_handler() {
    echo 'Just a test!';
}
