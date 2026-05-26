<?php
/**
 * AI Provider for Osaurus Uninstall.
 *
 * Uninstalling AI Provider for Osaurus deletes the plugin's options on the
 * primary blog and, on multisite, on every site of the network.
 *
 * @package OsaurusAi\Connector
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'osaurus_ai_connector_base_url' );
delete_option( 'osaurus_ai_connector_default_model' );

// On multisite, repeat the deletion against every site individually.
// `number => 0` lifts `get_sites()`'s default 100-site cap so large
// networks are cleaned in full instead of leaking options on site 101+.
if ( is_multisite() ) {
	$osaurus_ai_connector_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $osaurus_ai_connector_site_ids as $osaurus_ai_connector_site_id ) {
		switch_to_blog( (int) $osaurus_ai_connector_site_id );
		delete_option( 'osaurus_ai_connector_base_url' );
		delete_option( 'osaurus_ai_connector_default_model' );
		restore_current_blog();
	}
}
