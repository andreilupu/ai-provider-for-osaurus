# AI Provider for Osaurus

A WordPress plugin that registers [Osaurus](https://osaurus.ai) — a local, Apple-Silicon-native LLM runtime — as a provider for the [WordPress AI Client](https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/) shipping with WordPress 7.0.

Once activated, any plugin that uses `wp_ai_client_prompt()` (Gutenberg AI features, official provider plugins, third‑party code) can route prompts to Osaurus without ever leaving the machine.

---

## Why

- **Privacy first** — prompts never leave the local machine.
- **Zero cost** — no API keys, no metered requests.
- **Fast** — MLX-backed inference on Apple Silicon.
- **OpenAI-compatible wire format** — uses the same `/v1/chat/completions` shape the SDK already knows how to speak, so streaming, tool calls, JSON output and chat history all work out of the box.

## Requirements

| Component | Version |
| --------- | ------- |
| WordPress | 7.0 or newer (for the AI Client + Connectors API) |
| PHP       | 7.4+ |
| Osaurus   | Any recent release, running locally — see [osaurus.ai](https://osaurus.ai) |

## Installation

Install from the [WordPress.org plugin directory](https://wordpress.org/plugins/ai-provider-for-osaurus/), or clone into any WordPress 7.0+ install:

```bash
cd wp-content/plugins
git clone https://github.com/andreilupu/ai-provider-for-osaurus.git
```

Then activate **AI Provider for Osaurus** on the Plugins screen. Any local
WordPress (WP Studio, Local, MAMP, Valet, native PHP) works — the plugin needs
no build step.

## Configuration

Osaurus defaults to `http://127.0.0.1:1337/v1` on the host machine. The plugin resolves the base URL in this order (first match wins):

1. **`OSAURUS_BASE_URL` PHP constant** — define this in `wp-config.php`.
2. **`osaurus_ai_connector_base_url` option** — settable from **Settings → Connectors** or via `update_option()`.
3. **Default: `http://127.0.0.1:1337/v1`** — works out of the box for bare-metal WordPress on the same Mac as Osaurus.

> **Tip for bare-metal installs:** the default already works, no setup required.
> **Tip for Docker-based WordPress (DDEV, Lando, Docker Desktop):** set `OSAURUS_BASE_URL` to `http://host.docker.internal:1337/v1` or pick the Docker preset in the admin UI, so the container can reach Osaurus on the host.

The plugin also whitelists the configured host in `http_request_host_is_external` and appends the configured port to `http_allowed_safe_ports`, so WordPress's safe HTTP API can actually reach a local service on a non-standard port.

## How it works

```
wp_ai_client_prompt('Summarise …')
        │
        ▼
WordPress AI Client (core)
        │
        ▼
AiClient::defaultRegistry()        ◄── registerProvider(OsaurusProvider::class)
        │
        ▼
OsaurusProvider (this plugin)
        │
        ├─► OsaurusModelMetadataDirectory   ──► GET  {baseUrl}/models
        └─► OsaurusTextGenerationModel      ──► POST {baseUrl}/chat/completions
                (extends AbstractOpenAiCompatibleTextGenerationModel)
```

Because the text model inherits from the SDK's OpenAI-compatible base class, this plugin's implementation is small: a provider class, a text-generation model that only overrides URL construction, and a model-metadata directory.

## Capabilities

| Capability           | Supported |
| -------------------- | :-------: |
| Text generation      | ✅ |
| Chat history         | ✅ |
| Function / tool calls | ✅ |
| Structured JSON output | ✅ |
| Streaming            | ✅ (via SDK base) |
| Image generation     | ❌ — Osaurus is text-only |
| Embeddings           | ❌ — not exposed by Osaurus |
| Text-to-speech       | ❌ — not exposed by Osaurus |

If you need image generation, pair this plugin with an image-capable provider such as [AI Provider for OpenAI](https://github.com/WordPress/ai-provider-for-openai).

## Project layout

```
.
├── ai-provider-for-osaurus.php  # Plugin bootstrap, hooks, HTTP filters
├── uninstall.php                # Option cleanup on uninstall
├── src/
│   ├── autoload.php             # PSR-4 autoloader for OsaurusAi\Connector\
│   ├── Provider/
│   │   └── OsaurusProvider.php
│   ├── Models/
│   │   └── OsaurusTextGenerationModel.php
│   └── Metadata/
│       └── OsaurusModelMetadataDirectory.php
├── assets/                      # Shipped JS module + provider logo
└── Readme.md
```

## Development

Develop against any local WordPress 7.0+ install (WP Studio, Local, MAMP,
Valet, native PHP). Symlink or clone this repo into `wp-content/plugins/` and
activate it.

### PHP lint

```bash
find . -name '*.php' | xargs -n1 php -l
```

### Quick smoke test

With Osaurus running and the plugin active, run via WP-CLI against your site:

```bash
wp eval '
    $result = wp_ai_client_prompt("Say hello in three words.")->generate_text();
    echo $result;
'
```

## References

- [Introducing the AI Client in WordPress 7.0](https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/)
- [Introducing the Connectors API in WordPress 7.0](https://make.wordpress.org/core/2026/03/18/introducing-the-connectors-api-in-wordpress-7-0/)
- [WordPress PHP AI Client SDK](https://github.com/WordPress/php-ai-client)
- [Official AI Provider for OpenAI](https://github.com/WordPress/ai-provider-for-openai) — useful reference implementation
- [Osaurus documentation](https://docs.osaurus.ai)

## License

[GPL-2.0-or-later](https://spdx.org/licenses/GPL-2.0-or-later.html).
