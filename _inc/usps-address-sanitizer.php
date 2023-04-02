<?php

function xml_escape($text)
{
    return htmlentities($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1);
}

function simple_xml_parse($xml, $element, $required = true)
{
    $start_tag = '<' . $element . '>';
    $start_pos = strpos($xml, $start_tag);
    if ($start_pos == false) {
        if (!$required)
            return null;
        throw new Exception("Unable to find $start_tag in $xml");
    }
    $end_tag = '</' . $element . '>';
    $end_pos = strpos($xml, $end_tag, $start_pos);
    if ($end_pos == false)
        throw new Exception("Unable to find $end_tag in $xml");
    $start_pos += strlen($start_tag);
    $value = substr($xml, $start_pos, $end_pos - $start_pos);
    return html_entity_decode($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1);
}

function sanitize_address($address)
{
    $user_id = get_cfg_var('usps_api_user_id');
    if (!$user_id)
        throw new Exception('No usps_api_user_id set in php.ini');

    // USPS does not document their API very well, but from results returned, Address2
    // is the 'primary' line of the address.  Apartment Number, etc. can be in Address1
    // but technically should be at the end of Address2.  Address1 is intended for C/O
    // (care of).
    // This is why I'm swapping line1/line2 here.
    $body = '<AddressValidateRequest USERID="' . $user_id . '">' .
        '<Address ID="0">' .
        '<Address1>' . xml_escape($address['line_2']) . '</Address1>' .
        '<Address2>' . xml_escape($address['line_1']) . '</Address2>' .
        '<City>' . xml_escape($address['city']) . '</City>' .
        '<State>' . xml_escape($address['state']) . '</State>' .
        '<Zip5>' . xml_escape($address['zip']) . '</Zip5>' .
        '<Zip4></Zip4>' .
        '</Address>' .
        '</AddressValidateRequest>';

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_URL => 'https://production.shippingapis.com/ShippingAPI.dll',
        CURLOPT_POST => 1,
        CURLOPT_POSTFIELDS => http_build_query(array('API' => 'Verify', 'XML' => $body)),
        CURLOPT_RETURNTRANSFER => true
    ]);
    $result = curl_exec($ch);
    if ($result === false) {
        throw new Exception('curl_exec() returned false: curl_error=' . curl_error($ch) . ', curl_get_info=' . var_export(curl_getinfo($ch), true));
    }
    curl_close($ch);

    if (strpos($result, '<Error>')) {
        return array('Error' => simple_xml_parse($result, 'Description'));
        //return '<pre>'.var_export(Array('Error' => simple_xml_parse($result, 'Description')), true).'</pre>';
    }

    //<AddressValidateResponse><Address ID="0"><Error><Number>-2147219401</Number><Source>clsAMS</Source><Description>Address Not Found.  </Description><HelpFile/><HelpContext/></Error></Address></AddressValidateResponse>
    //<AddressValidateResponse><Address ID="0"><Address1>APT G406</Address1><Address2>3400 E RIVER VALLEY ST</Address2><City>MERIDIAN</City><State>ID</State><Zip5>83646</Zip5><Zip4>2312</Zip4></Address></AddressValidateResponse>
    //return '<pre>'.htmlentities($result).'</pre>'.'<pre>Address2 = '.simple_xml_parse($result, 'Address2').'</pre>';
    return array(
        'line_1' => simple_xml_parse($result, 'Address2'),
        'line_2' => simple_xml_parse($result, 'Address1', false),
        'city' => simple_xml_parse($result, 'City'),
        'state' => simple_xml_parse($result, 'State'),
        'zip' => simple_xml_parse($result, 'Zip5'),
        'zip_ext' => simple_xml_parse($result, 'Zip4')
    );
}

function get_address_id($address)
{
    global $wpdb;
    $address_table_name = $wpdb->prefix . 'lp_address';
    $query = prepare_query("SELECT id FROM {$address_table_name} WHERE line_1 = %s AND line_2 = %s AND zip = %s", $address['line_1'], $address['line_2'], $address['zip']);
    $results = $wpdb->get_results($query);
    if (count($results) == 0) {
        return null;
    }
    return $results[0]->id;
}

function store_address($address, $normalized_id = null)
{
    $id = get_address_id($address);
    if ($id != null) return $id;

    $values = array(
        'line_1' => $address['line_1'],
        'line_2' => $address['line_2'],
        'city'   => $address['city'],
        'state'  => $address['state'],
        'zip'    => $address['zip'],
        'zip_ext' => $address['zip_ext'] ?? null,
        'normalized_id' => $normalized_id
    );

    global $wpdb;
    $address_table_name = $wpdb->prefix . 'lp_address';
    $wpdb->insert(
        $address_table_name,
        $values
    );

    return get_address_id($address);
}
