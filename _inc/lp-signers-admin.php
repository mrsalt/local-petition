<?php

function display_value($key, $value)
{
    if ($key == 'Share Public' || $key == 'Helper' || $key == 'Supporter') {
        if ($value == '0')
            return 'No';
        if ($value == '1')
            return 'Yes';
    }
    return $value;
}

function internal_value($key, $value)
{
    if ($key == 'Share Public' || $key == 'Helper' || $key == 'Supporter') {
        if ($value == 'No')
            return '0';
        if ($value == 'Yes')
            return '1';
    }
    return $value;
}

function build_filters($result, $unique_values, &$hidden_columns)
{
    $histogram = [];
    foreach ($result as $index => $values) {
        foreach ($values as $key => $value) {
            if (in_array($key, $unique_values)) continue;
            if (in_array($key, $hidden_columns)) continue;
            $value = display_value($key, $value);
            if (!array_key_exists($key, $histogram))
                $histogram[$key] = [$value => 1];
            else {
                if (!array_key_exists($value, $histogram[$key]))
                    $histogram[$key][$value] = 1;
                else
                    $histogram[$key][$value]++;
            }
        }
    }
    $content = '<table><tr>';
    foreach ($histogram as $key => $options) {
        $content .= '<td>' . $key . '</td>';
    }
    $content .= '<td>Visible Columns</td>';
    $content .= "</tr>\n";
    $content .= '<tr>';
    foreach ($histogram as $key => $options) {
        $content .= '<td><select onchange="update_filter(\'' . $key . '\',this.value);">';
        $content .= '<option>&lt;All&gt;</option>';
        foreach ($options as $value => $count) {
            $wpkey = str_replace(' ', '_', $key);
            $selected = array_key_exists($wpkey, $_GET) && $_GET[$wpkey] === $value;
            $content .= '<option' . ($selected ? ' selected' : '') . ' value="' . $value . '">' . $value . ' (' . $count . ')</option>';
        }
        $content .= '</td>' . "\n";
    }
    $content .= '<td><select multiple onchange="update_visible_columns(this);">';
    $hidden_by_cookie = [];
    if (array_key_exists('lp-hidden-columns', $_COOKIE)) {
        // This is annoying.  https://wordpress.stackexchange.com/questions/34866/stop-wordpress-automatically-escaping-post-data
        $hidden_by_cookie = json_decode(wp_unslash($_COOKIE['lp-hidden-columns']));
    }
    $count = 0;
    if (count($result) > 0) {
        foreach ($result[0] as $key => $value) {
            if (in_array($key, $hidden_columns)) continue;
            $visible = !in_array($key, $hidden_by_cookie);
            $content .= '<option' . ($visible ? ' selected' : '') . '>' . $key . '</option>';
            $count++;
        }
    }
    $content .= '</select></td>';
    $content .= "</tr>\n";
    $hidden_columns = array_merge($hidden_columns, $hidden_by_cookie);
    return $content;
    //return var_export($histogram, true);
}

function build_where($filters, $values = [])
{
    $where = '';
    $values = array_merge($values, $_GET);
    foreach ($filters as $key => $field) {
        $wpkey = str_replace(' ', '_', $key);
        if (array_key_exists($wpkey, $values)) {
            if (strlen($where) > 0) $where .= ' AND ';
            $value = internal_value($key, $values[$wpkey]);
            if (!is_numeric($value)) $value = '\'' . $value . '\'';
            $where .= $field . ' = ' . $value;
        }
    }
    return $where;
}

function do_query($limit = null, $offset = 0, $apply_filters = true, $count_only = false)
{
    global $wpdb;

    $filters = ['CampaignStatus' => 'campaign.status', 'Campaign' => 'campaign.name', 'Status' => 'signer.status', 'Share Public' => 'signer.share_granted', 'Helper' => 'signer.is_helper', 'Supporter' => 'signer.is_supporter', 'Age' => 'signer.age', 'Collected By' => 'signer_proxy.name', 'Entered By' => 'users.display_name'];

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
                     signer.id 'ID', signer.status 'Status', signer.created 'Created', signer.name 'Name', signer.age 'Age', signer.photo_file 'Photo', signer.title 'Title', signer.email 'Email', signer.phone 'Phone', signer.comments 'Comments', signer.share_granted 'Share Public', signer.is_helper 'Helper', signer.is_supporter 'Supporter',
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
        $where = build_where($filters, ['CampaignStatus' => 'Active']);
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
        if (count($users) > 0 && $_POST['new_status'] !== 'Unreviewed') {
            global $wpdb;
            $table_name = $wpdb->prefix . 'lp_signer';
            $query = "UPDATE `$table_name` SET status = '" . $_POST['new_status'] . "', approved_id = " . wp_get_current_user()->ID . ' WHERE id IN (' . implode(',', $users) . ')';
            $wpdb->get_results($query);
        }
    }

    $unfiltered = do_query(apply_filters: false);

    $limit = array_key_exists('limit', $_GET) ? intval($_GET['limit']) : 25;
    $offset = array_key_exists('offset', $_GET) ? intval($_GET['offset']) : 0;
    $result = do_query($limit, $offset, apply_filters: true);

    $unique_values = ['ID', 'Created', 'Name', 'Photo', 'Title', 'Email', 'Phone', 'Comments', 'Line 1', 'Line 2'];
    $hidden_columns = ['slug'];
    $header_output = false;
    $count = 0;
    $show_form = array_key_exists('Status', $_GET);

    foreach ($result as $values) {
        if (!$header_output) {
            echo build_filters($unfiltered, $unique_values, $hidden_columns);
            if ($show_form) echo '<form method="post">';
            echo '<table class="lp-table">';
            echo '<tr class="lp-table-header-row">';
            if ($show_form) echo '<th></th>';
            foreach ($values as $key => $value) {
                if (in_array($key, $hidden_columns)) continue;
                echo '<th class="lp-table-header">' . esc_html($key) . '</th>';
            }
            echo '</tr>' . "\n";
            $header_output = true;
        }
        echo '<tr class="lp-table-row' . ($count++ % 2 == 0 ? ' lp-even-row' : ' lp-odd-row') . '">';
        if ($show_form)
            echo '<td class="lp-table-data"><input type="checkbox" name="user-id[' . $values['ID'] . ']" checked></td>';
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
    $result = do_query(count_only: true);
    $total = intval($result[0]->Count);
    if (count($result) == 0) {
        echo '<br><p>There are no users that match the current criteria.</p>';
        return;
    }
    echo '</table>';

    echo '<br/>';
    if ($show_form) {
        echo '<div>Change status of selected to: <select name="new_status">' .
            '<option>Unreviewed</option>' .
            '<option>Approved</option>' .
            '<option>Quarantined</option>' .
            '</select>';
        echo ' ';
        echo '<input type="submit"></div>';
    }
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
