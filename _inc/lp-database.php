<?php
global $lp_db_version;
$lp_db_version = '1.07';

function lp_db_install()
{
	global $wpdb;
	global $lp_db_version;

	$installed_ver = get_option("lp_db_version");

	if ($installed_ver == $lp_db_version) return;

	error_log('Notice: installed DB version = '.$installed_ver.', code version = '.$lp_db_version.', performing update.');

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
		line_2 tinytext,
		city tinytext NOT NULL,
		`state` tinytext NOT NULL,
		zip char(5) NOT NULL,
		zip_ext char(4),
		PRIMARY KEY  (id),
		UNIQUE KEY unique_address (line_1(12), line_2(12), city(12))
	) $charset_collate;";
	dbDelta($sql);

	$table_name = $wpdb->prefix . 'lp_signer';
	$sql = "CREATE TABLE $table_name (
		id mediumint(9) NOT NULL AUTO_INCREMENT,
		campaign_id mediumint(9) NOT NULL,
		name tinytext NOT NULL,
		address_id mediumint(9) NOT NULL,
		original_address_id mediumint(9) NOT NULL,
		title tinytext,
		comments text,
		photo_file tinytext,
		is_supporter boolean NOT NULL,
		consent_granted_to_share boolean NOT NULL,
		is_helper boolean NOT NULL,
		email tinytext,
		phone tinytext,
		PRIMARY KEY  (id)
	) $charset_collate;";
	dbDelta($sql);

	if (!isset($installed_ver) || version_compare($installed_ver, '1.01', '<')) {
		$wpdb->query("ALTER TABLE $table_name ADD FOREIGN KEY (campaign_id) REFERENCES $campaign_table_name(id)");
		$wpdb->query("ALTER TABLE $table_name ADD FOREIGN KEY (address_id)  REFERENCES $address_table_name(id)");
		$wpdb->query("ALTER TABLE $table_name ADD FOREIGN KEY (original_address_id) REFERENCES $address_table_name(id)");
	}

	if (!isset($installed_ver))
		add_option('lp_db_version', $lp_db_version);
	else
		update_option('lp_db_version', $lp_db_version);
}

function lp_db_install_data()
{
	global $wpdb;
}
