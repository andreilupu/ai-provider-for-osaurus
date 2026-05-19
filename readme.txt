=== AI Provider for Osaurus ===
Contributors: euthelup
Tags: ai, connector, llm, local-ai, osaurus
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.4.1
License: GPL-2.0-or-later
License URI: https://spdx.org/licenses/GPL-2.0-or-later.html

Registers Osaurus, a local Apple Silicon LLM runtime, as a provider for the WordPress AI Client.

== Description ==

AI Provider for Osaurus wires the [Osaurus](https://osaurus.ai) local LLM runtime into the WordPress AI Client that ships with WordPress 7.0.

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

= Pick the right URL for your setup =

WordPress can run in many places &mdash; the right base URL depends on how it reaches your Mac, where Osaurus is listening.

* **Bare-metal WordPress on the same Mac** (MAMP, Laravel Valet, Local, WordPress Studio, native PHP):
  `http://127.0.0.1:1337/v1`
* **Docker-based WordPress on the same Mac** (`@wordpress/env`, DDEV, Lando, Docker Desktop):
  `http://host.docker.internal:1337/v1` *(this is the default &mdash; no configuration needed)*
* **WordPress on a different machine on your LAN, Osaurus on your Mac:**
  `http://<your-mac-LAN-IP>:1337/v1` &mdash; make sure Osaurus is bound to a non-loopback interface and your firewall allows port 1337.
* **Remote Osaurus you operate** (e.g. a Mac mini on Tailscale, a colocated server):
  `http://<remote-host>:1337/v1` or `https://...` if you front it with TLS.

The fastest way to confirm a URL works from the WordPress host: run `curl <base-url>/models` from the same shell environment WordPress runs in. A JSON list back means the connector will work.

Osaurus project home: [osaurus.ai](https://osaurus.ai)
Osaurus documentation: [docs.osaurus.ai](https://docs.osaurus.ai)

== External services ==

This plugin connects to an Osaurus HTTP server &mdash; a local LLM runtime that you install and run yourself on the same machine as WordPress (or on a host you operate). The plugin is useless without it: every text-generation request from the WordPress AI Client is routed to this server.

**What the service is and what it is used for**

Osaurus is a local Apple Silicon LLM runtime (an OpenAI-compatible HTTP server) that you run on your own hardware. This plugin forwards prompts to it so WordPress can perform text generation, chat, tool calls, and structured JSON output without sending data to any cloud provider.

**Where the data is sent**

The plugin only contacts the host and port resolved from the `OSAURUS_BASE_URL` constant, the `osaurus_ai_connector_base_url` option, or the built-in default. The default targets are:

* `http://127.0.0.1:1337/v1` &mdash; used when WordPress and Osaurus run on the same machine (bare-metal: Studio, MAMP, Valet, Local, native PHP).
* `http://host.docker.internal:1337/v1` &mdash; used when WordPress runs inside Docker on the same Mac (`@wordpress/env`, DDEV, Lando, Docker Desktop). This is the plugin's built-in default and is resolved by Docker to the host machine.

If you change the URL, the plugin will only contact the host and port you configure. The plugin never contacts any third-party endpoint operated by the plugin author, Osaurus project, or any other party.

**What data is sent and when**

* When any plugin (including WordPress core) calls `wp_ai_client_prompt()` and routes through Osaurus, the plugin sends an HTTPS/HTTP `POST` to `{base_url}/chat/completions` with: the prompt text, the conversation history you supplied, the model ID, sampling parameters (temperature, top_p, max tokens, etc.), and any tool / function declarations or JSON schemas you supplied.
* When an admin opens **Settings &rarr; Connectors**, the plugin sends a `GET {base_url}/models` to populate the model picker and a `GET {root_url}/health` to display a connection status indicator. Both run only for users with `manage_options` and are triggered by user actions in wp-admin.
* No telemetry, analytics, or background requests are sent.

**Terms of service and privacy policy**

Osaurus is open-source software you self-host. There is no third-party service operator collecting your data. Osaurus documentation and source: [osaurus.ai](https://osaurus.ai) and [docs.osaurus.ai](https://docs.osaurus.ai). If you point the plugin at a remote Osaurus server operated by someone else (e.g. a hosted Mac mini), the operator of that server is the data recipient and is governed by the terms you have with them.

== Installation ==

1. Install and start [Osaurus](https://osaurus.ai) on your Mac (Apple Silicon required).
2. Confirm Osaurus is reachable, e.g. `curl http://127.0.0.1:1337/v1/models`.
3. Install this plugin from the WordPress.org plugin directory, or upload the plugin folder to `wp-content/plugins/`.
4. Activate **AI Provider for Osaurus** on the **Plugins** screen.
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

= 0.4.1 =
* New: provider logo wired through `ProviderMetadata::$logoPath` so the Connectors UI shows the Osaurus mark next to the provider name (SDK ≥ 1.3.0).
* Docs: expanded the External services section in readme to document the Osaurus host targets (`127.0.0.1:1337`, `host.docker.internal:1337`), what data is sent, and when.

= 0.4.0 =
* New: live "Reachable" status indicator under the URL field, with round-trip latency.
* New: quick-pick preset buttons for bare-metal (`127.0.0.1`) and Docker (`host.docker.internal`) setups.
* New: default-model dropdown populated from the configured Osaurus server.
* New: `GET /osaurus-ai-connector/v1/models` REST route (admin-only) that proxies the Osaurus models list and accepts a `base_url` override for pre-save probing.
* New: `osaurus_ai_connector_default_model` option, readable by consumer plugins as a fallback model.

= 0.3.0 =
* Custom Connectors UI: replaces the API-key field with a URL input backed by the Settings REST API.
* Registers `osaurus_ai_connector_base_url` as a typed REST setting with `esc_url_raw` sanitization.

= 0.2.0 =
* Whitelist the configured Osaurus host for `wp_safe_remote_*` and append the configured port to `http_allowed_safe_ports`.
* Provider description added (visible in the Connectors UI).

= 0.1.0 =
* Initial release: registers Osaurus as a WordPress AI Client provider with text generation, chat history, tool calls, structured JSON output, and streaming.

== Upgrade Notice ==

= 0.4.1 =
Documentation-only update. No data migration required.

= 0.4.0 =
Adds a connection status indicator, preset buttons, and a default-model picker. No data migration required.

= 0.3.0 =
Adds an in-admin URL field; no data migration required.
