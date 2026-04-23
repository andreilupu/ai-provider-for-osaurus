<?php
/**
 * Plugin Name:       Osaurus AI Connector
 * Plugin URI:        https://github.com/andreilupu/osaurus-ai-connector
 * Description:       Registers Osaurus (local Apple Silicon LLM runtime) as a provider for the WordPress AI Client.
 * Requires at least: 7.0
 * Requires PHP:      7.4
 * Version:           0.1.0
 * Author:            Andrei Lupu
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
const PLUGIN_VERSION = '0.1.0';

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
