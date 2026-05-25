<?php
/**
 * Osaurus model metadata directory class.
 *
 * @package OsaurusAi\Connector
 * @since   0.1.0
 */

declare(strict_types=1);

namespace OsaurusAi\Connector\Metadata;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleModelMetadataDirectory;
use OsaurusAi\Connector\Provider\OsaurusProvider;

/**
 * Model metadata directory for Osaurus.
 *
 * Osaurus exposes `GET /v1/models` in the OpenAI shape:
 *
 *     { "data": [ { "id": "...", "object": "model", ... }, ... ] }
 *
 * The response does not describe per-model capabilities. We therefore tag
 * every returned model with the same text-generation / chat-history
 * capability set and a conservative list of supported options. If Osaurus
 * starts serving models with different capabilities (vision input,
 * embeddings, tool-use-only, …), add ID-prefix classification here the same
 * way the official OpenAI provider plugin does for `gpt-*` vs `dall-e-*`.
 *
 * The base class {@see AbstractOpenAiCompatibleModelMetadataDirectory}
 * handles the request/response lifecycle: we only need to
 *   - build the Request ({@see createRequest()}), and
 *   - turn the Response into a list of {@see ModelMetadata}
 *     ({@see parseResponseToModelMetadataList()}).
 *
 * @since 0.1.0
 *
 * @phpstan-type ModelsResponseData array{
 *     data: list<array{id: string}>
 * }
 */
class OsaurusModelMetadataDirectory extends AbstractOpenAiCompatibleModelMetadataDirectory {

	/**
	 * Builds the HTTP request for the `/v1/models` endpoint.
	 *
	 * The SDK abstract base invokes this with `$path === 'models'`. We pass
	 * that straight through to {@see OsaurusProvider::url()}, which returns
	 * a fully-qualified URL built from the configured base URL.
	 *
	 * @since 0.1.0
	 *
	 * @param HttpMethodEnum        $method  HTTP method.
	 * @param string                $path    Endpoint-relative request path.
	 * @param array<string, string> $headers Optional headers.
	 * @param mixed                 $data    Optional request body.
	 * @return Request The constructed request object.
	 */
	protected function createRequest( HttpMethodEnum $method, string $path, array $headers = array(), $data = null ): Request {
		return new Request(
			$method,
			OsaurusProvider::url( $path ),
			$headers,
			$data
		);
	}

	/**
	 * Parses a `/v1/models` response into a list of `ModelMetadata` objects.
	 *
	 * Models are returned in the order Osaurus advertises them, then re-sorted
	 * by {@see sortModels()} so users see the most useful defaults first in
	 * the Connectors admin UI and block-editor model picker.
	 *
	 * @since 0.1.0
	 *
	 * @param Response $response Raw response from `/v1/models`.
	 * @return list<ModelMetadata> The parsed and sorted model list.
	 *
	 * @throws ResponseException When the response body is missing the required `data` array.
	 */
	protected function parseResponseToModelMetadataList( Response $response ): array {
		/** @var ModelsResponseData $data */
		$data = $response->getData();

		if ( ! isset( $data['data'] ) || ! is_array( $data['data'] ) ) {
			throw ResponseException::fromMissingData( 'Osaurus', 'data' );
		}

		// Every model is treated as a chat-completions text generator. This
		// matches Osaurus's current feature set (chat + tool calling + JSON).
		$capabilities = array(
			CapabilityEnum::textGeneration(),
			CapabilityEnum::chatHistory(),
		);

		// The options below mirror the feature surface advertised by the
		// OpenAI plugin's `gptBaseOptions`. Osaurus supports the same
		// sampling / formatting / tool-use knobs because it re-uses the
		// OpenAI chat-completions contract.
		$options = array(
			new SupportedOption( OptionEnum::systemInstruction() ),
			new SupportedOption( OptionEnum::candidateCount() ),
			new SupportedOption( OptionEnum::maxTokens() ),
			new SupportedOption( OptionEnum::temperature() ),
			new SupportedOption( OptionEnum::topP() ),
			new SupportedOption( OptionEnum::stopSequences() ),
			new SupportedOption( OptionEnum::outputMimeType(), array( 'text/plain', 'application/json' ) ),
			new SupportedOption( OptionEnum::outputSchema() ),
			new SupportedOption( OptionEnum::functionDeclarations() ),
			new SupportedOption( OptionEnum::customOptions() ),
			new SupportedOption( OptionEnum::inputModalities(), array( array( ModalityEnum::text() ) ) ),
			new SupportedOption( OptionEnum::outputModalities(), array( array( ModalityEnum::text() ) ) ),
		);

		$models = array();
		foreach ( (array) $data['data'] as $model_data ) {
			// Skip malformed entries rather than blowing up the whole list.
			if ( empty( $model_data['id'] ) || ! is_string( $model_data['id'] ) ) {
				continue;
			}

			$id       = $model_data['id'];
			// Osaurus does not expose a display name separate from the ID,
			// so we reuse the ID for both to avoid a confusing empty label.
			$models[] = new ModelMetadata( $id, $id, $capabilities, $options );
		}

		usort( $models, array( $this, 'sortModels' ) );

		return $models;
	}

	/**
	 * Sorts models so the most useful defaults appear first.
	 *
	 * Pushes `-preview`, `-draft`, and `-test` variants to the bottom of the
	 * list. Falls back to case-sensitive alphabetical order to keep the
	 * ordering stable across requests.
	 *
	 * Intended to be used as a `usort()` callback; the signature matches
	 * PHP's comparator contract (return <0 / 0 / >0).
	 *
	 * @since 0.1.0
	 *
	 * @param ModelMetadata $a First model.
	 * @param ModelMetadata $b Second model.
	 * @return int Negative if `$a` should sort before `$b`, positive if after, zero for equal.
	 */
	protected function sortModels( ModelMetadata $a, ModelMetadata $b ): int {
		$aId = $a->getId();
		$bId = $b->getId();

		// Demote known "experimental" model ID suffixes. `strpos` is used
		// rather than `str_contains` because the latter is PHP 8.0+ and the
		// plugin still supports PHP 7.4 per the `Requires PHP` header.
		foreach ( array( '-preview', '-draft', '-test' ) as $demote ) {
			$aHas = false !== strpos( $aId, $demote );
			$bHas = false !== strpos( $bId, $demote );
			if ( $aHas && ! $bHas ) {
				return 1;
			}
			if ( $bHas && ! $aHas ) {
				return -1;
			}
		}

		return strcmp( $aId, $bId );
	}
}
