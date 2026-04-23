<?php
/**
 * MU: dev helpers for Osaurus AI Connector inside wp-env.
 *
 * Loaded automatically by WordPress from wp-content/mu-plugins.
 *
 * @package OsaurusAIConnector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( ! is_string( $url ) ) {
			return $pre;
		}
		if ( false !== stripos( $url, 'host.docker.internal' ) ) {
			$args['reject_unsafe_urls'] = false;
		}
		return $pre;
	},
	10,
	3
);
