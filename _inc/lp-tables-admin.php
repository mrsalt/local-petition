<?php

function display_value($key, $value)
{
    if ($key == 'Share Public' || $key == 'Helper' || $key == 'Supporter') {
        if ($value == '0')
            return 'No';
        if ($value == '1')
            return 'Yes';
    }
    return $value;
}

function internal_value($table, $key, $value)
{
    if ($table == 'signers') {
        if ($key == 'Share Public' || $key == 'Helper' || $key == 'Supporter') {
            if ($value == 'No')
                return '0';
            if ($value == 'Yes')
                return '1';
        }
    }
    return $value;
}

function build_filters($table, $result, $unique_values, &$hidden_columns)
{
    $histogram = [];
    foreach ($result as $index => $values) {
        foreach ($values as $key => $value) {
            if (in_array($key, $unique_values)) continue;
            if (in_array($key, $hidden_columns)) continue;
            $value = display_value($key, $value);
            if (!array_key_exists($key, $histogram))
                $histogram[$key] = [$value => 1];
            else {
                if (!array_key_exists($value, $histogram[$key]))
                    $histogram[$key][$value] = 1;
                else
                    $histogram[$key][$value]++;
            }
        }
    }
    $content = '<table><tr>';
    foreach ($histogram as $key => $options) {
        $content .= '<td>' . $key . '</td>';
    }
    $content .= '<td>Visible Columns</td>';
    $content .= "</tr>\n";
    $content .= '<tr>';
    foreach ($histogram as $key => $options) {
        $content .= '<td><select onchange="update_filter(\'' . $key . '\',this.value);">';
        $content .= '<option>&lt;All&gt;</option>';
        foreach ($options as $value => $count) {
            $wpkey = str_replace(' ', '_', $key);
            $selected = array_key_exists($wpkey, $_GET) && $_GET[$wpkey] === $value;
            $content .= '<option' . ($selected ? ' selected' : '') . ' value="' . $value . '">' . $value . ' (' . $count . ')</option>';
        }
        $content .= '</td>' . "\n";
    }
    $cookie_name = 'lp-hidden-columns-' . $table;
    $content .= '<td><select multiple onchange="update_visible_columns(this, \''.$cookie_name .'\');">';
    $hidden_by_cookie = [];
    
    if (array_key_exists($cookie_name, $_COOKIE)) {
        // This is annoying.  https://wordpress.stackexchange.com/questions/34866/stop-wordpress-automatically-escaping-post-data
        $hidden_by_cookie = json_decode(wp_unslash($_COOKIE[$cookie_name]));
    }
    $count = 0;
    if (count($result) > 0) {
        foreach ($result[0] as $key => $value) {
            if (in_array($key, $hidden_columns)) continue;
            $visible = !in_array($key, $hidden_by_cookie);
            $content .= '<option' . ($visible ? ' selected' : '') . '>' . $key . '</option>';
            $count++;
        }
    }
    $content .= '</select></td>';
    $content .= "</tr>\n";
    $hidden_columns = array_merge($hidden_columns, $hidden_by_cookie);
    return $content;
    //return var_export($histogram, true);
}

function build_where($table, $filters, $values = [])
{
    $where = '';
    $values = array_merge($values, $_GET);
    foreach ($filters as $key => $field) {
        $wpkey = str_replace(' ', '_', $key);
        //echo "<pre>checking for $wpkey</pre>";
        if (array_key_exists($wpkey, $values)) {
            if (strlen($where) > 0) $where .= ' AND ';
            $value = internal_value($table, $key, $values[$wpkey]);
            if (!is_numeric($value)) $value = '\'' . $value . '\'';
            $where .= $field . ' = ' . $value;
        }
    }
    return $where;
}