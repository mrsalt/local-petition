<?php

function lp_render_petition( $atts = [], $content = null) {
    //$attributes = '<div>Attributes:<pre>'.htmlspecialchars(var_export($atts, true)).'</pre></div>';
    //return $attributes . '<div>Content:<pre>'.htmlspecialchars(var_export($content, true)).'</pre></div>';
    if (!isset($content)) {
        return 'Error: no content for shortcode <b>local_petition</b>';
    }

    if (!isset($atts['campaign'])) {
        return 'Error: no "campaign" attribute found in shortcode <b>local_petition</b>';
    }

    global $wpdb;
    $campaign_table_name = $wpdb->prefix . 'lp_campaign';
    //$wpdb->get_results( "" );

    $results = $wpdb->get_results(
        $wpdb->prepare( "SELECT * FROM {$campaign_table_name} WHERE slug = %s", $atts['campaign'] )
    );

    if (count($results) == 0) {
        return "Error: no campaign attribute found for campaign \"{$atts['campaign']}\"";
    }
    $campaign = $results[0];

    $_SESSION['lp_campaign_id'] = $campaign->id;

    $content .= '<form autocomplete="on" class="petition" action="'.get_permalink().'" method="post">';

    $content .= get_input('Name', 'signer_name');
    $content .= get_input('Address Line 1', 'line_1');
    $content .= get_input('Address Line 2', 'line_2');
    $content .= get_input('City', 'city');
    $content .= get_state_input('State', 'state');
    $content .= get_input('Zip', 'zip');

    $content .= get_input('Title', 'title');
    $content .= '<p><input type="submit"></p>';
    $content .= '</form>';
    return $content;
}

function get_input($label, $id, $type = 'text') {
    if ($type == 'text')
        $value = esc_attr($_POST[$id]);
    else if ($type == 'textarea')
        $value = esc_textarea($_POST[$id]);
    return
        '<p><label for="'.$id.'">'.$label.': <span>*</span> <br>'.
        '<input id="'.$id.'" type="'.$type.'" name="'.$id.'" value="'.$value.'"></label></p>';
}

function get_state_input($label, $id) {
    $states['AL'] = 'Alabama';
    $states['AK'] = 'Alaska';
    $states['AZ'] = 'Arizona';
    $states['AR'] = 'Arkansas';
    $states['CA'] = 'California';
    $states['CO'] = 'Colorado';
    $states['CT'] = 'Connecticut';
    $states['DE'] = 'Delaware';
    $states['DC'] = 'District Of Columbia';
    $states['FL'] = 'Florida';
    $states['GA'] = 'Georgia';
    $states['HI'] = 'Hawaii';
    $states['ID'] = 'Idaho';
    $states['IL'] = 'Illinois';
    $states['IN'] = 'Indiana';
    $states['IA'] = 'Iowa';
    $states['KS'] = 'Kansas';
    $states['KY'] = 'Kentucky';
    $states['LA'] = 'Louisiana';
    $states['ME'] = 'Maine';
    $states['MD'] = 'Maryland';
    $states['MA'] = 'Massachusetts';
    $states['MI'] = 'Michigan';
    $states['MN'] = 'Minnesota';
    $states['MS'] = 'Mississippi';
    $states['MO'] = 'Missouri';
    $states['MT'] = 'Montana';
    $states['NE'] = 'Nebraska';
    $states['NV'] = 'Nevada';
    $states['NH'] = 'New Hampshire';
    $states['NJ'] = 'New Jersey';
    $states['NM'] = 'New Mexico';
    $states['NY'] = 'New York';
    $states['NC'] = 'North Carolina';
    $states['ND'] = 'North Dakota';
    $states['OH'] = 'Ohio';
    $states['OK'] = 'Oklahoma';
    $states['OR'] = 'Oregon';
    $states['PA'] = 'Pennsylvania';
    $states['RI'] = 'Rhode Island';
    $states['SC'] = 'South Carolina';
    $states['SD'] = 'South Dakota';
    $states['TN'] = 'Tennessee';
    $states['TX'] = 'Texas';
    $states['UT'] = 'Utah';
    $states['VT'] = 'Vermont';
    $states['VA'] = 'Virginia';
    $states['WA'] = 'Washington';
    $states['WV'] = 'West Virginia';
    $states['WI'] = 'Wisconsin';
    $states['WY'] = 'Wyoming';
    $value = esc_attr($_POST[$id]);
    $content = '<p><label for="'.$id.'">'.$label.': <span>*</span> <br><select name="'.$id.'" value="'.$value.'">';
    foreach($states as $abbr => $name) {
        $content .= '<option value="'.$abbr.'"';
        if ($abbr == $value) $content .= ' selected';
        $content .= '>'.$name.'</option>';
    }
    $content .= '</select></label></p>';
    return $content;
}

?>