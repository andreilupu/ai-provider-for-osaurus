/**
 * AI Provider for Osaurus — admin script module.
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
 * declared in `ai-provider-for-osaurus.php`.
 */
const BASE_URL_OPTION = 'osaurus_ai_connector_base_url';

/**
 * Option name used to persist the user-selected default model. Must match
 * `DEFAULT_MODEL_OPTION` declared in `ai-provider-for-osaurus.php`.
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

	// Now reading the current time for latency measurement.
	const now = () =>
		typeof performance !== 'undefined' && performance.now
			? performance.now()
			: Date.now();

	// Health state — drives the status row directly under the URL field.
	// We probe `/health` (server root) instead of `/v1/models` because it is
	// the lighter authoritative reachability check and exposes the currently
	// loaded model so the row can describe what Osaurus is actually serving.
	const [ health, setHealth ] = useState( null );
	const [ healthError, setHealthError ] = useState( '' );
	const [ isProbingHealth, setIsProbingHealth ] = useState( false );
	const [ latencyMs, setLatencyMs ] = useState( null );

	// Models fetched from the server-side REST proxy for the default-model
	// dropdown. Kept as local state because the result depends on what
	// Osaurus advertises right now, not on any persisted WordPress data.
	const [ models, setModels ] = useState( [] );
	const [ modelsError, setModelsError ] = useState( '' );
	const [ isLoadingModels, setIsLoadingModels ] = useState( false );

	// Two effects, two probes, run in parallel: `/health` answers "is the
	// server up?" fast, `/v1/models` populates the dropdown. Both debounce
	// on URL change so a keystroke storm does not flood the REST proxy.

	useEffect( () => {
		if ( ! isExpanded || ! currentValue ) {
			return;
		}

		let cancelled = false;
		const handle = setTimeout( () => {
			setIsProbingHealth( true );
			setHealthError( '' );
			const startedAt = now();

			apiFetch( {
				path: addQueryArgs( '/ai-provider-for-osaurus/v1/health', {
					base_url: currentValue,
				} ),
			} )
				.then( ( payload ) => {
					if ( cancelled ) {
						return;
					}
					setHealth( payload || null );
					setLatencyMs( Math.round( now() - startedAt ) );
					setIsProbingHealth( false );
				} )
				.catch( ( err ) => {
					if ( cancelled ) {
						return;
					}
					setHealth( null );
					setLatencyMs( null );
					setHealthError(
						err?.message ||
							__( 'Could not reach Osaurus.', 'ai-provider-for-osaurus' )
					);
					setIsProbingHealth( false );
				} );
		}, 350 );

		return () => {
			cancelled = true;
			clearTimeout( handle );
		};
	}, [ isExpanded, currentValue ] );

	useEffect( () => {
		if ( ! isExpanded || ! currentValue ) {
			return;
		}

		let cancelled = false;
		const handle = setTimeout( () => {
			setIsLoadingModels( true );
			setModelsError( '' );

			apiFetch( {
				path: addQueryArgs( '/ai-provider-for-osaurus/v1/models', {
					base_url: currentValue,
				} ),
			} )
				.then( ( payload ) => {
					if ( cancelled ) {
						return;
					}
					const list = Array.isArray( payload?.models )
						? payload.models
						: [];
					setModels( list );
					setIsLoadingModels( false );
				} )
				.catch( ( err ) => {
					if ( cancelled ) {
						return;
					}
					setModels( [] );
					setModelsError(
						err?.message ||
							__( 'Could not reach Osaurus.', 'ai-provider-for-osaurus' )
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
		const status = health?.status || '';
		const loaded = Array.isArray( health?.loaded ) ? health.loaded : [];
		const currentlyLoadedModel =
			( typeof health?.current_model === 'string' && health.current_model ) ||
			( loaded.length > 0 ? loaded[ 0 ] : '' );

		let dotEl;
		let label;
		if ( isProbingHealth ) {
			dotEl = h( Spinner, null );
			label = __( 'Testing connection…', 'ai-provider-for-osaurus' );
		} else if ( healthError ) {
			dotEl = dot( '#D63638' );
			// Two-line label: the raw server-side error stays on top so power
			// users see exactly what failed (cURL message, HTTP code, etc.),
			// and a friendlier hint follows underneath linking to the Osaurus
			// download page. Most "unreachable" reports we will get are not
			// network bugs — they are "the app isn't running yet" — so the
			// onboarding nudge does more work than a longer error.
			label = h(
				VStack,
				{ spacing: 0 },
				h(
					'span',
					null,
					sprintf(
						/* translators: %s: error message returned by the server. */
						__( 'Unreachable — %s', 'ai-provider-for-osaurus' ),
						healthError
					)
				),
				h(
					'span',
					{ style: { color: '#646970' } },
					__(
						'Have you installed Osaurus and started the app? Download it at ',
						'ai-provider-for-osaurus'
					),
					h(
						'a',
						{
							href: 'https://osaurus.ai',
							target: '_blank',
							rel: 'noreferrer noopener',
						},
						'osaurus.ai'
					),
					'.'
				)
			);
		} else if ( health ) {
			const latencyPart =
				latencyMs !== null
					? sprintf(
							/* translators: %d: round-trip latency in milliseconds. */
							__( ' · %dms', 'ai-provider-for-osaurus' ),
							latencyMs
					  )
					: '';

			const isHealthy = status === 'healthy';
			dotEl = dot( isHealthy ? '#00A32A' : '#DBA617' );

			if ( currentlyLoadedModel ) {
				label = sprintf(
					/* translators: 1: health status string. 2: currently loaded model ID. 3: optional latency suffix. */
					__(
						'%1$s · loaded: %2$s%3$s',
						'ai-provider-for-osaurus'
					),
					status || __( 'reachable', 'ai-provider-for-osaurus' ),
					currentlyLoadedModel,
					latencyPart
				);
			} else {
				label = sprintf(
					/* translators: 1: health status string. 2: optional latency suffix. */
					__(
						'%1$s · no model loaded%2$s',
						'ai-provider-for-osaurus'
					),
					status || __( 'reachable', 'ai-provider-for-osaurus' ),
					latencyPart
				);
			}
		}

		if ( dotEl ) {
			// `<div>` here, not `<span>`, because the error label nests a
			// `VStack` (which renders a div) for the two-line hint, and
			// `<div>` inside `<span>` is invalid HTML. Display-flex via the
			// HStack handles inline placement next to the dot regardless.
			statusRow = h(
				HStack,
				{ justify: 'flex-start', spacing: 2, expanded: false },
				dotEl,
				h(
					'div',
					{
						style: {
							fontSize: '12px',
							color: healthError ? '#D63638' : '#1D2327',
						},
					},
					label
				)
			);
		}
	}

	const urlField = h( TextControl, {
		__next40pxDefaultSize: true,
		__nextHasNoMarginBottom: true,
		type: 'url',
		label: __( 'Server URL', 'ai-provider-for-osaurus' ),
		value: currentValue,
		onChange: ( next ) => edit( { [ BASE_URL_OPTION ]: next } ),
		placeholder: 'http://127.0.0.1:1337/v1',
		disabled: isSaving,
		help: __(
			'Base URL of your local Osaurus server, including the /v1 path.',
			'ai-provider-for-osaurus'
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
			label: __( 'Bare-metal (127.0.0.1)', 'ai-provider-for-osaurus' ),
			description: __(
				'WordPress and Osaurus on the same Mac (Studio, MAMP, Valet, Local, native PHP).',
				'ai-provider-for-osaurus'
			),
			url: 'http://127.0.0.1:1337/v1',
		},
		{
			label: __( 'Docker (host.docker.internal)', 'ai-provider-for-osaurus' ),
			description: __(
				'WordPress in Docker on the same Mac (wp-env, DDEV, Lando, Docker Desktop).',
				'ai-provider-for-osaurus'
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
			__( 'Quick presets', 'ai-provider-for-osaurus' )
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
				__( 'Fetching models from Osaurus…', 'ai-provider-for-osaurus' )
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
				label: __( '— No default —', 'ai-provider-for-osaurus' ),
			},
			...models.map( ( id ) => ( { value: id, label: id } ) ),
		];
		modelControl = h( SelectControl, {
			__next40pxDefaultSize: true,
			__nextHasNoMarginBottom: true,
			label: __( 'Default model', 'ai-provider-for-osaurus' ),
			value: currentModel,
			options,
			disabled: isSaving || models.length === 0,
			onChange: ( next ) => edit( { [ DEFAULT_MODEL_OPTION ]: next } ),
			help:
				models.length === 0
					? __(
							'Osaurus responded but advertised no models. Load one into Osaurus first, then reopen this panel.',
							'ai-provider-for-osaurus'
					  )
					: __(
							'Optional. Used by consumer plugins as a fallback when no model is specified in the prompt.',
							'ai-provider-for-osaurus'
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
		__( 'Save', 'ai-provider-for-osaurus' )
	);

	return h(
		VStack,
		{ spacing: 4, className: 'ai-provider-for-osaurus-settings' },
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
