<?php

require_once('lp-form-utils.php');

function lp_contact_form($atts = [], $content = null)
{
    //$attributes = '<div>Attributes:<pre>'.htmlspecialchars(var_export($atts, true)).'</pre></div>';
    //return $attributes . '<div>Content:<pre>'.htmlspecialchars(var_export($content, true)).'</pre></div>';
    $output = '';

    if ($_SERVER['REQUEST_METHOD'] == 'POST' && array_key_exists('email', $_POST) && array_key_exists('submitter_name', $_POST)) {
        $continue_form_render = true;
        $output = lp_attempt_submit_contact_form($continue_form_render);
        if (!$continue_form_render)
            return $output;
    }

    $style = 'label';
    if (is_array($atts) && array_key_exists('style', $atts))
        $style = $atts['style'];

    $output .= lp_render_contact_form($style);
    return $output;
}

function lp_attempt_submit_contact_form(&$continue_form_render)
{
    global $wpdb;

    $continue_form_render = false;
    $content = '';

    // This is annoying.  https://wordpress.stackexchange.com/questions/34866/stop-wordpress-automatically-escaping-post-data
    $_POST = wp_unslash($_POST);

    if (!check_captcha_in_post_body($content, $continue_form_render)) {
        return $content;
    }

    //$content .= '<pre>$_POST = ' . var_export($_POST, true) . "\n".' $_SESSION = ' . var_export($_SESSION, true) . "\n" . '$_FILES = ' . var_export($_FILES, true) . '</pre>';
    if (!array_key_exists('email', $_POST) || strlen(trim($_POST['email'])) == 0) {
        $content .= '<div class="submit-error">An email address is required.</div>';
        $continue_form_render = true;
        return $content;
    }

    if (!array_key_exists('submitter_name', $_POST) || strlen(trim($_POST['submitter_name'])) == 0) {
        $content .= '<div class="submit-error">Name not submitted.  Please re-submit.</div>';
        $continue_form_render = true;
        return $content;
    }

    if (!array_key_exists('comments', $_POST) || strlen(trim($_POST['comments'])) == 0) {
        $content .= '<div class="submit-error">Comments not submitted.  Please re-submit.</div>';
        $continue_form_render = true;
        return $content;
    }

    $table_name = $wpdb->prefix . 'lp_contact_request';

    $values = array(
        'name'         => $_POST['submitter_name'],
        'email'        => $_POST['email'],
        'comments'     => $_POST['comments']
    );

    $wpdb->insert($table_name, $values);


    $content .= '<div id="post-submit">';
    $content .= '<p style="font-size: xx-large">' . $_POST['submitter_name'] . ', thank you for contacting us!  We\'ll send a reply to ' . $_POST['email'] . '</p>';
    $content .= '<p><label>Comments:<div class="comment-preview">' . $values['comments'] . '</div></label></p>';
    $content .= '</div>';

    return $content;
}

function lp_render_contact_form($style)
{
    $content = '';
    if (LP_PRODUCTION)
        wp_enqueue_script('recaptcha');
    else
        $content .= '<p><i>This site is in debug/development mode</i></p>';

    $content .= '<form autocomplete="on" id="local-petition-form" class="petition" action="' . get_permalink() . '" method="post" enctype="multipart/form-data">';
    $content .= '<p>' . get_input('Name', 'submitter_name', required: true, max_chars: 50, style: $style) . '</p>';
    $content .= '<p>' . get_input('Email', 'email', required: true, max_chars: 50, type: 'email', style: $style) . '</p>';
    $content .= '<p>' . get_textarea('Comments', 'comments', required: true, style: $style) . '</p>';
    $content .= add_submit_button_with_captcha('Submit');
    $content .= '</form>';
    return $content;
}
