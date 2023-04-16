<?php

function verify_recaptcha($token)
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_URL => 'https://www.google.com/recaptcha/api/siteverify',
        CURLOPT_POST => 1,
        CURLOPT_POSTFIELDS => http_build_query(array(
            'secret' => reCAPTCHA_secret,
            'response' => $token,
            'remoteip' => $_SERVER['REMOTE_ADDR']
        ))
    ]);
    $result = curl_exec($ch);
    if ($result === false) {
        throw new Exception('curl_exec() returned false: curl_error=' . curl_error($ch) . ', curl_get_info=' . var_export(curl_getinfo($ch), true));
    }
    curl_close($ch);
    return json_decode($result);
}

function check_captcha_in_post_body(&$content, &$continue_form_render)
{
    if (LP_PRODUCTION) {
        if (!array_key_exists('g-recaptcha-response', $_POST)) {
            throw new Exception('g-recaptcha-response not found');
        }
        $result = verify_recaptcha($_POST['g-recaptcha-response']);
        if (!$result->success) {
            if (in_array('timeout-or-duplicate', $result->{'error-codes'})) {
                $content .= '<div class="submit-error">Duplicate submission error.  Please scroll to the bottom and submit again.</div>';
                $continue_form_render = true;
                return $content;
            } else {
                $content .= '<div class="submit-error">Human verification failed.</div>';
                return $content;
            }
            return false;
        }
    }
    return true;
}

function add_submit_button_with_captcha($title)
{
    if (LP_PRODUCTION) {
        return '<script>function onSubmit(token) { document.getElementById("local-petition-form").submit(); }</script>' .
            '<p><button type="submit" class="g-recaptcha" data-sitekey="' . reCAPTCHA_site_key . '" data-callback=\'onSubmit\' data-action=\'submit\'>' . $title . '</button></p>';
    } else {
        return '<p><button type="submit">' . $title . '</button></p>';
    }
}

function get_input($label, $id, $required = false, $max_chars = false, $type = 'text')
{
    $value = array_key_exists($id, $_POST) ? esc_attr($_POST[$id]) : '';
    return
        '<label for="' . $id . '">' . $label . ': <br>' . //<span>*</span>
        '<input id="' . $id . '"'
        . ' type="' . $type . '"'
        . ' name="' . $id . '"'
        . ' ' . (strlen($value) > 0 ? 'value="' . $value . '"' : '')
        . ($required ? ' required="true"' : '')
        . ($max_chars ? ' maxlength="' . $max_chars . '"' : '') . '></label>';
}

function get_textarea($label, $id, $required = false)
{
    $value = array_key_exists($id, $_POST) ? esc_textarea($_POST[$id]) : '';
    return
        '<label for="' . $id . '">' . $label . ': <br>' . //<span>*</span>
        '<textarea id="' . $id . '" name="' . $id . '" rows="10"' . ($required ? ' required="true"' : '') . '>' . $value . '</textarea></label>';
}

function get_state_input($label, $id)
{
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
    $value = array_key_exists($id, $_POST) ? esc_attr($_POST[$id]) : $_SESSION['campaign']->default_state;
    $content = '<label for="' . $id . '">' . $label . ': <br><select name="' . $id . '" id="' . $id . '">';
    foreach ($states as $abbr => $name) {
        $content .= '<option value="' . $abbr . '"';
        if ($abbr == $value) $content .= ' selected';
        $content .= '>' . $name . '</option>';
    }
    $content .= '</select></label>';
    return $content;
}

function record_update($table_name, $field, $id, $previous_value)
{
    global $wpdb;
    $values = array('table_name' => $table_name, 'id' => $id, 'field' => $field, 'previous' => var_export($previous_value, true));
    $wpdb->insert($wpdb->prefix . 'lp_updates', $values);
}
