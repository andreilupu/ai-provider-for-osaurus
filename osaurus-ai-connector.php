<?php
/**
 * Plugin Name:       Osaurus AI Connector
 * Plugin URI:        https://github.com/andreilupu/osaurus-ai-connector
 * Description:       Registers Osaurus (local Apple Silicon LLM runtime) as a provider for the WordPress AI Client.
 * Requires at least: 7.0
 * Tested up to:      7.0
 * Requires PHP:      7.4
 * Version:           0.4.0
 * Author:            Andrei Lupu
 * Author URI:        https://github.com/andreilupu
 * License:           GPL-2.0-or-later
 * License URI:       https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain:       osaurus-ai-connector
 *
 * This file is the plugin entry point. It wires the Osaurus provider into the
 * WordPress AI Client on `init` and adds the HTTP plumbing WordPress needs in
 * order to reach a local service.
 *
 * @package OsaurusAi\Connector
 * @since   0.1.0
 */

declare(strict_types=1);

namespace OsaurusAi\Connector;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use OsaurusAi\Connector\Provider\OsaurusProvider;

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * Absolute path to the main plugin file.
 *
 * @since 0.1.0
 * @var string
 */
const PLUGIN_FILE = __FILE__;

/**
 * Plugin version string. Keep in sync with the `Version` header above.
 *
 * @since 0.1.0
 * @var string
 */
const PLUGIN_VERSION = '0.4.0';

/**
 * Default Osaurus base URL, used when no constant or option is provided.
 *
 * Uses `host.docker.internal` so that a WordPress instance running inside
 * `@wordpress/env` (Docker) can reach an Osaurus server on the macOS host.
 * Bare-metal installs should override via the `OSAURUS_BASE_URL` constant or
 * by setting the `osaurus_ai_connector_base_url` option to `http://127.0.0.1:1337/v1`.
 *
 * @since 0.1.0
 * @var string
 */
const DEFAULT_BASE_URL = 'http://host.docker.internal:1337/v1';

/**
 * Option name used to persist a custom base URL from the admin UI.
 *
 * @since 0.1.0
 * @var string
 */
const BASE_URL_OPTION = 'osaurus_ai_connector_base_url';

/**
 * Option name used to persist a user-selected default model.
 *
 * Consumer plugins may read this option to fall back to the user's preferred
 * Osaurus model when the caller has not specified one explicitly.
 *
 * @since 0.4.0
 * @var string
 */
const DEFAULT_MODEL_OPTION = 'osaurus_ai_connector_default_model';

require_once __DIR__ . '/src/autoload.php';

/**
 * Resolves the Osaurus base URL to use for all API requests.
 *
 * Resolution order (first match wins):
 *   1. `OSAURUS_BASE_URL` PHP constant — for wp-env / CI / server configs.
 *   2. `osaurus_ai_connector_base_url` option — for the future settings screen.
 *   3. {@see DEFAULT_BASE_URL} — Docker-aware default.
 *
 * The returned URL has any trailing slash removed so path concatenation in
 * {@see Provider\OsaurusProvider::url()} does not produce a double slash.
 *
 * @since 0.1.0
 *
 * @return string The resolved base URL, without a trailing slash.
 */
function get_base_url(): string {
	// 1. Constant — highest priority so deploys can lock the value.
	if ( defined( 'OSAURUS_BASE_URL' ) && is_string( \OSAURUS_BASE_URL ) && '' !== \OSAURUS_BASE_URL ) {
		return rtrim( \OSAURUS_BASE_URL, '/' );
	}

	// 2. Database option — user-configurable via admin settings.
	$stored = get_option( BASE_URL_OPTION, '' );
	if ( is_string( $stored ) && '' !== $stored ) {
		return rtrim( $stored, '/' );
	}

	// 3. Default — assumes wp-env + Osaurus running on the host.
	return DEFAULT_BASE_URL;
}

/**
 * Registers the Osaurus provider with the WordPress AI Client.
 *
 * Hooked to `init` at priority 5 so the provider is available before any
 * plugin that consumes the AI Client on the default priority 10.
 *
 * The registration is idempotent: if the provider is already registered
 * (e.g. another plugin re-registered it), this is a no-op.
 *
 * @since 0.1.0
 *
 * @return void
 */
function register_provider(): void {
	// AI Client ships with WordPress core ≥ 7.0. Guard against older cores
	// so activation on an unsupported install produces no fatal error.
	if ( ! class_exists( AiClient::class ) ) {
		return;
	}

	$registry = AiClient::defaultRegistry();
	if ( $registry->hasProvider( OsaurusProvider::class ) ) {
		return;
	}

	$registry->registerProvider( OsaurusProvider::class );
}
add_action( 'init', __NAMESPACE__ . '\\register_provider', 5 );

/**
 * Registers a placeholder API-key authentication for Osaurus.
 *
 * Osaurus runs locally and accepts any credential, but the AI Client reports
 * a provider as "configured" only when a {@see ApiKeyRequestAuthentication}
 * is present in the registry. We register an empty one as a fallback so the
 * Connectors admin UI renders the row as connected and downstream features
 * (image generation gating, prompt routing) behave correctly.
 *
 * Hooked to `init` at priority 15 so it runs AFTER:
 *   - `register_provider()` (priority 5 in this plugin).
 *   - `_wp_connectors_pass_default_keys_to_ai_client()` (priority 20 in core).
 *
 * The priority 15 placement is the sweet spot: after our provider exists,
 * but before the core pass step would have overwritten any user-provided
 * key from the database.
 *
 * @since 0.1.0
 *
 * @return void
 */
function register_fallback_auth(): void {
	if ( ! class_exists( AiClient::class ) ) {
		return;
	}

	$registry = AiClient::defaultRegistry();
	if ( ! $registry->hasProvider( 'osaurus' ) ) {
		return;
	}

	// If anything else already set an authentication (e.g. a user-entered
	// key from Settings > Connectors), do not clobber it with an empty one.
	if ( null !== $registry->getProviderRequestAuthentication( 'osaurus' ) ) {
		return;
	}

	$registry->setProviderRequestAuthentication(
		'osaurus',
		new ApiKeyRequestAuthentication( '' )
	);
}
add_action( 'init', __NAMESPACE__ . '\\register_fallback_auth', 15 );

/**
 * Allows the WordPress HTTP API to reach the configured Osaurus host.
 *
 * By default, `wp_safe_remote_*()` calls — which the AI Client HTTP
 * transporter uses — reject requests to hosts WordPress considers external,
 * which includes `127.0.0.1`, `localhost`, and other private addresses.
 *
 * We whitelist only the host configured for Osaurus so we do not broaden
 * WordPress's safe-request policy beyond what this plugin needs.
 *
 * @since 0.1.0
 *
 * @param bool   $external Whether WordPress currently considers the host external.
 * @param string $host     The parsed host of the outgoing request.
 * @param string $url      The full outgoing request URL.
 * @return bool True to force the request through, otherwise the unchanged `$external` flag.
 */
function allow_localhost_requests( bool $external, string $host, string $url ): bool {
	$base_host = wp_parse_url( get_base_url(), PHP_URL_HOST );
	if ( $base_host && wp_parse_url( $url, PHP_URL_HOST ) === $base_host ) {
		return true;
	}

	return $external;
}
add_filter( 'http_request_host_is_external', __NAMESPACE__ . '\\allow_localhost_requests', 10, 3 );

/**
 * Adds the configured Osaurus port to WordPress's safe-ports allow-list.
 *
 * WordPress limits `wp_safe_remote_*()` to a small set of standard ports
 * (80, 443, 8080). Osaurus defaults to 1337, so without this filter every
 * request would be blocked with an `http_request_failed` error.
 *
 * @since 0.1.0
 *
 * @param array<int, int> $ports The current list of allowed ports.
 * @return array<int, int> The list with the Osaurus port appended (de-duplicated).
 */
function allow_osaurus_port( array $ports ): array {
	$port = wp_parse_url( get_base_url(), PHP_URL_PORT );
	if ( ! $port ) {
		return $ports;
	}

	$ports[] = (int) $port;

	return array_values( array_unique( $ports ) );
}
add_filter( 'http_allowed_safe_ports', __NAMESPACE__ . '\\allow_osaurus_port' );

/**
 * Registers the Osaurus base-URL option so it can be read and written via
 * the WordPress Settings REST API (`/wp/v2/settings`).
 *
 * Our custom admin React component (see `assets/js/connector-settings.js`)
 * uses `useEntityRecord( 'root', 'site' )` from `@wordpress/core-data` to
 * read and persist this option. That flow only works when the option is
 * registered against the `connectors` settings group with `show_in_rest`
 * enabled and a sensible sanitize callback.
 *
 * @since 0.3.0
 *
 * @return void
 */
function register_base_url_setting(): void {
	register_setting(
		'connectors',
		BASE_URL_OPTION,
		array(
			'type'              => 'string',
			'label'             => __( 'Osaurus Server URL', 'osaurus-ai-connector' ),
			'description'       => __( 'Base URL of your local Osaurus server, including the /v1 path.', 'osaurus-ai-connector' ),
			'default'           => DEFAULT_BASE_URL,
			'show_in_rest'      => true,
			'sanitize_callback' => 'esc_url_raw',
		)
	);

	register_setting(
		'connectors',
		DEFAULT_MODEL_OPTION,
		array(
			'type'              => 'string',
			'label'             => __( 'Osaurus default model', 'osaurus-ai-connector' ),
			'description'       => __( 'Model ID to use when callers do not specify one explicitly.', 'osaurus-ai-connector' ),
			'default'           => '',
			'show_in_rest'      => true,
			'sanitize_callback' => 'sanitize_text_field',
		)
	);
}
add_action( 'init', __NAMESPACE__ . '\\register_base_url_setting' );

/**
 * Registers a REST route that proxies `GET /v1/models` from the configured
 * Osaurus server.
 *
 * The Connectors admin screen runs in the browser and cannot fetch the
 * Osaurus models endpoint directly: Osaurus is typically bound to
 * `127.0.0.1` (cross-origin to the WordPress origin) and the local server
 * does not emit CORS headers. Routing the request through WordPress side-
 * steps both problems and lets us reuse the plugin's `http_*` filters that
 * already whitelist the host / port for `wp_safe_remote_get()`.
 *
 * Capability: requires `manage_options` so only admins can probe the
 * configured URL — the response leaks the list of locally installed models.
 *
 * @since 0.4.0
 *
 * @return void
 */
function register_rest_routes(): void {
	$base_url_arg = array(
		'base_url' => array(
			'type'              => 'string',
			'required'          => false,
			'sanitize_callback' => 'esc_url_raw',
			'validate_callback' => static function ( $value ): bool {
				if ( ! is_string( $value ) || '' === $value ) {
					return false;
				}
				$scheme = wp_parse_url( $value, PHP_URL_SCHEME );
				return in_array( $scheme, array( 'http', 'https' ), true );
			},
		),
	);

	$admin_only = static function (): bool {
		return current_user_can( 'manage_options' );
	};

	register_rest_route(
		'osaurus-ai-connector/v1',
		'/models',
		array(
			'methods'             => 'GET',
			'permission_callback' => $admin_only,
			'args'                => $base_url_arg,
			'callback'            => __NAMESPACE__ . '\\rest_get_models',
		)
	);

	register_rest_route(
		'osaurus-ai-connector/v1',
		'/health',
		array(
			'methods'             => 'GET',
			'permission_callback' => $admin_only,
			'args'                => $base_url_arg,
			'callback'            => __NAMESPACE__ . '\\rest_get_health',
		)
	);
}
add_action( 'rest_api_init', __NAMESPACE__ . '\\register_rest_routes' );

/**
 * REST callback: returns the model IDs advertised by the configured Osaurus
 * server, or a WP_Error on failure.
 *
 * Uses `wp_safe_remote_get()` (not `wp_remote_get()`) so the host / port
 * whitelisting done by `allow_localhost_requests()` and `allow_osaurus_port()`
 * applies. A short timeout keeps the admin screen responsive when Osaurus
 * is offline.
 *
 * @since 0.4.0
 *
 * @param \WP_REST_Request $request Incoming REST request; may carry a `base_url`
 *                                  query arg so the admin UI can probe an
 *                                  unsaved URL before persisting it.
 * @return \WP_REST_Response|\WP_Error
 */
/**
 * Performs an admin-side GET against the configured (or overridden) Osaurus
 * server and returns the decoded JSON body.
 *
 * Centralises the host / port whitelisting that `wp_safe_remote_get()` needs
 * to reach a loopback service on a non-standard port. Both REST callbacks in
 * this file delegate the network plumbing here so behaviour stays in sync.
 *
 * @since 0.4.0
 *
 * @param string $base_url Sanitized base URL of the Osaurus server. Trailing
 *                         slashes are tolerated and stripped.
 * @param string $url      Full URL to fetch (e.g. `<base>/models`, `<root>/health`).
 * @return array<string, mixed>|\WP_Error Decoded JSON body, or a WP_Error
 *                                        with HTTP status 502 on failure.
 */
function probe_osaurus( string $base_url, string $url ) {
	$probe_host = wp_parse_url( $base_url, PHP_URL_HOST );
	$probe_port = wp_parse_url( $base_url, PHP_URL_PORT );

	$allow_host = static function ( bool $external, string $host ) use ( $probe_host ): bool {
		return ( $probe_host && $host === $probe_host ) ? true : $external;
	};
	$allow_port = static function ( array $ports ) use ( $probe_port ): array {
		if ( $probe_port ) {
			$ports[] = (int) $probe_port;
		}
		return array_values( array_unique( $ports ) );
	};
	add_filter( 'http_request_host_is_external', $allow_host, 10, 2 );
	add_filter( 'http_allowed_safe_ports', $allow_port );

	$response = wp_safe_remote_get(
		$url,
		array(
			'timeout' => 5,
			'headers' => array( 'Accept' => 'application/json' ),
		)
	);

	remove_filter( 'http_request_host_is_external', $allow_host, 10 );
	remove_filter( 'http_allowed_safe_ports', $allow_port );

	if ( is_wp_error( $response ) ) {
		return new \WP_Error(
			'osaurus_unreachable',
			$response->get_error_message(),
			array( 'status' => 502 )
		);
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 300 ) {
		return new \WP_Error(
			'osaurus_bad_response',
			/* translators: %d: HTTP status code returned by Osaurus. */
			sprintf( __( 'Osaurus responded with HTTP %d.', 'osaurus-ai-connector' ), $code ),
			array( 'status' => 502 )
		);
	}

	$body    = wp_remote_retrieve_body( $response );
	$decoded = json_decode( $body, true );
	if ( ! is_array( $decoded ) ) {
		return new \WP_Error(
			'osaurus_malformed_response',
			__( 'Osaurus returned a non-JSON or unexpected response.', 'osaurus-ai-connector' ),
			array( 'status' => 502 )
		);
	}

	return $decoded;
}

/**
 * Resolves the base URL to probe, preferring an admin-supplied override over
 * the persisted option so the Settings panel can probe an unsaved URL.
 *
 * @since 0.4.0
 *
 * @param \WP_REST_Request $request Incoming REST request.
 * @return string Base URL with any trailing slash trimmed.
 */
function resolve_probe_base_url( \WP_REST_Request $request ): string {
	$override = $request->get_param( 'base_url' );
	return is_string( $override ) && '' !== $override
		? rtrim( $override, '/' )
		: get_base_url();
}

function rest_get_models( \WP_REST_Request $request ) {
	$base    = resolve_probe_base_url( $request );
	$decoded = probe_osaurus( $base, trailingslashit( $base ) . 'models' );

	if ( is_wp_error( $decoded ) ) {
		return $decoded;
	}

	if ( ! isset( $decoded['data'] ) || ! is_array( $decoded['data'] ) ) {
		return new \WP_Error(
			'osaurus_malformed_response',
			__( 'Osaurus returned a response without the expected `data` array.', 'osaurus-ai-connector' ),
			array( 'status' => 502 )
		);
	}

	$ids = array();
	foreach ( $decoded['data'] as $model ) {
		if ( isset( $model['id'] ) && is_string( $model['id'] ) && '' !== $model['id'] ) {
			$ids[] = $model['id'];
		}
	}
	sort( $ids );

	return rest_ensure_response( array( 'models' => $ids ) );
}

/**
 * REST callback: probes Osaurus's `/health` endpoint and returns a normalised
 * status payload.
 *
 * Health lives at the server **root**, not under the configured `/v1` path,
 * so we rebuild the URL from the base URL's scheme + host + port and discard
 * the path component. Returns the upstream `status`, the currently loaded
 * model (when Osaurus reports one), and the list of resident models so the
 * admin UI can display a meaningful status row instead of a binary OK/fail.
 *
 * @since 0.4.0
 *
 * @param \WP_REST_Request $request Incoming REST request.
 * @return \WP_REST_Response|\WP_Error
 */
function rest_get_health( \WP_REST_Request $request ) {
	$base = resolve_probe_base_url( $request );

	// Health is at server root: drop any configured path (`/v1`) and rebuild.
	$scheme = wp_parse_url( $base, PHP_URL_SCHEME );
	$host   = wp_parse_url( $base, PHP_URL_HOST );
	$port   = wp_parse_url( $base, PHP_URL_PORT );
	if ( ! $scheme || ! $host ) {
		return new \WP_Error(
			'osaurus_invalid_base_url',
			__( 'The configured base URL is missing a scheme or host.', 'osaurus-ai-connector' ),
			array( 'status' => 400 )
		);
	}
	$root        = $scheme . '://' . $host . ( $port ? ':' . (int) $port : '' );
	$health_url  = $root . '/health';
	$decoded     = probe_osaurus( $base, $health_url );

	if ( is_wp_error( $decoded ) ) {
		return $decoded;
	}

	$loaded = array();
	if ( isset( $decoded['loaded'] ) && is_array( $decoded['loaded'] ) ) {
		foreach ( $decoded['loaded'] as $item ) {
			if ( is_string( $item ) && '' !== $item ) {
				$loaded[] = $item;
			}
		}
	}

	return rest_ensure_response(
		array(
			'status'        => isset( $decoded['status'] ) && is_string( $decoded['status'] ) ? $decoded['status'] : 'unknown',
			'current_model' => isset( $decoded['current_model'] ) && is_string( $decoded['current_model'] ) ? $decoded['current_model'] : null,
			'loaded'        => $loaded,
		)
	);
}

/**
 * Enqueues the plugin's JavaScript module on the Connectors admin screen.
 *
 * The module registers a custom render for the Osaurus connector via
 * `__experimentalRegisterConnector`, replacing the legacy API-key input
 * with a URL input. All reads and writes go through the Settings REST API
 * using `@wordpress/core-data`.
 *
 * Enqueue triggers on both the wp-admin integrated screen
 * (`settings_page_options-connectors-wp-admin`) and the full-page variant
 * (`options-connectors`).
 *
 * @since 0.3.0
 *
 * @param string $hook_suffix Current admin screen hook suffix.
 * @return void
 */
function enqueue_connector_settings_module( string $hook_suffix ): void {
	$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	$screen_id = $screen ? $screen->id : '';

	$is_connectors_screen = (
		in_array( $hook_suffix, array( 'settings_page_options-connectors-wp-admin', 'settings_page_options-connectors' ), true ) ||
		in_array( $screen_id, array( 'options-connectors', 'settings_page_options-connectors-wp-admin' ), true )
	);

	if ( ! $is_connectors_screen ) {
		return;
	}

	$handle = 'osaurus-ai-connector-settings';

	wp_register_script_module(
		$handle,
		plugins_url( 'assets/js/connector-settings.js', PLUGIN_FILE ),
		array(
			// `@wordpress/connectors` is the only WP package exposed as a script
			// module in WP 7.0 and is the sole ES import used by this file.
			// All other `@wordpress/*` packages are consumed via the classic
			// `window.wp.*` globals that the Connectors page already enqueues.
			array(
				'import' => 'static',
				'id'     => '@wordpress/connectors',
			),
		),
		PLUGIN_VERSION
	);

	// Ensure the classic `window.wp.*` globals our module reads are printed
	// for this request. The Connectors page boot already enqueues them, but
	// declaring a classic-script dependency chain guarantees availability
	// even if the page layout changes in the future.
	wp_enqueue_script( 'wp-core-data' );
	wp_enqueue_script( 'wp-components' );
	wp_enqueue_script( 'wp-element' );
	wp_enqueue_script( 'wp-i18n' );
	wp_enqueue_script( 'wp-api-fetch' );
	wp_enqueue_script( 'wp-url' );

	wp_enqueue_script_module( $handle );
}
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_connector_settings_module' );
