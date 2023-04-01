<?php

require_once('usps-address-sanitizer.php');

function lp_render_petition( $atts = [], $content = null) {
    //$attributes = '<div>Attributes:<pre>'.htmlspecialchars(var_export($atts, true)).'</pre></div>';
    //return $attributes . '<div>Content:<pre>'.htmlspecialchars(var_export($content, true)).'</pre></div>';
    if (!isset($content)) {
        return 'Error: no content for shortcode <b>local_petition</b>';
    }

    if (!isset($atts['campaign'])) {
        return 'Error: no "campaign" attribute found in shortcode <b>local_petition</b>';
    }

    $output = '';

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $continue_form_render = true;
        $output = lp_attempt_submit($continue_form_render);
        if (!$continue_form_render)
            return $output;
    }

    $output .= lp_render_petition_form( $atts, $content );
    return $output;
}

function lp_attempt_submit(&$continue_form_render) {
    global $wpdb;

    $continue_form_render = false;

    $content = '<pre>$_POST = '.var_export($_POST, true)."\n".'$_FILES = '.var_export($_FILES, true).'</pre>';

    $sanitized_address = sanitize_address($_POST);

    if (array_key_exists('Error', $sanitized_address)) {
        $content .= '<div class="submit-error">An error occurred with the address submitted: '.$sanitized_address['Error'].'</div>';
        $continue_form_render = true;
        return $content;
    }

    $content .= '<div>Sanitized Address:<pre>'.var_export($sanitized_address, true).'</pre></div>';

    $sanitized_address_id = store_address($sanitized_address);
    $content .= '<div>Sanitized address id:<pre>'.var_export($sanitized_address_id, true).'</pre></div>';

    $original_address_id = store_address($_POST);
    $content .= '<div>Original address id:<pre>'.var_export($sanitized_address_id, true).'</pre></div>';

    return $content;


    //$wpdb->prepare( "SELECT * FROM {$campaign_table_name} WHERE slug = %s", $atts['campaign'] );
}

function lp_render_petition_form( $atts, $content ) {
    global $wpdb;
    $campaign_table_name = $wpdb->prefix . 'lp_campaign';

    $results = $wpdb->get_results(
        $wpdb->prepare( "SELECT * FROM {$campaign_table_name} WHERE slug = %s", $atts['campaign'] )
    );

    if (count($results) == 0) {
        return "Error: no campaign attribute found for campaign \"{$atts['campaign']}\"";
    }
    $campaign = $results[0];

    $_SESSION['lp_campaign_id'] = $campaign->id;

    $content .= '<form autocomplete="on" class="petition" action="'.get_permalink().'" method="post" enctype="multipart/form-data">';

    $content .= '<p>Pick One:<br>';
    if (!isset($_POST['is_supporter']))
        $is_supporter = 'true';
    else
        $is_supporter = $_POST['is_supporter'];
    $content .= '<label><input type="radio" name="is_supporter" value="true"'.($is_supporter == 'true' ? ' checked' : '').'> Yes, I\'m a supporter</label><br>';
    $content .= '<label><input type="radio" name="is_supporter" value="false"'.($is_supporter == 'false' ? ' checked' : '').'> No, I\'m not a supporter</input></label></p>';

    $content .= '<p>'.get_input('Name', 'signer_name').'</p>';
    $content .= '<p>'.get_input('Address Line 1', 'line_1').'</p>';
    $content .= '<p>'.get_input('Address Line 2 (optional)', 'line_2').'</p>';
    $content .= '<p>'.get_input('City', 'city').'</p>';
    $content .= '<p>'.get_state_input('State', 'state').'</p>';
    $content .= '<p>'.get_input('Zip', 'zip').'</p>';
    $content .= '<p>Optional Information:</p>';
    $content .= '<p>'.get_textarea('Comments', 'comments').'</p>';
    $content .= '<p>'.get_input('Title', 'title').'</p>';
    $content .= '<p>'.get_input('Email', 'email').'</p>';
    $content .= '<p><label>Photograph:<br><input type="file" id="photo" name="photo" value=""></label></p>';
    $content .= '<p><label><input type="checkbox" id="is_helper" name="is_helper"> I would like to get involved to help this effort.</label></p>';
    $content .= '<div><p>Privacy:<br>Your name, picture, and comments will be shown to site visitors only with your consent, which you may give by checking the box below.<br>';
    $content .= 'Anonymous markers will show petition signers on a city map.<br>';
    $content .= 'Information submitted here may also be shared with Boise City Officials.</p></div>';
    $content .= '<p><label><input type="checkbox" id="is_share" name="is_share"> Yes, please share my name and any other optional information I have provided with site visitors.</label></p>';
    $content .= '<p><input type="submit"></p>';
    $content .= '</form>';
    return $content;
}

function get_input($label, $id) {
    $value = array_key_exists($id, $_POST) ? esc_attr($_POST[$id]) : '';
    return
        '<label for="'.$id.'">'.$label.': <br>'. //<span>*</span>
        '<input id="'.$id.'" type="text" name="'.$id.'" value="'.$value.'"></label>';
}

function get_textarea($label, $id) {
    $value = array_key_exists($id, $_POST) ? esc_textarea($_POST[$id]) : '';
    return
        '<label for="'.$id.'">'.$label.': <br>'. //<span>*</span>
        '<textarea id="'.$id.'" name="'.$id.'" rows="10">'.$value.'</textarea></label>';
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
    $value = array_key_exists($id, $_POST) ? esc_attr($_POST[$id]) : '';
    $content = '<label for="'.$id.'">'.$label.': <br><select name="'.$id.'" id="'.$id.'">';
    foreach($states as $abbr => $name) {
        $content .= '<option value="'.$abbr.'"';
        if ($abbr == $value) $content .= ' selected';
        $content .= '>'.$name.'</option>';
    }
    $content .= '</select></label>';
    return $content;
}
