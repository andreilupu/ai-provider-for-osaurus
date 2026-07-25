/**
 * Build config for the Osaurus connector settings module.
 *
 * The Connectors screen is a WordPress admin app built on **script modules**,
 * but only `@wordpress/connectors` is published as a script module in WP 7.0.
 * `@wordpress/dataviews` (which gives us `DataForm`) is neither a script module
 * nor a classic `window.wp.*` global, so it must be bundled into our own file.
 *
 * That forces a specific, slightly unusual externals split — the "boilerplate"
 * every DataForms-based connector needs until a native form API lands:
 *
 *   - `@wordpress/connectors`  → external **module** import (browser resolves it
 *                                 through the page's import map at runtime).
 *   - every other `@wordpress/x` that WP already exposes as a global
 *                                 → external `window.wp.x` (shared singletons:
 *                                 one React, one data registry, one private-apis
 *                                 lock table — never a second copy).
 *   - `react` / `react-dom` / `react/jsx-runtime`
 *                                 → the shared `window.React*` the admin loads.
 *   - `@wordpress/dataviews` and anything not available at runtime
 *                                 (`@wordpress/icons`, `@wordpress/ui`, ariakit,
 *                                 clsx, date-fns, …) → **bundled** (all stateless,
 *                                 safe to duplicate).
 *
 * The output is therefore a real ES module: it keeps a bare
 * `import … from "@wordpress/connectors"` while embedding DataViews.
 *
 * The list of runtime-available `window.wp.*` globals below was taken from the
 * live Connectors screen (`Object.keys( window.wp )`); a package absent from it
 * (e.g. `icons`) is intentionally left to be bundled.
 */

const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

/**
 * `window.wp.*` globals present on the WP 7.0 Connectors screen. Any
 * `@wordpress/<pkg>` whose camelCased name is here is externalized to the
 * shared global; anything else is bundled.
 */
const WP_GLOBALS = new Set( [
	'a11y',
	'apiFetch',
	'components',
	'compose',
	'coreData',
	'data',
	'date',
	'deprecated',
	'dom',
	'element',
	'hooks',
	'htmlEntities',
	'i18n',
	'isShallowEqual',
	'keycodes',
	'notices',
	'primitives',
	'priorityQueue',
	'privateApis',
	'richText',
	'url',
	'warning',
] );

/** Convert a kebab-case package name to the WordPress camelCase global name. */
const toCamelCase = ( name ) =>
	name.replace( /-([a-z])/g, ( _, letter ) => letter.toUpperCase() );

/**
 * Externals resolver. Returns:
 *   - a `'module …'` string  → emitted as an ES `import` (import-map resolved),
 *   - a `[ 'wp', 'x' ]` array → `window.wp.x`,
 *   - a `[ 'React' ]` array   → `window.React`,
 *   - `undefined`             → bundle it.
 */
const externals = ( { request }, callback ) => {
	if ( request === '@wordpress/connectors' ) {
		// The one script-module dependency: keep it as a bare import.
		return callback( null, 'module @wordpress/connectors' );
	}

	if ( request === 'react' ) {
		return callback( null, [ 'React' ] );
	}
	if ( request === 'react-dom' ) {
		return callback( null, [ 'ReactDOM' ] );
	}
	if ( request === 'react/jsx-runtime' ) {
		return callback( null, [ 'ReactJSXRuntime' ] );
	}

	const wpMatch = request.match( /^@wordpress\/([a-z0-9-]+)$/ );
	if ( wpMatch ) {
		const camel = toCamelCase( wpMatch[ 1 ] );
		if ( WP_GLOBALS.has( camel ) ) {
			// Shared singleton — reference the global, do not bundle.
			return callback( null, [ 'wp', camel ] );
		}
		// e.g. @wordpress/dataviews, @wordpress/icons, @wordpress/ui — bundle.
		return callback();
	}

	// Plain npm dependencies (ariakit, clsx, date-fns, …) — bundle.
	return callback();
};

module.exports = {
	...defaultConfig,
	entry: {
		'connector-settings': path.resolve(
			__dirname,
			'assets/js/connector-settings.js'
		),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
		filename: '[name].js',
		module: true,
		chunkFormat: 'module',
		library: { type: 'module' },
		environment: { ...defaultConfig.output?.environment, module: true },
	},
	experiments: {
		...defaultConfig.experiments,
		outputModule: true,
	},
	module: {
		...defaultConfig.module,
		rules: [
			...defaultConfig.module.rules,
			// `@wordpress/dataviews` declares `"sideEffects": false`, so webpack
			// tree-shakes away our side-effect-only stylesheet import from that
			// package — silently, with no error and no emitted CSS. Flagging CSS
			// as side-effectful keeps the import alive so the stylesheet is
			// actually emitted.
			{ test: /\.css$/i, sideEffects: true },
		],
	},
	// Keep the JS in a single self-contained file.
	//
	// Do NOT set `splitChunks: false` here: wp-scripts relies on a
	// `cacheGroups.style` group (type `css/mini-extract`, enforced) to gather
	// imported CSS into an emitted chunk. Disabling splitChunks leaves the CSS
	// modules orphaned — webpack processes them and then emits no stylesheet at
	// all, silently. The inherited config already sets `cacheGroups.default:
	// false`, so JS is not split into vendor chunks either way.
	optimization: {
		...defaultConfig.optimization,
		runtimeChunk: false,
	},
	externalsType: 'window',
	externals,
	// `@wordpress/dependency-extraction-webpack-plugin` would try to externalize
	// every `@wordpress/*` (including dataviews) to non-existent globals and
	// emit a classic-script asset file — both wrong here. We own externals.
	plugins: defaultConfig.plugins.filter(
		( plugin ) =>
			plugin.constructor.name !==
			'DependencyExtractionWebpackPlugin'
	),
};
