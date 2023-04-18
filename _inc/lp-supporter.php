<?php

function lp_supporter_map($atts = [], $content = null)
{
    return $content;
}

function lp_supporter_counter($atts = [], $content = null)
{
    global $wpdb;
    $query = $atts['query'];
    $query = str_replace('${wpdb}', $wpdb->prefix, $query);

    $result = $wpdb->get_results($wpdb->prepare($query));
    if (!is_array($result)) {
        return '<error>Query failed: ' . $query . '</error>';
    }
    foreach ($result[0] as $val) {
        $count = $val;
        break;
    }
    return
        "<div class=\"counter-box\">
      <div class=\"counter\">" . $count . "</div>
      <div class=\"message\">" . htmlspecialchars($atts['message']) . "</div>
    </div>";
}
