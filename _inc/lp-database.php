<?php
global $lp_db_version;
$lp_db_version = '0.9';

function lp_db_install()
{
	global $wpdb;
	global $lp_db_version;

	$installed_ver = get_option("lp_db_version");

	if ($installed_ver == $lp_db_version) return;

	$charset_collate = $wpdb->get_charset_collate();
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	// See requirements for using wordpress function dbDelta() here:
	// https://codex.wordpress.org/Creating_Tables_with_Plugins

	$address_table_name = $wpdb->prefix . 'lp_address';
	$sql = "CREATE TABLE $address_table_name (
		id mediumint(9) NOT NULL AUTO_INCREMENT,
		line_1 tinytext NOT NULL,
		line_2 tinytext NOT NULL,
		city tinytext NOT NULL,
		st tinytext NOT NULL,
		zip tinytext NOT NULL,
		PRIMARY KEY  (id)
	) $charset_collate;";
	dbDelta($sql);

	$table_name = $wpdb->prefix . 'lp_signer';
	$sql = "CREATE TABLE $table_name (
		id mediumint(9) NOT NULL AUTO_INCREMENT,
		wordpress_user_id mediumint(9),
		name tinytext NOT NULL,
		address_id mediumint(9) NOT NULL,
		signer_id mediumint(9),
		login_type tinytext NOT NULL,
		login_token tinytext NOT NULL,
		title tinytext,
		comments text,
		share_comments boolean,
		share_picture boolean,
		email tinytext,
		phone tinytext,
		FOREIGN KEY (address_id) REFERENCES $address_table_name(id),
		PRIMARY KEY  (id)
	) $charset_collate;";
	dbDelta($sql);

	add_option('lp_db_version', $lp_db_version);
}

function lp_db_install_data()
{
	global $wpdb;
}

function lp_db_update_check()
{
	global $lp_db_version;
	if (get_site_option('lp_db_version') != $lp_db_version) {
		lp_db_install();
	}
}
