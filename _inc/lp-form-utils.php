<?php

define('LP_RECAPTCHA_MIN_SCORE', 0.5);

function verify_recaptcha($token)
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
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
        $error = curl_error($ch);
        curl_close($ch);
        throw new Exception('curl_exec() returned false: curl_error=' . $error);
    }
    curl_close($ch);
    return json_decode($result);
}

/**
 * Verifies a reCAPTCHA v3 token with Google.  Returns true only if Google says the
 * token is valid, was issued for the expected action, and scored as likely human.
 * On failure, $error_code is set to 'duplicate', 'unavailable' or 'failed'.
 */
function lp_recaptcha_token_is_human($token, $action, &$error_code)
{
    $error_code = 'failed';
    if (!is_string($token) || $token === '') {
        return false;
    }
    try {
        $result = verify_recaptcha($token);
    } catch (Exception $e) {
        error_log('reCAPTCHA verification unavailable: ' . $e->getMessage());
        $error_code = 'unavailable';
        return false;
    }
    if (!is_object($result) || empty($result->success)) {
        $codes = (is_object($result) && isset($result->{'error-codes'})) ? (array) $result->{'error-codes'} : [];
        if (in_array('timeout-or-duplicate', $codes)) {
            $error_code = 'duplicate';
        }
        return false;
    }
    if (!isset($result->action) || $result->action !== $action) {
        return false;
    }
    if (!isset($result->score) || $result->score < LP_RECAPTCHA_MIN_SCORE) {
        error_log('reCAPTCHA rejected submission, score=' . ($result->score ?? 'none') . ', ip=' . $_SERVER['REMOTE_ADDR']);
        return false;
    }
    return true;
}

// Returns true if the submission may proceed.  On false, $content holds the error to show.
function check_captcha_in_post_body(&$content, &$continue_form_render)
{
    if (!LP_PRODUCTION) {
        return true;
    }
    $token = isset($_POST['g-recaptcha-response']) ? $_POST['g-recaptcha-response'] : '';
    if (lp_recaptcha_token_is_human($token, 'submit', $error_code)) {
        return true;
    }
    if ($error_code === 'duplicate') {
        $content .= '<div class="submit-error">Duplicate submission error.  Please scroll to the bottom and submit again.</div>';
        $continue_form_render = true;
    } elseif ($error_code === 'unavailable') {
        $content .= '<div class="submit-error">Human verification is temporarily unavailable.  Please try again.</div>';
        $continue_form_render = true;
    } else {
        $content .= '<div class="submit-error">Human verification failed.</div>';
    }
    return false;
}

function add_submit_button_with_captcha($buttons)
{
    $text = '';
    if (LP_PRODUCTION) {
        $text .=
            '<script>' .
            '(function () {' .
            '  let captchaCompleted = false;' .
            '  document.getElementById("local-petition-form").addEventListener("submit", (event) => {' .
            '    if (captchaCompleted) return;' .
            '    event.preventDefault();' .
            '    grecaptcha.ready(function() {' .
            '      grecaptcha.execute("' . reCAPTCHA_site_key . '", {action: "submit"}).then(function(token) {' .
            '        captchaCompleted = true;' .
            '        document.getElementById("g-recaptcha-response").value = token;' .
            '        if (event.submitter) {' .
            '          let proxy = document.getElementById("g-recaptcha-submit-proxy");' .
            '          proxy.name = event.submitter.name;' .
            '          proxy.value = event.submitter.value;' .
            '        }' .
            '        document.getElementById("local-petition-form").submit();' .
            '      });' .
            '    });' .
            '  });' .
            '})();' .
            '</script>' .
            '<input type="hidden" id="g-recaptcha-submit-proxy">'.
            '<input type="hidden" name="g-recaptcha-response" id="g-recaptcha-response">';
    }
    foreach ($buttons as $button) {
        $text .= '<p><button type="submit"';
        $text .= ' class="g-recaptcha"';
        if (array_key_exists('name', $button))
            $text .= ' name="'.$button['name'].'"';
        if (array_key_exists('value', $button))
            $text .= ' value="'.$button['value'].'"';
        if (array_key_exists('description', $button))
            $text .= ' title="'.$button['description'].'"';
        $text .= '>' . $button['title'] . '</button></p>';
    }
    return $text;
}

function get_input($label, $id, $required = false, $max_chars = false, $type = 'text', $style = 'label', $autofocus = false, $placeholder = null)
{
    $value = array_key_exists($id, $_POST) ? esc_attr($_POST[$id]) : '';
    if (strlen($value) == 0 && array_key_exists($id, $_SESSION)) $value = $_SESSION[$id];
    if (!$placeholder && $style == 'placeholder') $placeholder = esc_attr($label);
    else if ($placeholder) $placeholder = esc_attr($placeholder);
    return ($style == 'label' ? '<label for="' . $id . '">' . $label . ': <br>' : '') //<span>*</span>
        . '<input id="' . $id . '"'
        . ' type="' . $type . '"'
        . ' name="' . $id . '"'
        . ($autofocus ? ' autofocus' : '')
        . ($placeholder ? ' placeholder="' . esc_attr($placeholder) . '"' : '')
        . (strlen($value) > 0 ? ' value="' . $value . '"' : '')
        . ($required ? ' required="true"' : '')
        . ($max_chars ? ' maxlength="' . $max_chars . '"' : '') . '></label>';
}

function get_textarea($label, $id, $required = false, $style = 'label')
{
    $value = array_key_exists($id, $_POST) ? esc_textarea($_POST[$id]) : '';
    return ($style == 'label' ? '<label for="' . $id . '">' . $label . ': <br>' : '') //<span>*</span>
        . '<textarea id="' . $id . '"'
        . ' name="' . $id . '"'
        . ' rows="10"'
        . ($required ? ' required="true"' : '')
        . ($style == 'placeholder' ? ' placeholder="' . esc_attr($label) . '"' : '')
        . '>' . $value . '</textarea></label>';
}

function get_state_input($label, $id, $required = false, $style = 'label')
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
    $content = ($style == 'label' ? '<label for="' . $id . '">' . $label . ': <br>' : '');
    $content .= '<select name="' . $id . '" id="' . $id . '">';
    if ($required) $content .= ' required="true"';
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

function are_phone_numbers_equal($phone1, $phone2)
{
    $values_to_ignore = array(" ", "(", ")", "-");
    $clean_phone1 = str_replace($values_to_ignore, '', $phone1);
    $clean_phone2 = str_replace($values_to_ignore, '', $phone2);
    return $clean_phone1 === $clean_phone2;
}

function safe_input_name($str)
{
    return preg_replace( '/[^a-z0-9\-]/i', '_', $str);
}