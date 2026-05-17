<?php
/**
 * Osaurus provider class.
 *
 * @package OsaurusAi\Connector
 * @since   0.1.0
 */

declare(strict_types=1);

namespace OsaurusAi\Connector\Provider;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\ApiBasedImplementation\ListModelsApiBasedProviderAvailability;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use OsaurusAi\Connector\Metadata\OsaurusModelMetadataDirectory;
use OsaurusAi\Connector\Models\OsaurusTextGenerationModel;

use function OsaurusAi\Connector\get_base_url;

/**
 * Provider class for Osaurus.
 *
 * Osaurus is a local Apple Silicon LLM runtime that exposes OpenAI-compatible
 * REST endpoints (`/v1/chat/completions`, `/v1/models`). Because the wire
 * format matches OpenAI's, the text generation model extends the SDK's
 * `AbstractOpenAiCompatibleTextGenerationModel` and receives streaming,
 * tool calling, and structured output support "for free".
 *
 * This class is consumed by the WordPress AI Client via
 * `AiClient::defaultRegistry()->registerProvider(OsaurusProvider::class)`.
 *
 * @since 0.1.0
 *
 * @see https://docs.osaurus.ai
 */
class OsaurusProvider extends AbstractApiProvider {

	/**
	 * Returns the base URL used for every Osaurus API request.
	 *
	 * The SDK calls this internally from its `url()` helper to build request
	 * URLs like `{baseUrl}/chat/completions`. The URL already includes the
	 * `/v1` suffix because Osaurus exposes its OpenAI-compatible surface
	 * under that path prefix.
	 *
	 * @since 0.1.0
	 *
	 * @return string The configured base URL, without a trailing slash.
	 */
	protected static function baseUrl(): string {
		return get_base_url();
	}

	/**
	 * Creates a model implementation for the given model metadata.
	 *
	 * Osaurus currently only supports text generation / chat, so we dispatch
	 * to a single concrete model class. If future Osaurus versions add other
	 * capabilities (vision input, embeddings, …), extend the branching here
	 * the same way OpenAI's provider does.
	 *
	 * @since 0.1.0
	 *
	 * @param ModelMetadata    $modelMetadata    Metadata describing the model.
	 * @param ProviderMetadata $providerMetadata Metadata describing this provider.
	 * @return ModelInterface The model instance capable of running the requested operation.
	 *
	 * @throws RuntimeException When none of the model's declared capabilities map to a concrete implementation.
	 */
	protected static function createModel(
		ModelMetadata $modelMetadata,
		ProviderMetadata $providerMetadata
	): ModelInterface {
		foreach ( $modelMetadata->getSupportedCapabilities() as $capability ) {
			if ( $capability->isTextGeneration() ) {
				return new OsaurusTextGenerationModel( $modelMetadata, $providerMetadata );
			}
		}

		throw new RuntimeException(
			esc_html(
				'Unsupported Osaurus model capabilities: ' . implode( ', ', $modelMetadata->getSupportedCapabilities() )
			)
		);
	}

	/**
	 * Returns provider-level metadata for Osaurus.
	 *
	 * Identifies the provider to the AI Client and to the Connectors admin UI.
	 *
	 *   - ID `osaurus` — must match `/^[a-z0-9_-]+$/` for the Connectors API.
	 *   - Type {@see ProviderTypeEnum::server()} — local/self-hosted, not `cloud()`.
	 *   - Authentication {@see RequestAuthenticationMethod::apiKey()} even though
	 *     Osaurus does not require a real key. This is needed so the Connectors
	 *     screen treats the row as first-class; see `register_fallback_auth()`
	 *     in the main plugin file.
	 *
	 * The `description` argument only exists on SDK ≥ 1.2.0 — the version
	 * guard lets this plugin run on older SDK builds during the WP 7.0 beta.
	 *
	 * @since 0.1.0
	 *
	 * @return ProviderMetadata
	 */
	protected static function createProviderMetadata(): ProviderMetadata {
		$args = array(
			'osaurus',
			'Osaurus',
			ProviderTypeEnum::server(),
			'https://osaurus.ai',
			RequestAuthenticationMethod::apiKey(),
		);

		if ( version_compare( AiClient::VERSION, '1.2.0', '>=' ) ) {
			// The WP 7.0 Connectors UI currently only renders rows for api_key
			// providers, so we advertise api_key auth even though Osaurus does
			// not require a key. The description doubles as onboarding copy:
			// it points users at the Osaurus install (an external Mac app, not
			// an account they need to sign up for) and clarifies that there
			// is no credential to look up.
			$description = 'Local Apple Silicon LLM runtime with OpenAI-compatible endpoints. Requires the Osaurus app to be installed and running on this Mac — download it from https://osaurus.ai. No API key needed.';

			// Localisable on WordPress; raw string when the SDK is used outside WP (e.g. in tests / CLI).
			$args[] = function_exists( '__' )
				? __( 'Local Apple Silicon LLM runtime with OpenAI-compatible endpoints. Requires the Osaurus app to be installed and running on this Mac — download it from https://osaurus.ai. No API key needed.', 'osaurus-ai-connector' )
				: $description;
		}

		return new ProviderMetadata( ...$args );
	}

	/**
	 * Returns the availability checker for the provider.
	 *
	 * The SDK uses this to answer `isProviderConfigured()`. We use the
	 * stock "list models" availability: the provider is considered available
	 * if `/v1/models` returns a non-empty list. This means Osaurus being
	 * offline correctly reports the provider as unavailable.
	 *
	 * @since 0.1.0
	 *
	 * @return ProviderAvailabilityInterface
	 */
	protected static function createProviderAvailability(): ProviderAvailabilityInterface {
		return new ListModelsApiBasedProviderAvailability( static::modelMetadataDirectory() );
	}

	/**
	 * Returns the model metadata directory for the provider.
	 *
	 * The directory knows how to query `/v1/models` and build
	 * {@see ModelMetadata} objects for each returned model.
	 *
	 * @since 0.1.0
	 *
	 * @return ModelMetadataDirectoryInterface
	 */
	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new OsaurusModelMetadataDirectory();
	}
}
