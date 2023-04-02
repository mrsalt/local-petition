<?php

require_once('usps-address-sanitizer.php');

function lp_render_petition($atts = [], $content = null)
{
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

    if (!set_campaign_session_var($atts['campaign'], $output)) {
        return $output;
    }

    $output .= lp_render_petition_form($atts, $content);
    return $output;
}

function set_campaign_session_var($campaign_slug, &$output)
{
    if (isset($_SESSION['lp_campaign_id']) && isset($_SESSION['campaign']) && $_SESSION['campaign'] === $campaign_slug) {
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

    $_SESSION['lp_campaign_id'] = intval($campaign->id);
    $_SESSION['campaign'] = $campaign_slug;
    return true;
}

function lp_attempt_submit(&$continue_form_render)
{
    global $wpdb;

    $continue_form_render = false;
    $content = '';

    // This is annoying.  https://wordpress.stackexchange.com/questions/34866/stop-wordpress-automatically-escaping-post-data
    $_POST = wp_unslash($_POST);

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
        }
    }

    //$content .= '<pre>$_POST = ' . var_export($_POST, true) . "\n" . '$_FILES = ' . var_export($_FILES, true) . '</pre>';

    $email = array_key_exists('email', $_POST) ? $_POST['email'] : null;
    $phone = array_key_exists('phone', $_POST) ? $_POST['phone'] : null;

    if (!$email && !$phone) {
        $content .= '<div class="submit-error">For verification purposes, an email address or phone number is required.</div>';
        $continue_form_render = true;
        return $content;
    }

    $sanitized_address = sanitize_address($_POST);

    if (array_key_exists('Error', $sanitized_address)) {
        $content .= '<div class="submit-error">An error occurred with the address submitted: ' . $sanitized_address['Error'] . '</div>';
        $continue_form_render = true;
        return $content;
    }

    if (!array_key_exists('lp_campaign_id', $_SESSION)) {
        $content .= '<div class="submit-error">Page timed out.  Please re-submit.</div>';
        $continue_form_render = true;
        return $content;
    }

    if (!array_key_exists('signer_name', $_POST)) {
        $content .= '<div class="submit-error">Name not submitted.  Please re-submit.</div>';
        $continue_form_render = true;
        return $content;
    }

    //$content .= '<div>Sanitized Address:<pre>' . var_export($sanitized_address, true) . '</pre></div>';

    $sanitized_address_id = store_address($sanitized_address);
    if (!$sanitized_address_id)
        throw new Exception('No sanitized address ID. $sanitized_address = ' . var_export($sanitized_address, true));
    //$content .= '<div>Sanitized address id:<pre>' . var_export($sanitized_address_id, true) . '</pre></div>';

    $original_address_id = store_address($_POST, $sanitized_address_id);
    if (!$original_address_id)
        throw new Exception('No original address ID. $_POST = ' . var_export($_POST, true));
    //$content .= '<div>Original address id:<pre>' . var_export($original_address_id, true) . '</pre></div>';

    $table_name = $wpdb->prefix . 'lp_signer';

    $values = array(
        'campaign_id'         => intval($_SESSION['lp_campaign_id']),
        'name'                => $_POST['signer_name'],
        'address_id'          => $sanitized_address_id,
        'original_address_id' => $original_address_id,
        'title'               => $_POST['title'],
        'comments'            => $_POST['comments'],
        'is_supporter'        => $_POST['is_supporter'] == 'true' ? 1 : 0,
        'share_granted'       => array_key_exists('is_share', $_POST) ? 1 : 0,
        'is_helper'           => array_key_exists('is_helper', $_POST) ? 1 : 0,
        'email'               => $email,
        'phone'               => $phone,
    );

    $signer = get_signer($_SESSION['lp_campaign_id'], $_POST['signer_name'], $sanitized_address_id);

    if (!$signer) {
        $wpdb->insert($table_name, $values);
    } else {
        if ($signer->email && $signer->email !== $email) {
            $content .= '<div class="submit-error">In order to submit changes, the same email address must be used which was used originally.</div>';
            $continue_form_render = true;
            return $content;
        }
        if ($signer->phone && $signer->phone !== $phone) {
            $content .= '<div class="submit-error">In order to submit changes, the same phone number must be used which was used originally.</div>';
            $continue_form_render = true;
            return $content;
        }
        // Since we're not (currently) requiring authentication to make changes, record the changes are are being submitted
        // to be able to detect if anything unusual is happening.
        foreach ($values as $key => $value) {
            if ($value !== $signer->$key) {
                //$content .= '<div>Recording change to field ' . $key . ', old value: ' . $signer->$key . ' (' . gettype($signer->$key) . '), new value: ' . $value . ' (' . gettype($value) . ')</div>';
                record_update($table_name, $key, $signer->id, $signer->$key);
            }
        }
        $wpdb->update($table_name, $values, array('id' => $signer->id));
    }

    if (!$signer) {
        $signer = get_signer($_SESSION['lp_campaign_id'], $_POST['signer_name'], $sanitized_address_id);
        if (!$signer) {
            throw new Exception('Failed to upsert ' . $table_name);
        }
    }

    if (isset($_FILES['photo']) && $_FILES['photo']['tmp_name']) {
        $file = $_FILES['photo'];

        $type = $file['type'];
        if ($type !== 'image/jpeg') {
            $content .= '<div class="submit-error">Photo should be a .jpg file</div>';
            $continue_form_render = true;
            return $content;
        }

        $tmp_path = $file['tmp_name'];

        $upload_dir = wp_upload_dir();
        $upload_dir = $upload_dir['basedir'] . '/local-petition/' . $_SESSION['campaign'] . '/' . $signer->id . '/';
        if (!is_dir($upload_dir)) {
            if (!mkdir($upload_dir, 0666, true)) throw new Exception('Failed to mkdir ' . $upload_dir);
        }
        $final_path = $upload_dir . $file['name'];

        $result = move_uploaded_file($tmp_path, $final_path);
        if (!$result)
            throw new Exception('Failed to move uploaded file: ' . var_export($_FILES, true));

        $result = $wpdb->update(
            $table_name,
            array(
                'photo_file' => $file['name'],
                'photo_file_type' => $file['type']
            ),
            array('id' => $signer->id)
        );
        $signer->photo_file = $file['name'];

        if (!$result) {
            throw new Exception('Failed to update ' . $table_name . ' with photo file');
        }
    }

    if (!$continue_form_render) {
        $content .= '<div id="post-submit">';
        if ($values['is_supporter']) {
            $content .= '<p style="font-size: xx-large">' . $signer->name . ', thank you for signing our petition!</p>';
        } else {
            $content .= '<p>' . $signer->name . ', thank you for your feedback.  We\'re sorry to hear that you don\'t support this initiative.';
            if (strlen($values['comments']) > 5) {
                $content .= '  Do your comments describe why?  (if not, hit back and leave more comments to help us understand why)';
            } else {
                $content .= '  Would you please hit back and add comments to help us understand why?';
            }
            $content .= '</p>';
        }
        $content .= '<p><label>Title: <span>' . $values['title'] . '</span></label></p>';
        $content .= '<p><label>Photo Provided: <span>' . ($signer->photo_file ? 'Yes' : 'No') . '</span></label></p>';
        $content .= '<p><label>Consent Granted to Share: <span>' . ($values['share_granted'] ? 'Yes' : 'No') . '</span></label></p>';
        $content .= '<p><label>I Want to Help: <span>' . ($values['is_helper'] ? 'Yes' : 'No') . '</span></label></p>';
        $content .= '<p><label>Comments:<div class="comment-preview">' . $values['comments'] . '</div></label></p>';
        $content .= '<p><i>If you wish to change anything, press back and make changes.  You may also make changes in the future if you return to this page.</i></p>';
        $content .= '<p>Click this button to allow another individual to sign: <button type="button" onclick="location.href=\'' . get_permalink() . '\'">Sign Again</button></p>';
        $content .= '</div>';
    }

    return $content;
}

function get_signer($campaign_id, $name, $address_id)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'lp_signer';
    $query = prepare_query("SELECT * FROM {$table_name} WHERE `campaign_id` = %s AND `name` = %s AND address_id = %s", $campaign_id, $name, $address_id);
    $results = $wpdb->get_results($query);
    if (count($results) == 0) {
        return null;
    }
    // get_results uses mysqli_fetch_object; mysql returns all values as strings.  Let's convert integer values here to integers:
    $signer = $results[0];
    $int_properties = array('id', 'campaign_id', 'address_id', 'original_address_id', 'is_supporter', 'share_granted', 'is_helper');
    foreach ($int_properties as $field)
        $signer->$field = intval($signer->$field);
    return $signer;
}

function lp_render_petition_form($atts, $content)
{
    if (LP_PRODUCTION)
        wp_enqueue_script('recaptcha');
    else
        $content .= '<p><i>This site is in debug/development mode</i></p>';
    $content .= '<form autocomplete="on" id="local-petition-form" class="petition" action="' . get_permalink() . '" method="post" enctype="multipart/form-data">';

    $content .= '<p>Pick One:<br>';
    $is_supporter = $_POST['is_supporter'] ?? 'true';
    $content .= '<label><input type="radio" name="is_supporter" value="true"' . ($is_supporter == 'true' ? ' checked' : '') . '> Yes, I\'m a supporter</label><br>';
    $content .= '<label><input type="radio" name="is_supporter" value="false"' . ($is_supporter == 'false' ? ' checked' : '') . '> No, I\'m not a supporter</input></label></p>';

    $content .= '<p>' . get_input('Name', 'signer_name', true) . '</p>';
    $content .= '<p>' . get_input('Address Line 1', 'line_1', true, 40) . '</p>';
    $content .= '<p>' . get_input('Address Line 2 (optional)', 'line_2', false, 40) . '</p>';
    $content .= '<p>' . get_input('City', 'city', true, 20) . '</p>';
    $content .= '<p>' . get_state_input('State', 'state', true) . '</p>';
    $content .= '<p>' . get_input('Zip', 'zip', true, 5) . '</p>';
    $content .= '<p>' . get_input('Email', 'email', false, 50) . '</p>';
    $content .= '<p>' . get_input('Phone', 'phone', false, 20) . '</p>';

    $content .= '<p>Optional Information:</p>';
    $content .= '<p>' . get_textarea('Comments', 'comments') . '</p>';
    $content .= '<p>' . get_input('Title', 'title', false, 50) . '</p>';
    $content .= '<p><label>Photograph:<br><input type="file" id="photo" name="photo" value=""></label></p>';
    $is_helper = array_key_exists('is_helper', $_POST);
    $content .= '<p><label><input type="checkbox" id="is_helper" name="is_helper"' . ($is_helper ? ' checked' : '') . '> I would like to get involved to help this effort.</label></p>';
    $content .= '<div>Privacy:<ul style="margin-left: 15px">';
    $content .= '<li>Your name, photo, and comments will be shown to site visitors only with your consent, which you may give by checking the box below.</li>';
    $content .= '<li>The number of signers in an area will be shown on a city map.</li>';
    $content .= '<li>Information submitted here may also be shared with Boise City Officials.</li>';
    $content .= '</ul></div>';

    $is_share = array_key_exists('is_share', $_POST);
    $content .= '<p><label><input type="checkbox" id="is_share" name="is_share"' . ($is_share ? ' checked' : '') . '> Yes, please share my name and optional information I have provided with site visitors.  <i>Sharing your name, comments, and photo will help promote this effort.</i></label></p>';
    if (LP_PRODUCTION) {
        $content .= '<script>function onSubmit(token) { document.getElementById("local-petition-form").submit(); }</script>';
        $content .= '<p><button class="g-recaptcha" data-sitekey="' . reCAPTCHA_site_key . '" data-callback=\'onSubmit\' data-action=\'submit\'>Submit</button></p>';
    } else {
        $content .= '<p><input type="submit"></p>';
    }
    $content .= '</form>';
    return $content;
}

function get_input($label, $id, $required = false, $max_chars = false)
{
    $value = array_key_exists($id, $_POST) ? esc_attr($_POST[$id]) : '';
    return
        '<label for="' . $id . '">' . $label . ': <br>' . //<span>*</span>
        '<input id="' . $id . '" type="text" name="' . $id . '" value="' . $value . '"' . ($required ? ' required="true"' : '') . ($max_chars ? ' maxlength="' . $max_chars . '"' : '') . '></label>';
}

function get_textarea($label, $id)
{
    $value = array_key_exists($id, $_POST) ? esc_textarea($_POST[$id]) : '';
    return
        '<label for="' . $id . '">' . $label . ': <br>' . //<span>*</span>
        '<textarea id="' . $id . '" name="' . $id . '" rows="10">' . $value . '</textarea></label>';
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
    $value = array_key_exists($id, $_POST) ? esc_attr($_POST[$id]) : '';
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
