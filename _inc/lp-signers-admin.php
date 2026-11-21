<?php

require_once('lp-tables-admin.php');

function lp_query_signers($limit = null, $offset = 0, $apply_filters = true, $count_only = false, $order_by = 'ID', $include_campaign_status = false)
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
                     address.line_1 'Line 1', address.line_2 'Line 2', address.city 'City', address.state 'State', address.zip 'Zip', address.neighborhood 'Neighborhood',
                     signer_proxy.name 'Collected By', users.display_name 'Entered By' ";
        if ($include_campaign_status) {
            $query .= ", campaign.status 'CampaignStatus' ";
        }
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
    if ($order_by && !$count_only) {
        $query .= " ORDER BY " . $order_by;
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

    if (!current_user_can('moderate_comments')) {
        echo 'You do not have access to this page.';
        return;
    }

    // The whole signer list is embedded as JSON; lp-review-signers.js does the
    // filtering, paging, column choice, search and email lists in the browser.
    global $wpdb;
    $campaign_table = $wpdb->prefix . 'lp_campaign';
    $city_specific = $wpdb->get_col("SELECT DISTINCT city_specific FROM `$campaign_table` WHERE status = 'Active' AND city_specific IS NOT NULL AND city_specific <> ''");
    $data = [
        'rows' => lp_query_signers(apply_filters: false, include_campaign_status: true),
        'page' => 'lp-review-signers',
        // Cities pre-checked in the Email dialog; empty means every city.
        'defaultCities' => array_map('strtoupper', $city_specific ?: []),
    ];
    wp_enqueue_script('lp_review_signers', plugins_url('js/lp-review-signers.js', dirname(__FILE__)), array('jscookie'), LOCAL_PETITION_VERSION, true);
    wp_add_inline_script('lp_review_signers', 'window.lpSigners = ' . wp_json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP) . ';', 'before');
    ?>
    <style>
        #lp-signers-app .lp-filters td { padding: 0 8px 4px 0; vertical-align: bottom; }
        #lp-signers-app .lp-pager a, #lp-signers-app .lp-pager b { margin-right: 10px; }
        #lp-signers-app .lp-bottom-buttons { margin: 1em 0; }
        .lp-dialog { max-width: 42em; width: 90vw; }
        .lp-dialog h2 { margin-top: 0; }
        .lp-columns-lists { display: flex; gap: 12px; align-items: center; }
        .lp-columns-lists select { width: 14em; height: 18em; }
        .lp-columns-lists .lp-columns-buttons { display: flex; flex-direction: column; gap: 8px; }
        .lp-combo { position: relative; width: 25em; max-width: 100%; margin: 0.5em 0 1em; }
        .lp-combo input { width: 100%; box-sizing: border-box; }
        .lp-combo.open input { border-bottom-left-radius: 0; border-bottom-right-radius: 0; }
        .lp-combo ul { display: none; position: absolute; z-index: 10; top: 100%; left: 0; right: 0; margin: -1px 0 0; padding: 0; list-style: none;
            background: #fff; border: 1px solid #8c8f94; border-top-color: #dcdcde; border-radius: 0 0 4px 4px; box-shadow: 0 3px 6px rgba(0,0,0,.12); }
        .lp-combo.open ul { display: block; }
        .lp-combo li { margin: 0; padding: 6px 10px; cursor: pointer; }
        .lp-combo li.active, .lp-combo li[role=option]:hover { background: #2271b1; color: #fff; }
        .lp-combo li.more { color: #646970; cursor: default; font-style: italic; }
        .lp-details th { text-align: left; padding: 4px 16px 4px 0; vertical-align: top; white-space: nowrap; }
        .lp-details td { padding: 4px 0; white-space: pre-wrap; }
        .lp-email-options fieldset { margin: 0 0 12px; border: 1px solid #c3c4c7; padding: 6px 12px; }
        .lp-email-options label { margin-right: 14px; }
        .lp-city-picker { position: relative; display: inline-block; }
        .lp-city-panel { display: none; position: absolute; z-index: 10; background: #fff; border: 1px solid #8c8f94; padding: 8px; max-height: 14em; overflow: auto; min-width: 14em; box-shadow: 0 3px 6px rgba(0,0,0,.12); }
        .lp-city-picker.open .lp-city-panel { display: block; }
        .lp-city-panel label { display: block; margin: 0 0 4px; }
        .lp-email-result { max-height: 22em; overflow: auto; margin-top: 12px; }
        .lp-email-result textarea { width: 100%; box-sizing: border-box; }
    </style>
    <div id="lp-signers-app"><noscript>JavaScript is required to review signers.</noscript></div>
    <?php
}

function lp_fetch_signers_json_handler() {
    $result = lp_query_signers(apply_filters: false);
    $header_output = false;
    $out = fopen('php://output', 'w');
    foreach ($result as $values) {
        if (!$header_output) {
            fputcsv($out, array_keys($values));
            $header_output = true;
        }
        fputcsv($out, array_values($values));
    }
    fclose($out);
    wp_die();
}
