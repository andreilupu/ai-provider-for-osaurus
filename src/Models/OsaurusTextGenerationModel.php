<?php
/**
 * Osaurus text generation model class.
 *
 * @package OsaurusAi\Connector
 * @since   0.1.0
 */

declare(strict_types=1);

namespace OsaurusAi\Connector\Models;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;
use OsaurusAi\Connector\Provider\OsaurusProvider;

/**
 * Text generation model for Osaurus using the OpenAI-compatible chat completions API.
 *
 * Osaurus implements the exact `POST /v1/chat/completions` request / response
 * schema that the OpenAI Chat Completions API defines. All of the heavy lifting
 * — parameter building, message role mapping, tool call parsing, finish reason
 * translation, token usage extraction — is inherited from the SDK abstract
 * base. The only provider-specific concern is telling the base how to address
 * the Osaurus endpoint, which we do by overriding {@see createRequest()}.
 *
 * If Osaurus ever diverges from strict OpenAI compatibility (for instance by
 * changing the request path, or adding required headers), override additional
 * hook points on the abstract base rather than expanding this method.
 *
 * @since 0.1.0
 */
class OsaurusTextGenerationModel extends AbstractOpenAiCompatibleTextGenerationModel {

	/**
	 * Builds an HTTP request targeted at the configured Osaurus server.
	 *
	 * Called by the SDK's abstract base with the endpoint-relative `$path`
	 * (e.g. `'chat/completions'`). We delegate URL construction to the
	 * provider's {@see OsaurusProvider::url()} helper so the base URL stays
	 * in exactly one place in the codebase.
	 *
	 * `$this->getRequestOptions()` is forwarded so caller-supplied timeouts,
	 * retries, and streaming flags survive round-tripping.
	 *
	 * @since 0.1.0
	 *
	 * @param HttpMethodEnum        $method  HTTP verb, e.g. POST.
	 * @param string                $path    Endpoint-relative request path.
	 * @param array<string, string> $headers Optional request headers.
	 * @param mixed                 $data    Optional request body, typically a JSON-serializable array.
	 * @return Request The constructed request object.
	 */
	protected function createRequest(
		HttpMethodEnum $method,
		string $path,
		array $headers = array(),
		$data = null
	): Request {
		return new Request(
			$method,
			OsaurusProvider::url( $path ),
			$headers,
			$data,
			$this->getRequestOptions()
		);
	}
}
