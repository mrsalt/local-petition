<?php

function lp_handle_init()
{
    if (!session_id()) {
        session_start();
    }
}

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
