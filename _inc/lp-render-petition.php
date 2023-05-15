<?php

require_once('lp-init.php');
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
    if (!set_campaign($atts['campaign'], $output)) {
        return $output;
    }

    $style = 'label';
    if (is_array($atts) && array_key_exists('style', $atts))
        $style = $atts['style'];

    if ($_SERVER['REQUEST_METHOD'] == 'POST' && array_key_exists('lp-petition-step', $_POST)) {
        $continue_form_render = true;
        $output = lp_attempt_submit($style, $continue_form_render);
        if (!$continue_form_render)
            return $output;
    }

    $output .= lp_render_petition_form($style, $content, 1);
    return $output;
}

function lp_attempt_submit($style, &$continue_form_render)
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

    if (is_user_logged_in()) {
        $_SESSION['is_proxy'] = array_key_exists('is_proxy', $_POST);
        if ($_SESSION['is_proxy']) {
            $_SESSION['proxy_id'] = $_POST['proxy_id'];
            $_SESSION['proxy_date'] = $_POST['proxy_date'];
        }
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
            if ($signer->phone && !are_phone_numbers_equal($signer->phone, $phone)) {
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
            $_POST['age'] = $signer->age;

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
        $content = lp_render_petition_form($style, $content, 2, $signer);
    } else {
        $table_name = $wpdb->prefix . 'lp_signer';

        $step_2 = array(
            'campaign_id'         => $_SESSION['campaign']->id,
            'title'               => $_POST['title'],
            'comments'            => $_POST['comments'],
            'is_supporter'        => $_POST['is_supporter'] == 'true' ? 1 : 0,
            'share_granted'       => array_key_exists('is_share', $_POST) ? 1 : 0,
            'is_helper'           => array_key_exists('is_helper', $_POST) ? 1 : 0,
            'age'                 => $_POST['age']
        );

        $values = array_merge($_SESSION['lp_petition_step_1'], $step_2);

        $signer = get_signer($_SESSION['campaign']->id, $values['name'], $values['address_id']);

        $sensitive_info_changed = false;
        if (!$signer) {
            $wpdb->insert($table_name, $values);
        } else {
            // Since we're not (currently) requiring authentication to make changes, record the changes are are being submitted
            // to be able to detect if anything unusual is happening.
            foreach ($values as $key => $value) {
                if ($value !== $signer->$key) {
                    //$content .= '<div>Recording change to field ' . $key . ', old value: ' . $signer->$key . ' (' . gettype($signer->$key) . '), new value: ' . $value . ' (' . gettype($value) . ')</div>';
                    if (in_array($key, array('title', 'comments')))
                        $sensitive_info_changed = true;
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
            if (is_user_logged_in()) {
                if ($_SESSION['is_proxy']) {
                    $table_name = $wpdb->prefix . 'lp_proxy_signature';
                    $result = $wpdb->insert($table_name, array(
                        'campaign_id' => $_SESSION['campaign']->id,
                        'entered_by' => $_SESSION['proxy_id'],
                        'signer_id' => $signer->id,
                        'wp_user_id' => wp_get_current_user()->ID,
                        'sign_date' => $_SESSION['proxy_date']
                    ));
                    if ($result === false) {
                        throw new Exception('Failed to insert into ' . $table_name);
                    }
                }
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
                if (!mkdir($upload_dir, 01777, true)) throw new Exception('Failed to mkdir ' . $upload_dir);
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
            $sensitive_info_changed = true;
            $signer->photo_file = $file['name'];
        }

        if ($sensitive_info_changed) {
            $result = $wpdb->update(
                $table_name,
                array('status' => 'Unreviewed'),
                array('id' => $signer->id)
            );
            if ($result === false) {
                throw new Exception('Failed to update ' . $table_name . ' with status.  $signer = ' . var_export($signer, true) . ', $file = ' . var_export($file, true));
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
            if ($values['is_supporter'] && $_SESSION['campaign']->post_sign_message) {
                $content .= '<p>' . $_SESSION['campaign']->post_sign_message . '</p>';
            }
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

function lp_render_petition_form($style, $content, $step, $signer = null)
{
    if (LP_PRODUCTION)
        wp_enqueue_script('recaptcha');
    else
        $content .= '<p><i>This site is in debug/development mode</i></p>';

    $content .= '<form autocomplete="on" id="local-petition-form" class="petition" action="' . get_permalink() . '" method="post" enctype="multipart/form-data">';
    if (is_user_logged_in()) {
        $content .= add_bulk_sign_inputs($_SESSION['campaign']->id);
    }
    if ($step == 1) {
        $content .= '<p>' . get_input('Name', 'signer_name', required: true, max_chars: 50, style: $style, autofocus: is_user_logged_in()) . '</p>';
        $content .= '<p>' . get_input('Address Line 1', 'line_1', required: true, max_chars: 40, style: $style) . '</p>';
        $content .= '<p>' . get_input('Address Line 2 (optional)', 'line_2', required: false, max_chars: 40, style: $style) . '</p>';
        $content .= '<p>' . get_input('City', 'city', required: true, max_chars: 20, style: $style) . '</p>';
        $content .= '<p>' . get_state_input('State', 'state', required: true, style: $style) . '</p>';
        $content .= '<p>' . get_input('Zip', 'zip', required: true, max_chars: 5, style: $style) . '</p>';
        $content .= '<p>' . get_input('Email', 'email', required: false, max_chars: 50, type: 'email', style: $style) . '</p>';
        $content .= '<p>' . get_input('Phone', 'phone', required: false, max_chars: 20, type: 'tel', style: $style) . '</p>';
        $submit_title = 'Next';
    } else if ($step == 2) {
        $content .= '<p>Pick One:<br>';
        $is_supporter = $_POST['is_supporter'] ?? 'true';
        $content .= '<label><input type="radio" name="is_supporter" value="true"' . ($is_supporter == 'true' ? ' checked' : '') . '> Yes, I\'m a supporter</label><br>';
        $content .= '<label><input type="radio" name="is_supporter" value="false"' . ($is_supporter == 'false' ? ' checked' : '') . '> No, I\'m not a supporter</input></label></p>';
        $content .= '<p>What is your age?<br>';
        $age = $_POST['age'] ?? null;
        $content .= '<label><input type="radio" name="age" required="true" value="&lt; 13"' . ($age == '< 13' ? ' checked' : '') . '> &lt; 13</label><br>';
        $content .= '<label><input type="radio" name="age" required="true" value="13 - 17"' . ($age == '13 - 17' ? ' checked' : '') . '> 13 - 17</input></label><br>';
        $content .= '<label><input type="radio" name="age" required="true" value="18+"' . ($age == '18+' ? ' checked' : '') . '> 18+</input></label></p>';
        $content .= '<p>~~ Optional Information ~~</p>';
        $content .= '<p>' . get_textarea('Comments', 'comments', style: $style) . '</p>';
        if ($_SESSION['campaign']->comment_suggestion)
            $content .= '<p>' . $_SESSION['campaign']->comment_suggestion . '</p>';
        $content .= '<p>' . get_input('Title', 'title', required: false, max_chars: 50, style: $style) . '</p>';
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
        $content .= '<script>watchImageInput(document.getElementById(\'photo\'), 0.8, 1600, 1600)</script>';
    }
    $content .= add_submit_button_with_captcha($submit_title);
    $content .= '<input type="hidden" name="lp-petition-step" value="' . $step . '">';
    $content .= '</form>';
    return $content;
}

function add_bulk_sign_inputs($campaign_id)
{
    $is_proxy = !array_key_exists('is_proxy', $_SESSION) || $_SESSION['is_proxy'];
    $proxy_id = array_key_exists('proxy_id', $_SESSION) ? $_SESSION['proxy_id'] : null;
    $proxy_date = array_key_exists('proxy_date', $_SESSION) ? $_SESSION['proxy_date'] : '';
    $content = '<div style="border: 1px solid gray; padding: 20px">';
    $content .= '<p><input type="checkbox" name="is_proxy"' . ($is_proxy ? 'checked' : '') . '> I am entering a signature for someone else</p>';
    $content .= '<p><label>Signature Collector: ';
    global $wpdb;
    $table_name = $wpdb->prefix . 'lp_signer';
    $query = prepare_query("SELECT id, name FROM {$table_name} WHERE `campaign_id` = %s AND `is_helper` = 1", $campaign_id);
    $results = $wpdb->get_results($query);
    //$content .= '<pre>'.var_export($results, true).'</pre>';
    $content .= '<select name="proxy_id" required="true">';
    foreach ($results as $helper) {
        $content .= '<option value="' . $helper->id . '"';
        if ($helper->id == $proxy_id) $content .= ' selected';
        $content .= '>' . $helper->name . '</option>';
    }
    $content .= '</select>';
    $content .= '</p>';
    $content .= '<p>Date Collected: <input type="text" name="proxy_date" required="true" placeholder="YYYY-MM-DD" value="' . $proxy_date . '">';
    $content .= '</div>';
    if (array_key_exists('city', $_POST)) {
        $_SESSION['city'] = $_POST['city'];
        $_SESSION['zip'] = $_POST['zip'];
    }
    return $content;
}
