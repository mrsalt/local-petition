<?php

function lp_admin_menu()
{
    if (!is_user_logged_in()) {
        return;
    }
    add_menu_page(
        __('Review Signers', 'textdomain'),
        'Review Signers',
        'moderate_comments',
        'lp-review-signers',
        'lp_review_signers',
        '', //plugins_url( 'myplugin/images/icon.png' ),
        null //6
    );
}

function lp_admin_bar_menu(WP_Admin_Bar $admin_bar)
{
    if (!is_user_logged_in()) {
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'lp_signer';
    $query = "SELECT COUNT(*) AS count FROM `$table_name` WHERE status = 'Unreviewed'";
    $result = $wpdb->get_results($query);
    $count = $result[0]->count;

    $admin_bar->add_menu(array(
        'id'    => 'lp-review-new-signers',
        'parent' => null,
        'group'  => null,
        'title' => 'Review New Signers (' . $count . ')', //you can use img tag with image link. it will show the image icon Instead of the title.
        'href'  => admin_url('admin.php?page=lp-review-signers&Status=Unreviewed'),
        'meta' => [
            'title' => __('Approve new signers so they will become visible on the site', 'textdomain'), //This title will show on hover
        ]
    ));
}

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
            if (!intval($value)) $value = '\'' . $value . '\'';
            $where .= $field . ' = ' . $value;
        }
    }
    return $where;
}

function lp_review_signers()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'lp_signer';

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $users = array_keys($_POST['user-id']);
        if (count($users) > 0) {
            $query = "UPDATE `$table_name` SET status = '" . $_POST['new_status'] . "', approved_id = " . wp_get_current_user()->ID . ' WHERE id IN (' . implode(',', $users) . ')';
            $wpdb->get_results($query);
        }
    }

    $filters = ['CampaignStatus' => 'campaign.status', 'Campaign' => 'campaign.name', 'Status' => 'signer.status', 'Share Public' => 'signer.share_granted', 'Helper' => 'signer.is_helper', 'Supporter' => 'signer.is_supporter'];

    $campaign_table = $wpdb->prefix . 'lp_campaign';
    $address_table = $wpdb->prefix . 'lp_address';
    $where = build_where($filters, ['CampaignStatus' => 'Active']);
    $query = "SELECT campaign.name 'Campaign', campaign.slug,
                     signer.status 'Status', signer.created 'Created', signer.id 'ID', signer.name 'Name', signer.photo_file 'Photo', signer.title 'Title', signer.email 'Email', signer.phone 'Phone', signer.comments 'Comments', signer.share_granted 'Share Public', signer.is_helper 'Helper', signer.is_supporter 'Supporter',
                     address.line_1 'Line 1', address.line_2 'Line 2', address.city 'City', address.state 'State', address.neighborhood 'Neighborhood'
              FROM `$table_name` signer
              JOIN `$campaign_table` campaign ON campaign.id = signer.campaign_id
              JOIN `$address_table` address ON address.id = signer.address_id
              WHERE $where";

    //echo "<pre>$query</pre>";
    $result = $wpdb->get_results($query, ARRAY_A);
    //echo '<pre>';
    //var_export($result);
    //echo '</pre>';
    if (count($result) == 0) {
        echo '<br><p>There are no users that match the current criteria.</p>';
        return;
    }

    $unique_values = ['Created', 'Name', 'Photo', 'Title', 'Email', 'Phone', 'Comments', 'Line 1', 'Line 2'];
    $hidden_columns = ['slug', 'ID'];
    $header_output = false;
    $count = 0;
    echo '<form method="post">';
    foreach ($result as $index => $values) {
        if (!$header_output) {
            echo build_filters($result, $unique_values, $hidden_columns);
            echo '<table class="lp-table">';
            echo '<tr class="lp-table-header-row">';
            echo '<th></th>';
            foreach ($values as $key => $value) {
                if ($key == 'slug' || $key == 'ID') continue;
                if (in_array($key, $hidden_columns)) continue;
                echo '<th class="lp-table-header">' . esc_html($key) . '</th>';
            }
            echo '</tr>' . "\n";
            $header_output = true;
        }
        echo '<tr class="lp-table-row' . ($count++ % 2 == 0 ? ' lp-even-row' : ' lp-odd-row') . '">';
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
    echo '</table>';

    echo '<br/>';
    echo '<div>Change status of selected to: <select name="new_status">' .
        '<option>Unreviewed</option>' .
        '<option>Approved</option>' .
        '<option>Quarantined</option>' .
        '</select>';
    echo ' ';
    echo '<input type="submit">';
    echo '</form>';
}
