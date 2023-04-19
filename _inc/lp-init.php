<?php

function lp_handle_init()
{
    if (!session_id()) {
        session_start();
    }
}

function set_campaign($campaign_slug, &$output)
{
    if (isset($_SESSION['campaign']) && $_SESSION['campaign']->slug === $campaign_slug) {
        return true;
    }

    global $wpdb;
    $campaign_table_name = $wpdb->prefix . 'lp_campaign';

    $results = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM {$campaign_table_name} WHERE slug = %s", $campaign_slug)
    );

    if (count($results) == 0) {
        $output .= "Error: no campaign found with slug = \"{$campaign_slug}\"";
        return false;
    }
    $campaign = $results[0];
    $campaign->id = intval($campaign->id);
    $_SESSION['campaign'] = $campaign;
    return true;
}
