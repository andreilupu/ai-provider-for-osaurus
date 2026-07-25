/**
 * AI Provider for Osaurus — admin script module (DataViews / DataForm).
 *
 * Registers a custom render for the Osaurus connector row on the Connectors
 * admin screen and renders its configuration with `@wordpress/dataviews`'
 * `DataForm`, backed by the Settings REST API via `@wordpress/core-data`.
 *
 * ### Why this file is bundled (the DataForms "boilerplate")
 *
 * The Connectors screen is a script-module app, but only `@wordpress/connectors`
 * ships as a script module in WP 7.0. `@wordpress/dataviews` does not — it is
 * neither a module nor a `window.wp.*` global — so it is bundled into this file
 * by `webpack.config.js`. Everything else `@wordpress/*` resolves to the shared
 * `window.wp.*` globals the admin already loads (one React, one data store),
 * and `@wordpress/connectors` stays a bare ES import resolved by the page's
 * import map. See `webpack.config.js` for the full externals split.
 *
 * When a native PHP form/field API for connectors lands, this whole file — and
 * its bundling — collapses into a declarative `$registry->register()` call.
 *
 * @package OsaurusAi\Connector
 * @since   0.5.0
 */

import {
	__experimentalRegisterConnector as registerConnector,
	__experimentalConnectorItem as ConnectorItem,
} from '@wordpress/connectors';
import { DataForm } from '@wordpress/dataviews';

// DataViews ships its own stylesheet, and nothing on the Connectors screen
// loads it (the screen does not use DataViews itself). Importing it here makes
// webpack emit `build/connector-settings.css`, which the plugin enqueues — see
// `enqueue_connector_settings_module()`. Without this the form renders
// unstyled.
import '@wordpress/dataviews/build-style/style.css';

/** @type {typeof window.wp.element} */
const { createElement: h, useState } = window.wp.element;

const {
	Button,
	__experimentalHStack: HStack,
	__experimentalVStack: VStack,
} = window.wp.components;

const { useEntityRecord } = window.wp.coreData;
const { __ } = window.wp.i18n;
const apiFetch = window.wp.apiFetch;
const { addQueryArgs } = window.wp.url;

/**
 * Option name used to persist the base URL. Must match `BASE_URL_OPTION`
 * declared in `ai-provider-for-osaurus.php`.
 */
const BASE_URL_OPTION = 'osaurus_ai_connector_base_url';

/**
 * Option name used to persist the user-selected default model. Must match
 * `DEFAULT_MODEL_OPTION` declared in `ai-provider-for-osaurus.php`.
 */
const DEFAULT_MODEL_OPTION = 'osaurus_ai_connector_default_model';

/**
 * Maps `DataForm` field ids to the WordPress option names they persist to.
 * The form speaks in short field ids; the Settings REST API speaks in option
 * names, so `onChange` translates between them.
 */
const FIELD_TO_OPTION = {
	base_url: BASE_URL_OPTION,
	default_model: DEFAULT_MODEL_OPTION,
};

/**
 * Collapsible settings panel rendered inside the Osaurus ConnectorItem.
 *
 * Reads and writes via `@wordpress/core-data`'s `useEntityRecord` against the
 * `root/site` entity (the WordPress Settings REST API), and renders the two
 * fields through a single `DataForm`.
 */
function OsaurusSettings() {
	const [ isExpanded, setIsExpanded ] = useState( false );
	const { editedRecord, edit, save, hasEdits, isSaving } = useEntityRecord(
		'root',
		'site'
	);

	const baseUrl = editedRecord?.[ BASE_URL_OPTION ] ?? '';
	const model = editedRecord?.[ DEFAULT_MODEL_OPTION ] ?? '';
	const isConnected = Boolean( baseUrl );

	// `DataForm` reads values by field id, so expose the record under the
	// short ids the fields declare below.
	const data = { base_url: baseUrl, default_model: model };

	const fields = [
		{
			id: 'base_url',
			label: __( 'Server URL', 'ai-provider-for-osaurus' ),
			type: 'text',
			placeholder: 'http://127.0.0.1:1337/v1',
			description: __(
				'Base URL of your local Osaurus server, including the /v1 path.',
				'ai-provider-for-osaurus'
			),
		},
		{
			id: 'default_model',
			label: __( 'Default model', 'ai-provider-for-osaurus' ),
			description: __(
				'Optional. Used by consumer plugins as a fallback when a request does not name a model.',
				'ai-provider-for-osaurus'
			),
			// Async, lazy elements: the option list comes from whatever Osaurus
			// advertises right now, fetched through the server-side REST proxy
			// and scoped to the URL currently being edited. This is the pattern
			// every local-model connector needs — resolved client-side at render
			// instead of materialized at registration time.
			getElements: async () => {
				if ( ! baseUrl ) {
					return [];
				}
				try {
					const payload = await apiFetch( {
						path: addQueryArgs(
							'/ai-provider-for-osaurus/v1/models',
							{ base_url: baseUrl }
						),
					} );
					const list = Array.isArray( payload?.models )
						? payload.models
						: [];
					return list.map( ( id ) => ( { value: id, label: id } ) );
				} catch ( _error ) {
					return [];
				}
			},
		},
	];

	const form = {
		layout: { type: 'regular', labelPosition: 'top' },
		fields: [ 'base_url', 'default_model' ],
	};

	// Translate field-id edits into option-name edits for core-data.
	const onChange = ( edits ) => {
		const next = {};
		for ( const [ fieldId, value ] of Object.entries( edits ) ) {
			next[ FIELD_TO_OPTION[ fieldId ] ?? fieldId ] = value;
		}
		edit( next );
	};

	let buttonLabel;
	if ( isExpanded ) {
		buttonLabel = __( 'Cancel', 'ai-provider-for-osaurus' );
	} else if ( isConnected ) {
		buttonLabel = __( 'Edit', 'ai-provider-for-osaurus' );
	} else {
		buttonLabel = __( 'Configure', 'ai-provider-for-osaurus' );
	}

	const toggleButton = h(
		Button,
		{
			variant: isExpanded || isConnected ? 'tertiary' : 'secondary',
			size: 'compact',
			onClick: () => setIsExpanded( ( prev ) => ! prev ),
		},
		buttonLabel
	);

	if ( ! isExpanded ) {
		return h(
			HStack,
			{ justify: 'flex-end', spacing: 3, expanded: false },
			toggleButton
		);
	}

	const saveButton = h(
		Button,
		{
			__next40pxDefaultSize: true,
			variant: 'primary',
			isBusy: isSaving,
			disabled: ! hasEdits || isSaving,
			accessibleWhenDisabled: true,
			onClick: async () => {
				try {
					await save();
					setIsExpanded( false );
				} catch ( _error ) {
					// Stay expanded so the user can retry.
				}
			},
		},
		__( 'Save', 'ai-provider-for-osaurus' )
	);

	return h(
		VStack,
		{ spacing: 4, className: 'ai-provider-for-osaurus-settings' },
		h( DataForm, { data, fields, form, onChange } ),
		h(
			HStack,
			{ justify: 'flex-start', spacing: 2 },
			saveButton,
			toggleButton
		)
	);
}

registerConnector( 'osaurus', {
	render: ( { name, description, logo } ) =>
		h(
			ConnectorItem,
			{ name, description, logo, actionArea: null },
			h( OsaurusSettings )
		),
} );
