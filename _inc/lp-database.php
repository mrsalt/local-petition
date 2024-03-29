<?php
global $lp_db_version;
$lp_db_version = '1.47';

function lp_db_install()
{
	global $wpdb;
	global $lp_db_version;

	$installed_ver = get_option("lp_db_version");

	if ($installed_ver == $lp_db_version) return;

	error_log('Notice: installed DB version = ' . $installed_ver . ', code version = ' . $lp_db_version . ', performing update.');

	$charset_collate = $wpdb->get_charset_collate();
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	// See requirements for using wordpress function dbDelta() here:
	// https://codex.wordpress.org/Creating_Tables_with_Plugins

	$campaign_table_name = $wpdb->prefix . 'lp_campaign';
	$sql = "CREATE TABLE $campaign_table_name (
		id mediumint(9) NOT NULL AUTO_INCREMENT,
		name varchar(50) NOT NULL,
		slug varchar(15) NOT NULL,
		privacy_statement text,
		comment_suggestion text,
		title_suggestion text,
		post_sign_message text,
		default_state char(2),
		status ENUM ('Active','Successful','Abandoned') NOT NULL,
		PRIMARY KEY  (id)
	) $charset_collate;";
	dbDelta($sql);

	$address_table_name = $wpdb->prefix . 'lp_address';
	$sql = "CREATE TABLE $address_table_name (
		id mediumint(9) NOT NULL AUTO_INCREMENT,
		normalized_id mediumint(9),
		line_1 varchar(40) NOT NULL,
		line_2 varchar(40),
		city varchar(20) NOT NULL,
		`state` char(2) NOT NULL,
		zip char(5) NOT NULL,
		zip_ext char(4),
		latitude decimal(10,7),
		longitude decimal(10,7),
		neighborhood varchar(40),
		PRIMARY KEY  (id)
	) $charset_collate;";
	dbDelta($sql);

	if (!check_for_constraint('unique_address', 'UNIQUE')) {
		$wpdb->query("ALTER TABLE $address_table_name ADD CONSTRAINT unique_address UNIQUE (line_1(12), line_2(12), city(12))");
	}

	$table_name = $wpdb->prefix . 'lp_signer';
	$sql = "CREATE TABLE $table_name (
		id mediumint(9) NOT NULL AUTO_INCREMENT,
		created timestamp DEFAULT CURRENT_TIMESTAMP,
		campaign_id mediumint(9) NOT NULL,
		name varchar(50) NOT NULL,
		address_id mediumint(9) NOT NULL,
		original_address_id mediumint(9) NOT NULL,
		age ENUM('< 13','13 - 17','18+'),
		title varchar(70),
		comments text,
		photo_file varchar(50),
		photo_file_type varchar(15),
		is_supporter boolean NOT NULL,
		share_granted boolean NOT NULL,
		is_helper boolean NOT NULL,
		email varchar(50),
		email_status ENUM('Unknown','Valid','Full','Invalid','Unsubscribed') NOT NULL DEFAULT 'Unknown',
		phone varchar(20),
		status ENUM ('Unreviewed','Approved','Quarantined') NOT NULL DEFAULT 'Unreviewed',
		approved_id mediumint(9) NULL,
		PRIMARY KEY  (id)
	) $charset_collate;";
	dbDelta($sql);

	if (!check_for_constraint('campaign_fk', 'FOREIGN KEY')) {
		$wpdb->query("ALTER TABLE $address_table_name ADD CONSTRAINT normalized_fk FOREIGN KEY (normalized_id) REFERENCES $address_table_name(id)");
		$wpdb->query("ALTER TABLE $table_name ADD CONSTRAINT campaign_fk FOREIGN KEY (campaign_id) REFERENCES $campaign_table_name(id)");
		$wpdb->query("ALTER TABLE $table_name ADD CONSTRAINT address_fk  FOREIGN KEY (address_id)  REFERENCES $address_table_name(id)");
		$wpdb->query("ALTER TABLE $table_name ADD CONSTRAINT orig_address_fk FOREIGN KEY (original_address_id) REFERENCES $address_table_name(id)");
	}

	$table_name = $wpdb->prefix . 'lp_updates';
	$sql = "CREATE TABLE $table_name (
		update_id mediumint(9) NOT NULL AUTO_INCREMENT,
		created timestamp DEFAULT CURRENT_TIMESTAMP,
		table_name varchar(50) NOT NULL,
		id mediumint(9) NOT NULL,
		field varchar(32) NOT NULL,
		previous text,
		PRIMARY KEY  (update_id)
	) $charset_collate;";
	dbDelta($sql);

	$table_name = $wpdb->prefix . 'lp_contact_request';
	$sql = "CREATE TABLE $table_name (
		id mediumint(9) NOT NULL AUTO_INCREMENT,
		created timestamp DEFAULT CURRENT_TIMESTAMP,
		status ENUM ('Unread','Read','Response Sent','Will Not Respond','Spam') NOT NULL DEFAULT 'Unread',
		name varchar(50) NOT NULL,
		email varchar(50),
		comments text NOT NULL,
		updated_id mediumint(9) NULL,
		PRIMARY KEY  (id)
	) $charset_collate;";
	dbDelta($sql);

	$table_name = $wpdb->prefix . 'lp_proxy_signature';
	$sql = "CREATE TABLE $table_name (
		created timestamp DEFAULT CURRENT_TIMESTAMP,
		campaign_id mediumint(9) NOT NULL,
		collected_by mediumint(9) NOT NULL,
		signer_id mediumint(9) NOT NULL,
		wp_user_id mediumint(9) NOT NULL,
		sign_date date NOT NULL
	) $charset_collate;";
	dbDelta($sql);

	$table_name = $wpdb->prefix . 'lp_route';
	$sql = "CREATE TABLE $table_name (
		id mediumint(9) NOT NULL AUTO_INCREMENT,
		created timestamp DEFAULT CURRENT_TIMESTAMP,
		campaign_id mediumint(9) NOT NULL,
		created_by_wp_user_id mediumint(9) NOT NULL,
		assigned_to_wp_user_id mediumint(9) NULL,
		assigned_date timestamp NULL,
		number_residences smallint(3) NOT NULL,
		neighborhood varchar(50),
		bounds text NOT NULL,
		number_position text NULL,
		east decimal(10,7),
		west decimal(10,7),
		north decimal(10,7),
		south decimal(10,7),
		status ENUM ('Unassigned','Assigned','Complete') NOT NULL DEFAULT 'Unassigned',
		PRIMARY KEY  (id)
	) $charset_collate;";
	dbDelta($sql);

	$table_name = $wpdb->prefix . 'lp_residence_visit';
	$sql = "CREATE TABLE $table_name (
		campaign_id mediumint(9) NOT NULL,
		address_id mediumint(9),
		created timestamp DEFAULT CURRENT_TIMESTAMP,
		created_by_wp_user_id mediumint(9) NOT NULL,
		route_id mediumint(9) NULL,
		status ENUM ('Talked - Signed','Talked - Did Not Sign','Flyer','Skipped') NOT NULL,
		PRIMARY KEY  (campaign_id, address_id)
	) $charset_collate;";
	dbDelta($sql);

	$map_table_name = $wpdb->prefix . 'lp_map';
	$sql = "CREATE TABLE $map_table_name (
		id mediumint(9) NOT NULL AUTO_INCREMENT,
		created timestamp DEFAULT CURRENT_TIMESTAMP,
		campaign_id mediumint(9) NOT NULL,
		name varchar(50) NOT NULL,
		PRIMARY KEY  (id)
	) $charset_collate;";
	dbDelta($sql);

	$table_name = $wpdb->prefix . 'lp_marker';
	$sql = "CREATE TABLE $table_name (
		id mediumint(9) NOT NULL AUTO_INCREMENT,
		created timestamp DEFAULT CURRENT_TIMESTAMP,
		name varchar(50) NOT NULL,
		address_id mediumint(9) NOT NULL,
		map_id mediumint(9) NOT NULL,
		icon ENUM ('Library','Question Mark') NOT NULL DEFAULT 'Library',
		PRIMARY KEY  (id)
	) $charset_collate;";
	dbDelta($sql);
	if (!check_for_constraint('marker_address_fk', 'FOREIGN KEY')) {
		$wpdb->query("ALTER TABLE $table_name ADD CONSTRAINT marker_address_fk  FOREIGN KEY (address_id)  REFERENCES $address_table_name(id)");
		$wpdb->query("ALTER TABLE $table_name ADD CONSTRAINT map_address_fk  FOREIGN KEY (map_id)  REFERENCES $map_table_name(id)");
	}

	error_log('Notice: updating lp_db_version to ' . $installed_ver);
	if (!isset($installed_ver))
		add_option('lp_db_version', $lp_db_version);
	else
		update_option('lp_db_version', $lp_db_version);
}

function check_for_constraint($constraint, $type)
{
	$sql = "SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE
        CONSTRAINT_SCHEMA = DATABASE() AND
        CONSTRAINT_NAME   = '$constraint' AND
        CONSTRAINT_TYPE   = '$type'";
	global $wpdb;
	return $wpdb->query($sql);
}

function lp_db_install_data()
{
	global $wpdb;
}

function prepare_query($query, ...$args)
{
	// wordpress' prepare function does not handle null values the same way the insert function does.
	// insert will insert null values, but prepare() will convert these null values to empty strings,
	// causing the sequence of inserting followed by a query to be non-idempotent.  :(
	// The purpose of this function is to prepare a query that preserves nulls.

	$replaced = [];
	foreach ($args as $arg) {
		if ($arg === null) {
			$replaced[] = 'NULL';
		} else if (gettype($arg) == 'string') {
			$replaced[] = '\'' . addslashes($arg) . '\'';
		} else {
			$replaced[] = $arg;
		}
	}
	$query = vsprintf($query, $replaced);
	$query = str_replace('= NULL', 'IS NULL', $query);
	return $query;
}
