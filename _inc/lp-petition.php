<?php
function lp_register_custom_post_type() {
	register_post_type('petition',
		array(
			'labels'      => array(
				'name'          => __('Petitions', 'textdomain'),
				'singular_name' => __('Petition', 'textdomain'),
			),
			'public'      => true,
			'has_archive' => false
		)
	);
}
?>