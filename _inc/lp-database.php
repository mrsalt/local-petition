<?php
global $lp_db_version;
$lp_db_version = '0.84';

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

	$campaign_table_name = $wpdb->prefix . 'lp_campaign';
	$sql = "CREATE TABLE $campaign_table_name (
		id mediumint(9) NOT NULL AUTO_INCREMENT,
		name tinytext NOT NULL,
		slug tinytext NOT NULL,
		status ENUM ('Active','Successful','Abandoned') NOT NULL,
		PRIMARY KEY  (id)
	) $charset_collate;";
	dbDelta($sql);

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
		campaign_id mediumint(9),
		name tinytext NOT NULL,
		address_id mediumint(9) NOT NULL,
		title tinytext,
		comments text,
		photo_file tinytext,
		is_supporter boolean NOT NULL,
		consent_granted_to_share boolean NOT NULL,
		is_helper boolean NOT NULL,
		email tinytext,
		phone tinytext,
		FOREIGN KEY (campaign_id) REFERENCES $campaign_table_name(id),
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
