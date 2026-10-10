<?php

// reCAPTCHA v3 for the standard WordPress comment form.

function lp_comment_captcha_enabled()
{
    return LP_PRODUCTION && reCAPTCHA_site_key && reCAPTCHA_secret;
}

function lp_comment_captcha_field()
{
    if (!lp_comment_captcha_enabled()) {
        return;
    }
    wp_enqueue_script('recaptcha');
    echo '<input type="hidden" name="g-recaptcha-response" id="lp-comment-recaptcha">';
    echo '<script>(function () {' .
        'var f = document.getElementById("lp-comment-recaptcha").form, done = false;' .
        'f.addEventListener("submit", function (e) {' .
        '  if (done) return;' .
        '  e.preventDefault();' .
        '  var btn = e.submitter;' .
        '  grecaptcha.ready(function () {' .
        '    grecaptcha.execute("' . esc_js(reCAPTCHA_site_key) . '", {action: "comment"}).then(function (t) {' .
        '      document.getElementById("lp-comment-recaptcha").value = t;' .
        '      done = true;' .
        '      if (f.requestSubmit) f.requestSubmit(btn); else f.submit();' .
        '    });' .
        '  });' .
        '});' .
        '})();</script>';
}
add_action('comment_form', 'lp_comment_captcha_field');

function lp_comment_captcha_check($commentdata)
{
    if (!lp_comment_captcha_enabled() || is_user_logged_in()) {
        return $commentdata;
    }
    // Pingbacks/trackbacks have no form; let WordPress's own filters handle those.
    if (!empty($commentdata['comment_type']) && !in_array($commentdata['comment_type'], ['comment', ''], true)) {
        return $commentdata;
    }
    $token = isset($_POST['g-recaptcha-response']) ? wp_unslash($_POST['g-recaptcha-response']) : '';
    if (!lp_recaptcha_token_is_human($token, 'comment', $error_code)) {
        $msg = $error_code === 'unavailable'
            ? 'Human verification is temporarily unavailable.  Please go back and try again.'
            : 'Human verification failed.  Please go back and try again.';
        wp_die(esc_html($msg), 'Comment not submitted', ['response' => 403, 'back_link' => true]);
    }
    return $commentdata;
}
add_filter('preprocess_comment', 'lp_comment_captcha_check', 1);
