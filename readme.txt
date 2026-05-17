=== Osaurus AI Connector ===
Contributors: euthelup
Tags: ai, llm, osaurus, ai-client, local-ai
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPL-2.0-or-later
License URI: https://spdx.org/licenses/GPL-2.0-or-later.html

Registers Osaurus, a local Apple Silicon LLM runtime, as a provider for the WordPress AI Client.

== Description ==

Osaurus AI Connector wires the [Osaurus](https://osaurus.ai) local LLM runtime into the WordPress AI Client that ships with WordPress 7.0.

After activation, any plugin that calls `wp_ai_client_prompt()` (Gutenberg AI features, official provider plugins, third-party code) can route prompts to Osaurus running on the same machine. Prompts never leave the local network.

= Why use it =

* **Privacy first** &mdash; prompts stay on your machine, no third-party API receives the data.
* **Zero cost** &mdash; no API keys, no metered requests.
* **Fast** &mdash; MLX-backed inference on Apple Silicon.
* **OpenAI-compatible wire format** &mdash; streaming, tool calls, JSON output, and chat history work out of the box.

= Capabilities =

* Text generation
* Chat history
* Function / tool calls
* Structured JSON output
* Streaming

Image generation, embeddings, and text-to-speech are not supported because Osaurus does not currently expose those endpoints. Pair this plugin with an image-capable provider such as the official AI Provider for OpenAI when you need images.

= How it works =

The plugin registers a provider with `AiClient::defaultRegistry()` and points it at the Osaurus HTTP server. Because Osaurus speaks the OpenAI Chat Completions wire format, the implementation extends the SDK's OpenAI-compatible base classes &mdash; so capabilities like streaming and tool calling flow through automatically.

= Configuration =

The plugin resolves the Osaurus base URL in this order (first match wins):

1. `OSAURUS_BASE_URL` PHP constant defined in `wp-config.php`.
2. `osaurus_ai_connector_base_url` option, settable from **Settings &rarr; Connectors** in wp-admin.
3. Default: `http://host.docker.internal:1337/v1` (works for `@wordpress/env` Docker setups).

For a bare-metal install, set the constant or option to `http://127.0.0.1:1337/v1`.

= External services =

This plugin sends data **only to the Osaurus server you configure** &mdash; by default a process running on `127.0.0.1` on the same machine as WordPress. No data is sent to any third-party service operated by the plugin author.

When you call `wp_ai_client_prompt()` (or any feature in another plugin that uses the AI Client and routes through Osaurus), the following is sent over HTTP to the configured Osaurus URL:

* The prompt text and conversation history.
* Model name, sampling parameters (temperature, top_p, max tokens, etc.).
* Any tool / function declarations or JSON schema you supplied.

The plugin also fetches `GET {base_url}/models` to populate the model picker.

If you point `OSAURUS_BASE_URL` at a remote Osaurus instance you operate, the same data will be sent to that host. The plugin makes no calls to any other endpoint.

Osaurus project home: [osaurus.ai](https://osaurus.ai)
Osaurus documentation: [docs.osaurus.ai](https://docs.osaurus.ai)

== Installation ==

1. Install and start [Osaurus](https://osaurus.ai) on your Mac (Apple Silicon required).
2. Confirm Osaurus is reachable, e.g. `curl http://127.0.0.1:1337/v1/models`.
3. Install this plugin from the WordPress.org plugin directory, or upload the plugin folder to `wp-content/plugins/`.
4. Activate **Osaurus AI Connector** on the **Plugins** screen.
5. Visit **Settings &rarr; Connectors**. The Osaurus row should report as connected once a model list is available.

If WordPress and Osaurus run on the same host but on different ports, no further configuration is required. If you run WordPress inside Docker (e.g. `@wordpress/env`), the default Docker-aware URL works as-is.

== Frequently Asked Questions ==

= Do I need an API key? =

No. Osaurus is a local server and does not require credentials. The plugin registers a placeholder authentication so the Connectors screen treats the row as configured.

= Does this plugin send data to Osaurus.ai or any third party? =

No. All requests go to the Osaurus URL you configure &mdash; by default a process on your own machine. See the **External services** section above.

= I'm not on Apple Silicon. Will this work? =

Osaurus itself currently targets Apple Silicon. The connector plugin will load on any system but cannot reach Osaurus if Osaurus is not running.

= How do I change the Osaurus URL? =

Either define `OSAURUS_BASE_URL` in `wp-config.php`, or change the URL from **Settings &rarr; Connectors** in wp-admin.

= What about image generation, embeddings, or TTS? =

Not supported. Osaurus only exposes text / chat completions today. For images, install an image-capable provider plugin alongside this one.

= I get an `http_request_failed` error. =

Confirm Osaurus is running and reachable from the host running WordPress. If you run WordPress inside Docker, the host is `host.docker.internal`, not `127.0.0.1`.

== Screenshots ==

1. Osaurus row on the Connectors admin screen with a custom URL field.
2. Model picker populated from the Osaurus `/v1/models` endpoint.

== Changelog ==

= 0.3.0 =
* Custom Connectors UI: replaces the API-key field with a URL input backed by the Settings REST API.
* Registers `osaurus_ai_connector_base_url` as a typed REST setting with `esc_url_raw` sanitization.

= 0.2.0 =
* Whitelist the configured Osaurus host for `wp_safe_remote_*` and append the configured port to `http_allowed_safe_ports`.
* Provider description added (visible in the Connectors UI).

= 0.1.0 =
* Initial release: registers Osaurus as a WordPress AI Client provider with text generation, chat history, tool calls, structured JSON output, and streaming.

== Upgrade Notice ==

= 0.3.0 =
Adds an in-admin URL field; no data migration required.
