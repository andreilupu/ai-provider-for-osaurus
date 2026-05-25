<?php
/**
 * Uninstall handler for AI Provider for Osaurus.
 *
 * Runs when the user deletes the plugin via the wp-admin Plugins screen.
 * WordPress sets the `WP_UNINSTALL_PLUGIN` constant and includes this file in
 * a clean PHP context — no plugin code is loaded, so no constants or
 * autoloaded classes from the plugin itself are available here.
 *
 * Removes both options the plugin writes so the database returns to the
 * state it was in before the plugin was installed.
 *
 * @package OsaurusAi\Connector
 * @since   0.4.2
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Option names duplicated from `ai-provider-for-osaurus.php` because this
// file runs without the plugin's autoloader. Keep these in sync with
// `BASE_URL_OPTION` and `DEFAULT_MODEL_OPTION`.
delete_option( 'osaurus_ai_connector_base_url' );
delete_option( 'osaurus_ai_connector_default_model' );

// Same option deletion on multisite, scoped to every site individually.
// `number => 0` disables the default 100-site cap so large networks are
// covered in full instead of leaking options on the 101st site and beyond.
if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		delete_option( 'osaurus_ai_connector_base_url' );
		delete_option( 'osaurus_ai_connector_default_model' );
		restore_current_blog();
	}
}
