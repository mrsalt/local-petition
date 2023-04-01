<?php

function lp_render_petition( $atts = [], $content = null) {
    $attributes = '<div>Attributes:<pre>'.htmlspecialchars(var_export($atts, true)).'</pre></div>';
    return $attributes . '<div>Content:<pre>'.htmlspecialchars(var_export($content, true)).'</pre></div>';
}


?>