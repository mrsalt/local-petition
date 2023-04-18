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
    $query = str_replace('${less_than_one_day}', 'TIMESTAMPDIFF(DAY, ' . $wpdb->prefix . 'lp_signer.created, NOW()) < 1.0', $query);
    $message = htmlspecialchars($atts['message']);

    $result = $wpdb->get_results($wpdb->prepare($query));
    if (!is_array($result)) {
        return '<error>Query failed: ' . $query . '</error>';
    }
    foreach ($result[0] as $val) {
        $count = $val;
        break;
    }
    if (array_key_exists('min', $atts)) {
        $min = intval($atts['min']);
        if ($count < $min) {
            if (is_user_logged_in()) {
                $message .= '<p style="color: lightgray"><i>This counter is hidden from non-logged in users until the value = ' . $min . '</i></p>';
            } else {
                return;
            }
        }
    }
    $id = 'counter-' . md5($query);
    $timeInterval = 1000 / $count;
    $script = "<script>animateCounter(document.getElementById('$id'), $count, $timeInterval);</script>";
    return
        "<div class=\"counter-box\">
      <div class=\"counter\" id=\"$id\">" . $count . "</div>
      <div class=\"message\">" . $message . "</div>
    </div>" . $script;
}
