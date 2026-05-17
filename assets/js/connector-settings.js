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
const { createElement: h, useState, useEffect } = window.wp.element;

const {
	Button,
	TextControl,
	SelectControl,
	Notice,
	Spinner,
	__experimentalHStack: HStack,
	__experimentalVStack: VStack,
} = window.wp.components;

const { useEntityRecord } = window.wp.coreData;
const { __, sprintf } = window.wp.i18n;
const apiFetch = window.wp.apiFetch;
const { addQueryArgs } = window.wp.url;

/**
 * Option name used to persist the base URL. Must match `BASE_URL_OPTION`
 * declared in `osaurus-ai-connector.php`.
 */
const BASE_URL_OPTION = 'osaurus_ai_connector_base_url';

/**
 * Option name used to persist the user-selected default model. Must match
 * `DEFAULT_MODEL_OPTION` declared in `osaurus-ai-connector.php`.
 */
const DEFAULT_MODEL_OPTION = 'osaurus_ai_connector_default_model';

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
	const currentModel = editedRecord?.[ DEFAULT_MODEL_OPTION ] ?? '';
	const isConnected = Boolean( currentValue );

	// Models fetched from the server-side REST proxy. Kept as local state
	// because the result depends on what Osaurus advertises right now, not
	// on any persisted WordPress data.
	const [ models, setModels ] = useState( [] );
	const [ modelsError, setModelsError ] = useState( '' );
	const [ isLoadingModels, setIsLoadingModels ] = useState( false );
	const [ latencyMs, setLatencyMs ] = useState( null );

	// Refetch the model list whenever the panel opens or the URL changes.
	// Debounced so we do not hammer the REST proxy on every keystroke while
	// the user is still typing.
	useEffect( () => {
		if ( ! isExpanded || ! currentValue ) {
			return;
		}

		let cancelled = false;
		const handle = setTimeout( () => {
			setIsLoadingModels( true );
			setModelsError( '' );
			const startedAt =
				typeof performance !== 'undefined' && performance.now
					? performance.now()
					: Date.now();

			apiFetch( {
				path: addQueryArgs(
					'/osaurus-ai-connector/v1/models',
					{ base_url: currentValue }
				),
			} )
				.then( ( payload ) => {
					if ( cancelled ) {
						return;
					}
					const list = Array.isArray( payload?.models ) ? payload.models : [];
					setModels( list );
					setLatencyMs( Math.round( ( typeof performance !== 'undefined' && performance.now ? performance.now() : Date.now() ) - startedAt ) );
					setIsLoadingModels( false );
				} )
				.catch( ( err ) => {
					if ( cancelled ) {
						return;
					}
					setModels( [] );
					setLatencyMs( null );
					setModelsError(
						err?.message ||
							__( 'Could not reach Osaurus.', 'osaurus-ai-connector' )
					);
					setIsLoadingModels( false );
				} );
		}, 350 );

		return () => {
			cancelled = true;
			clearTimeout( handle );
		};
	}, [ isExpanded, currentValue ] );

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

	/**
	 * Inline status row rendered directly under the URL field.
	 *
	 * Reuses the model-fetch state machine so the same probe drives both
	 * "is Osaurus reachable" and "what models does it advertise" — they
	 * answer to the same single HTTP call and we render two views of it.
	 *
	 * Render states:
	 *   - no URL:   nothing (no point probing an empty field)
	 *   - loading:  spinner + "Testing connection…"
	 *   - error:    red dot + server-side error message
	 *   - empty OK: yellow dot + "Reachable, but no models advertised"
	 *   - ready:    green dot + "Reachable · N models · Xms"
	 */
	const dot = ( color ) =>
		h( 'span', {
			'aria-hidden': true,
			style: {
				display: 'inline-block',
				width: '8px',
				height: '8px',
				borderRadius: '50%',
				background: color,
				flex: '0 0 auto',
			},
		} );

	let statusRow = null;
	if ( currentValue ) {
		let dotEl;
		let label;
		if ( isLoadingModels ) {
			dotEl = h( Spinner, null );
			label = __( 'Testing connection…', 'osaurus-ai-connector' );
		} else if ( modelsError ) {
			dotEl = dot( '#D63638' );
			label = sprintf(
				/* translators: %s: error message returned by the server. */
				__( 'Unreachable — %s', 'osaurus-ai-connector' ),
				modelsError
			);
		} else if ( models.length === 0 ) {
			dotEl = dot( '#DBA617' );
			label = __(
				'Reachable, but no models advertised.',
				'osaurus-ai-connector'
			);
		} else {
			dotEl = dot( '#00A32A' );
			const latencyPart =
				latencyMs !== null
					? sprintf(
							/* translators: %d: round-trip latency in milliseconds. */
							__( ' · %dms', 'osaurus-ai-connector' ),
							latencyMs
					  )
					: '';
			label = sprintf(
				/* translators: 1: number of models advertised. 2: optional latency suffix. */
				__( 'Reachable · %1$d models%2$s', 'osaurus-ai-connector' ),
				models.length,
				latencyPart
			);
		}

		statusRow = h(
			HStack,
			{ justify: 'flex-start', spacing: 2, expanded: false },
			dotEl,
			h(
				'span',
				{
					style: {
						fontSize: '12px',
						color: modelsError ? '#D63638' : '#1D2327',
					},
				},
				label
			)
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

	/**
	 * Quick-pick presets covering the two most common setups.
	 *
	 * Users running WordPress bare-metal on the same Mac as Osaurus need
	 * `127.0.0.1`; users running WordPress inside Docker (wp-env, DDEV,
	 * Lando) need `host.docker.internal` so the container can reach the
	 * host. Other setups (LAN, remote) require a custom URL — surfacing
	 * the two common ones cuts setup friction without crowding the UI.
	 */
	const presets = [
		{
			label: __( 'Bare-metal (127.0.0.1)', 'osaurus-ai-connector' ),
			description: __(
				'WordPress and Osaurus on the same Mac (Studio, MAMP, Valet, Local, native PHP).',
				'osaurus-ai-connector'
			),
			url: 'http://127.0.0.1:1337/v1',
		},
		{
			label: __( 'Docker (host.docker.internal)', 'osaurus-ai-connector' ),
			description: __(
				'WordPress in Docker on the same Mac (wp-env, DDEV, Lando, Docker Desktop).',
				'osaurus-ai-connector'
			),
			url: 'http://host.docker.internal:1337/v1',
		},
	];

	const presetButtons = presets.map( ( preset ) =>
		h(
			Button,
			{
				key: preset.url,
				__next40pxDefaultSize: true,
				size: 'compact',
				variant: currentValue === preset.url ? 'primary' : 'secondary',
				disabled: isSaving,
				showTooltip: true,
				label: preset.description,
				onClick: () => edit( { [ BASE_URL_OPTION ]: preset.url } ),
			},
			preset.label
		)
	);

	const presetRow = h(
		VStack,
		{ spacing: 1 },
		h(
			'span',
			{
				style: {
					fontSize: '11px',
					fontWeight: 500,
					textTransform: 'uppercase',
					color: '#646970',
				},
			},
			__( 'Quick presets', 'osaurus-ai-connector' )
		),
		h( HStack, { justify: 'flex-start', spacing: 2 }, ...presetButtons )
	);

	/**
	 * Model selector block.
	 *
	 * Three visual states:
	 *   - loading:  spinner + "Fetching models…"
	 *   - error:    inline error notice with the server-side message
	 *   - loaded:   SelectControl with one option per advertised model
	 *
	 * The selector falls back to a single placeholder option when Osaurus
	 * advertises zero models, so the field still renders meaningfully and
	 * communicates "the server responded but has nothing to offer".
	 */
	let modelControl;
	if ( isLoadingModels ) {
		modelControl = h(
			HStack,
			{ justify: 'flex-start', spacing: 2, expanded: false },
			h( Spinner, null ),
			h(
				'span',
				{ style: { fontSize: '12px', color: '#646970' } },
				__( 'Fetching models from Osaurus…', 'osaurus-ai-connector' )
			)
		);
	} else if ( modelsError ) {
		modelControl = h(
			Notice,
			{ status: 'warning', isDismissible: false },
			modelsError
		);
	} else {
		const options = [
			{
				value: '',
				label: __( '— No default —', 'osaurus-ai-connector' ),
			},
			...models.map( ( id ) => ( { value: id, label: id } ) ),
		];
		modelControl = h( SelectControl, {
			__next40pxDefaultSize: true,
			__nextHasNoMarginBottom: true,
			label: __( 'Default model', 'osaurus-ai-connector' ),
			value: currentModel,
			options,
			disabled: isSaving || models.length === 0,
			onChange: ( next ) => edit( { [ DEFAULT_MODEL_OPTION ]: next } ),
			help:
				models.length === 0
					? __(
							'Osaurus responded but advertised no models. Load one into Osaurus first, then reopen this panel.',
							'osaurus-ai-connector'
					  )
					: __(
							'Optional. Used by consumer plugins as a fallback when no model is specified in the prompt.',
							'osaurus-ai-connector'
					  ),
		} );
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
		statusRow,
		presetRow,
		modelControl,
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
