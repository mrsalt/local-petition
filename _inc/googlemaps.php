<?php

function geocode($address)
{
    $api_key = get_cfg_var('google_maps_api_key');
    if (!$api_key)
        throw new Exception('No google_maps_api_key set in php.ini');

    $address_string = $address['line_1'];
    //if ($address['line_2'])
    //    $address_string .= ', '.$address['line_2'];
    $address_string .= ', ' . $address['city'];
    $address_string .= ', ' . $address['state'];
    $encoded_address = urlencode($address_string);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_URL => 'https://maps.googleapis.com/maps/api/geocode/json?address=' . $encoded_address . '&key=' . $api_key,
    ]);
    $result = curl_exec($ch);
    if ($result === false) {
        throw new Exception('curl_exec() returned false: curl_error=' . curl_error($ch) . ', curl_get_info=' . var_export(curl_getinfo($ch), true));
    }
    if (curl_getinfo($ch, CURLINFO_RESPONSE_CODE) != 200) {
        error_log('Geocode request failed.  curl_get_info=' . var_export(curl_getinfo($ch), true) . ', result = ' . var_export($result, true));
        curl_close($ch);
        return false;
    }
    curl_close($ch);

    error_log($result);
    $json = json_decode($result);
    if ($json->status != 'OK') {
        error_log('Geocode request failed.  $json =' . $result);
        error_log('Geocode request failed.  var_export($json) =' . var_export($json, true));
        return false;
    }
    $location = $json->results[0]->geometry->location;
    $ret = array(
        'latitude' => $location->lat,
        'longitude' => $location->lng
    );
    $address_components = $json->results[0]->address_components;
    foreach ($address_components as $component) {
        if (in_array('neighborhood', $component->types)) {
            $ret['neighborhood'] = $component->long_name;
        }
    }
    return $ret;
}

function lp_get_map_id()
{
    global $map_counter;
    if (!$map_counter) $map_counter = 1;
    else $map_counter++;
    return 'map-' . $map_counter;
}

function lp_create_map_element($id, $class_name, $load_marker_clusterer, $locality = null, $lat = null, $lng = null, $zoom = 'null', $mapId = 'null', $additional_script = '')
{
    $content = '<div id="' . $id . '" class="' . $class_name . '"></div>';
    global $map_api_loaded;
    if (!$map_api_loaded) {
        $api_key = get_cfg_var('google_maps_api_key');
        if (!$api_key)
            throw new Exception('No google_maps_api_key set in php.ini');
        $map_api_loaded = true;
        wp_enqueue_script('local_petition_maps');
        // https://developers.google.com/maps/documentation/javascript/adding-a-google-map#maps_add_map-javascript
        $content .= '<script>(g=>{var h,a,k,p="The Google Maps JavaScript API",c="google",l="importLibrary",q="__ib__",m=document,b=window;b=b[c]||(b[c]={});var d=b.maps||(b.maps={}),r=new Set,e=new URLSearchParams,u=()=>h||(h=new Promise(async(f,n)=>{await (a=m.createElement("script"));e.set("libraries",[...r]+"");for(k in g)e.set(k.replace(/[A-Z]/g,t=>"_"+t[0].toLowerCase()),g[k]);e.set("callback",c+".maps."+q);a.src=`https://maps.${c}apis.com/maps/api/js?`+e;d[q]=f;a.onerror=()=>h=n(Error(p+" could not load."));a.nonce=m.querySelector("script[nonce]")?.nonce||"";m.head.append(a)}));d[l]?console.warn(p+" only loads once. Ignoring:",g):d[l]=(f,...n)=>r.add(f)&&u().then(()=>d[l](f,...n))})
        ({key: "' . $api_key . '", v: "beta"});</script>';
    }
    if ($load_marker_clusterer)
        wp_enqueue_script('markerclusterer');
    $map_properties = '';
    if ($lat) $map_properties .= '"lat": ' . $lat;
    if ($lng) {
        if ($map_properties) $map_properties .= ', ';
        $map_properties .= '"lng": ' . $lng;
    }
    if ($locality) {
        $locality = "'" . $locality . "'";
    }
    $content .= '<script>initMap(document.getElementById(\'' . $id . '\'), {' . $map_properties . '}, ' . $zoom . ', \'' . $mapId . '\', ' . $locality . ')' .
        $additional_script .
        '</script>' . "\n";
    return $content;
}
