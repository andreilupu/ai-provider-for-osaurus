/**
 * Osaurus AI Connector — admin script module.
 *
 * Registers a custom React render for the Osaurus connector row on the
 * Connectors admin screen via the experimental client-side registration
 * API. Replaces the default API-key TextControl with a URL input backed by
 * the `osaurus_ai_connector_base_url` setting, read and written through the
 * Settings REST API via `@wordpress/core-data`.
 *
 * Guidance from the WordPress AI / Core team (Slack, April 2026): until the
 * Connectors public API stabilises, custom configuration should live in JS
 * via `__experimentalRegisterConnector`, not in a new PHP field registry.
 *
 * ### Module / classic-script mix
 *
 * `@wordpress/connectors` is the only WordPress package exposed as a script
 * module in WP 7.0, so `registerConnector` / `ConnectorItem` are pulled via
 * real ES imports. Every other `@wordpress/*` dependency (element, components,
 * core-data, i18n) is consumed through the classic `window.wp.*` globals that
 * the Connectors page already enqueues for the admin SPA.
 *
 * @package OsaurusAi\Connector
 * @since   0.3.0
 */

import {
	__experimentalRegisterConnector as registerConnector,
	__experimentalConnectorItem as ConnectorItem,
} from '@wordpress/connectors';

/** @type {typeof window.wp.element} */
const { createElement: h, useState } = window.wp.element;

const {
	Button,
	TextControl,
	__experimentalHStack: HStack,
	__experimentalVStack: VStack,
} = window.wp.components;

const { useEntityRecord } = window.wp.coreData;
const { __ } = window.wp.i18n;

/**
 * Option name used to persist the base URL. Must match `BASE_URL_OPTION`
 * declared in `osaurus-ai-connector.php`.
 */
const BASE_URL_OPTION = 'osaurus_ai_connector_base_url';

/**
 * Collapsible URL form rendered inside the Osaurus ConnectorItem.
 *
 * Reads and writes via `@wordpress/core-data`'s `useEntityRecord` against
 * the `root/site` entity, which maps to the WordPress Settings REST API.
 */
function OsaurusSettings() {
	const [ isExpanded, setIsExpanded ] = useState( false );
	const { editedRecord, edit, save, hasEdits, isSaving } = useEntityRecord(
		'root',
		'site'
	);

	const currentValue = editedRecord?.[ BASE_URL_OPTION ] ?? '';
	const isConnected = Boolean( currentValue );

	let buttonLabel;
	if ( isExpanded ) {
		buttonLabel = __( 'Cancel', 'osaurus-ai-connector' );
	} else if ( isConnected ) {
		buttonLabel = __( 'Edit', 'osaurus-ai-connector' );
	} else {
		buttonLabel = __( 'Configure', 'osaurus-ai-connector' );
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

	const urlField = h( TextControl, {
		__next40pxDefaultSize: true,
		__nextHasNoMarginBottom: true,
		type: 'url',
		label: __( 'Server URL', 'osaurus-ai-connector' ),
		value: currentValue,
		onChange: ( next ) => edit( { [ BASE_URL_OPTION ]: next } ),
		placeholder: 'http://127.0.0.1:1337/v1',
		disabled: isSaving,
		help: __(
			'Base URL of your local Osaurus server, including the /v1 path.',
			'osaurus-ai-connector'
		),
	} );

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
					// Stay expanded so the user sees the inline notice.
				}
			},
		},
		__( 'Save', 'osaurus-ai-connector' )
	);

	return h(
		VStack,
		{ spacing: 4, className: 'osaurus-ai-connector-settings' },
		urlField,
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
