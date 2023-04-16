<?php

require_once('usps-address-sanitizer.php');
require_once('googlemaps.php');
require_once('lp-form-utils.php');

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

    if ($_SERVER['REQUEST_METHOD'] == 'POST' && array_key_exists('lp-petition-step', $_POST)) {
        $continue_form_render = true;
        $output = lp_attempt_submit($continue_form_render);
        if (!$continue_form_render)
            return $output;
    }

    if (!set_campaign($atts['campaign'], $output)) {
        return $output;
    }

    $output .= lp_render_petition_form($content, 1);
    return $output;
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

function lp_attempt_submit(&$continue_form_render)
{
    global $wpdb;

    $continue_form_render = false;
    $content = '';

    // This is annoying.  https://wordpress.stackexchange.com/questions/34866/stop-wordpress-automatically-escaping-post-data
    $_POST = wp_unslash($_POST);

    if (!check_captcha_in_post_body($content, $continue_form_render)) {
        return $content;
    }

    if (!array_key_exists('campaign', $_SESSION)) {
        $content .= '<div class="submit-error">Page timed out.  Please re-submit.</div>';
        $continue_form_render = true;
        return $content;
    }

    //$content .= '<pre>$_POST = ' . var_export($_POST, true) . "\n".' $_SESSION = ' . var_export($_SESSION, true) . "\n" . '$_FILES = ' . var_export($_FILES, true) . '</pre>';
    if (!array_key_exists('lp_petition_step_1', $_SESSION) || !array_key_exists('title', $_POST)) {
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

        $signer = get_signer($_SESSION['campaign']->id, $_POST['signer_name'], $sanitized_address_id);
        //$content .= '<div>Signer:<pre>' . var_export($signer, true) . '</pre></div>';

        if ($signer) {
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
            // If this person has already signed, load in values that they submitted previously.
            $_POST['is_supporter'] = $signer->is_supporter ? 'true' : 'false';
            $_POST['comments'] = $signer->comments;
            $_POST['title'] = $signer->title;
            if ($signer->is_helper) $_POST['is_helper'] = true;
            if ($signer->share_granted) $_POST['is_share'] = true;

            //$content .= '<div>$_POST:<pre>' . var_export($_POST, true) . '</pre></div>';
        }

        if (!$signer || $sanitized_address_id !== $signer->address_id) {
            $coordinates = geocode($sanitized_address);
            if ($coordinates)
                update_coordinates($sanitized_address_id, $coordinates);
        }

        $_SESSION['lp_petition_step_1'] = array(
            'name'                => $_POST['signer_name'],
            'address_id'          => $sanitized_address_id,
            'original_address_id' => $original_address_id,
            'email'               => $email,
            'phone'               => $phone,
        );
        $content = lp_render_petition_form($content, 2, $signer);
    } else {
        $table_name = $wpdb->prefix . 'lp_signer';

        $step_2 = array(
            'campaign_id'         => $_SESSION['campaign']->id,
            'title'               => $_POST['title'],
            'comments'            => $_POST['comments'],
            'is_supporter'        => $_POST['is_supporter'] == 'true' ? 1 : 0,
            'share_granted'       => array_key_exists('is_share', $_POST) ? 1 : 0,
            'is_helper'           => array_key_exists('is_helper', $_POST) ? 1 : 0
        );

        $values = array_merge($_SESSION['lp_petition_step_1'], $step_2);

        $signer = get_signer($_SESSION['campaign']->id, $values['name'], $values['address_id']);

        if (!$signer) {
            $wpdb->insert($table_name, $values);
        } else {
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
            $signer = get_signer($_SESSION['campaign']->id, $values['name'], $values['address_id']);
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
            $upload_dir = $upload_dir['basedir'] . '/local-petition/' . $_SESSION['campaign']->slug . '/' . $signer->id . '/';
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
            if ($result === false) {
                throw new Exception('Failed to update ' . $table_name . ' with photo file.  $signer = ' . var_export($signer, true) . ', $file = ' . var_export($file, true));
            }
            $signer->photo_file = $file['name'];
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

function lp_render_petition_form($content, $step, $signer = null)
{
    if (LP_PRODUCTION)
        wp_enqueue_script('recaptcha');
    else
        $content .= '<p><i>This site is in debug/development mode</i></p>';

    $content .= '<form autocomplete="on" id="local-petition-form" class="petition" action="' . get_permalink() . '" method="post" enctype="multipart/form-data">';
    if ($step == 1) {
        $content .= '<p>' . get_input('Name', 'signer_name', true, 50) . '</p>';
        $content .= '<p>' . get_input('Address Line 1', 'line_1', true, 40) . '</p>';
        $content .= '<p>' . get_input('Address Line 2 (optional)', 'line_2', false, 40) . '</p>';
        $content .= '<p>' . get_input('City', 'city', true, 20) . '</p>';
        $content .= '<p>' . get_state_input('State', 'state', true) . '</p>';
        $content .= '<p>' . get_input('Zip', 'zip', true, 5) . '</p>';
        $content .= '<p>' . get_input('Email', 'email', false, 50, 'email') . '</p>';
        $content .= '<p>' . get_input('Phone', 'phone', false, 20, 'tel') . '</p>';
        $submit_title = 'Next';
    } else if ($step == 2) {
        $content .= '<p>Pick One:<br>';
        $is_supporter = $_POST['is_supporter'] ?? 'true';
        $content .= '<label><input type="radio" name="is_supporter" value="true"' . ($is_supporter == 'true' ? ' checked' : '') . '> Yes, I\'m a supporter</label><br>';
        $content .= '<label><input type="radio" name="is_supporter" value="false"' . ($is_supporter == 'false' ? ' checked' : '') . '> No, I\'m not a supporter</input></label></p>';
        $content .= '<p>~~ Optional Information ~~</p>';
        $content .= '<p>' . get_textarea('Comments', 'comments') . '</p>';
        if ($_SESSION['campaign']->comment_suggestion)
            $content .= '<p>' . $_SESSION['campaign']->comment_suggestion . '</p>';
        $content .= '<p>' . get_input('Title', 'title', false, 50) . '</p>';
        if ($_SESSION['campaign']->title_suggestion)
            $content .= '<p>' . $_SESSION['campaign']->title_suggestion . '</p>';
        $content .= '<p><label>Photograph:<br><input type="file" id="photo" name="photo" value=""></label>';
        if ($signer && $signer->photo_file) $content .= ' (Photo already uploaded)';
        $content .= '</p>';
        $is_helper = array_key_exists('is_helper', $_POST);
        $content .= '<p><label><input type="checkbox" id="is_helper" name="is_helper"' . ($is_helper ? ' checked' : '') . '> I would like to get involved to help this effort.</label></p>';
        $content .= $_SESSION['campaign']->privacy_statement;

        $is_share = array_key_exists('is_share', $_POST) || !$signer;
        $content .= '<p><label><input type="checkbox" id="is_share" name="is_share"' . ($is_share ? ' checked' : '') . '> Yes, please share my name and optional information (comments, title, photo) I have provided with site visitors.  <i>Sharing your name, comments, and photo will help promote this effort.</i></label></p>';
        $submit_title = 'Submit';
    }
    $content .= add_submit_button_with_captcha($submit_title);
    $content .= '<input type="hidden" name="lp-petition-step" value="' . $step . '">';
    $content .= '</form>';
    return $content;
}
