=== AI Provider for Osaurus ===
Contributors: euthelup
Tags: ai, connector, llm, local-ai, osaurus
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.4.2
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
3. Default: `http://127.0.0.1:1337/v1` (works out of the box for bare-metal WordPress on the same Mac as Osaurus; Docker setups must override).

= Pick the right URL for your setup =

WordPress can run in many places &mdash; the right base URL depends on how it reaches your Mac, where Osaurus is listening.

* **Bare-metal WordPress on the same Mac** (MAMP, Laravel Valet, Local, WordPress Studio, native PHP):
  `http://127.0.0.1:1337/v1` *(this is the default &mdash; no configuration needed)*
* **Docker-based WordPress on the same Mac** (DDEV, Lando, Docker Desktop):
  `http://host.docker.internal:1337/v1`
* **WordPress on a different machine on your LAN, Osaurus on your Mac:**
  `http://<your-mac-LAN-IP>:1337/v1` &mdash; make sure Osaurus is bound to a non-loopback interface and your firewall allows port 1337.
* **Remote Osaurus you operate** (e.g. a Mac mini on Tailscale, a colocated server):
  `http://<remote-host>:1337/v1` or `https://...` if you front it with TLS.

The fastest way to confirm a URL works from the WordPress host: run `curl <base-url>/models` from the same shell environment WordPress runs in. A JSON list back means the connector will work.

Osaurus project home: [osaurus.ai](https://osaurus.ai)
Osaurus documentation: [docs.osaurus.ai](https://docs.osaurus.ai)

== External services ==

This plugin connects to an Osaurus HTTP server &mdash; a local LLM runtime that you install and run yourself on the same machine as WordPress (or on a host you operate). The plugin is useless without it: every text-generation request from the WordPress AI Client is routed to this server.

**Service: Osaurus (self-hosted, local LLM runtime)**

Osaurus is an open-source Apple Silicon LLM runtime that exposes an OpenAI-compatible HTTP API. It is installed and operated by the same person running WordPress &mdash; there is no third-party service operator. This plugin forwards prompts to it so WordPress can perform text generation, chat, tool calls, and structured JSON output without sending data to any cloud provider.

Project homepage and download (`osaurus.ai`): https://osaurus.ai
Project documentation (`docs.osaurus.ai`): https://docs.osaurus.ai

These URLs are referenced in the plugin's admin UI as documentation/download links only; the plugin does **not** make HTTP requests to `osaurus.ai` or `docs.osaurus.ai`.

**Hosts the plugin actually contacts at runtime**

The plugin sends HTTP requests only to the host and port resolved (in order) from:

1. The `OSAURUS_BASE_URL` PHP constant (if defined in `wp-config.php`).
2. The `osaurus_ai_connector_base_url` WordPress option (set from **Settings &rarr; Connectors**).
3. The built-in default `http://127.0.0.1:1337/v1`.

Two host targets are referenced by name in the plugin code as quick-pick presets and as the default:

* `127.0.0.1:1337` &mdash; loopback on the same machine as WordPress (default). Used by bare-metal installs (Studio, MAMP, Valet, Local, native PHP).
* `host.docker.internal:1337` &mdash; Docker's hostname for the host machine. Used by Docker-based WordPress (DDEV, Lando, Docker Desktop). Resolved by Docker, not by a public DNS server.

If the user configures a different URL, the plugin only contacts that URL. The plugin never contacts any third-party endpoint operated by the plugin author, the Osaurus project, or any other party.

**What data is sent and when**

* When any plugin (including WordPress core) calls `wp_ai_client_prompt()` and routes through Osaurus, the plugin sends a `POST` to `{base_url}/chat/completions` with: the prompt text, the conversation history you supplied, the model ID, sampling parameters (temperature, top_p, max tokens, etc.), and any tool / function declarations or JSON schemas you supplied.
* When an admin opens **Settings &rarr; Connectors**, the plugin sends a `GET {base_url}/models` to populate the model picker and a `GET {base_root}/health` to display a connection status indicator. Both run only for users with `manage_options` and are triggered by user actions in wp-admin.
* No telemetry, analytics, or background requests are sent.

**Terms of service and privacy policy**

Osaurus is open-source software you self-host. There is no third-party service operator collecting your data. See the project pages linked above for the project's terms and source. If you point the plugin at a remote Osaurus server operated by someone else (for example, a Mac mini you reach over Tailscale), the operator of that server is the data recipient and is governed by whatever terms you have with them.

== Installation ==

1. Install and start [Osaurus](https://osaurus.ai) on your Mac (Apple Silicon required).
2. Confirm Osaurus is reachable, e.g. `curl http://127.0.0.1:1337/v1/models`.
3. Install this plugin from the WordPress.org plugin directory, or upload the plugin folder to `wp-content/plugins/`.
4. Activate **AI Provider for Osaurus** on the **Plugins** screen.
5. Visit **Settings &rarr; Connectors**. The Osaurus row should report as connected once a model list is available.

If WordPress and Osaurus run on the same host, no further configuration is required &mdash; the default `http://127.0.0.1:1337/v1` works as-is. If you run WordPress inside Docker (DDEV, Lando, Docker Desktop), switch the URL to `http://host.docker.internal:1337/v1` from **Settings &rarr; Connectors** or set `OSAURUS_BASE_URL` in your environment config.

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

Confirm Osaurus is running and reachable from the host running WordPress. If you run WordPress inside Docker, change the URL to `http://host.docker.internal:1337/v1` &mdash; the default `127.0.0.1` only works for bare-metal WordPress.

== Screenshots ==

1. The Osaurus connector on the Connectors admin screen: server URL field, live reachability status with round-trip latency, bare-metal / Docker presets, and a default-model picker populated from the Osaurus server.
2. Using AI in the block editor — the paragraph toolbar offers Shorten, Expand, and Rephrase, all routed to the local Osaurus server.
3. An AI-generated suggested replacement shown in the editor, produced locally by Osaurus.

== Changelog ==

= 0.4.2 =
* Initial release: registers Osaurus as a WordPress AI Client provider with text generation, chat history, tool calls, structured JSON output, and streaming. Custom Connectors UI with URL input, bare-metal / Docker presets, live connection status, and a default-model picker.
