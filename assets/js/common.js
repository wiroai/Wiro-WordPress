/**
 * Shared code for the Wiro AI scripts: settings from PHP, the job runner,
 * prompt helpers and the components every tool uses (model menu, price line,
 * voice menu, image tool). Exposed as window.wiroAi.
 */
( function ( wp ) {
	'use strict';

	const { __, sprintf } = wp.i18n;
	const apiFetch = wp.apiFetch;
	const { createElement: el, useEffect, useState } = wp.element;
	const { Button, Notice, SelectControl, Spinner, TextareaControl } =
		wp.components;

	const data = Object.assign(
		{
			hasKey: false,
			canGenerate: false,
			canManage: false,
			thumbnails: false,
			settingsUrl: '',
			defaults: {},
			ratios: [],
			defaultRatio: '16:9',
			promptTemplate:
				'A featured image for the article "{title}". {content} No text, letters or logos in the image.',
			narrationPosition: 'top',
			upscaleScale: '2',
			maxChars: 10000,
		},
		window.wiroAiData || {}
	);

	// Give up waiting after ten minutes. The task keeps running on Wiro AI.
	const TIMEOUT_MS = 10 * 60 * 1000;

	// The Wiro AI "W" mark.
	const icon = el(
		'svg',
		{
			xmlns: 'http://www.w3.org/2000/svg',
			viewBox: '0 0 1080 1080',
			width: 24,
			height: 24,
			'aria-hidden': true,
			focusable: false,
		},
		el( 'path', {
			fill: 'currentColor',
			d: 'M128 17q53-1 93 35a120 120 0 0 1 36 88v137l1 179v243l1-2 33-57a344 344 0 0 1 75-94q17-14 35-24l2-1c55-33 125-38 187-23q28 9 49 30l2 2 16 20 9 11 2 2 3 5 9 11 28 37 10 12 14 19 10 12 12 16 19 24 27 35 2 3 14 17 1 2 3 4V489l1-336c0-39 8-73 36-101 24-24 57-35 91-35a123 123 0 0 1 90 38q20 19 27 43l1 2c6 16 5 32 5 49l1 136v454q0 32-4 63l-1 2a322 322 0 0 1-34 102 242 242 0 0 1-60 74l-6 4a361 361 0 0 1-235 78l-10-12-6-8-2-3-10-13-14-19-10-12-48-63-10-12-14-19-10-12-48-63-10-12-25-32-1-2-2-2-2-2-2-3-1 2-34 60-2 3-4 7-9 16-4 7-83 143-25 42-2 5-3 4h-17c-89-5-182-39-244-106q-23-24-38-54l-1-2q-23-47-32-98v-4a416 416 0 0 1-4-88V146Q5 92 42 52q17-16 38-25l3-1q22-9 45-9',
		} ),
		el( 'path', {
			fill: 'currentColor',
			d: 'M128 17q53-1 93 35a120 120 0 0 1 36 96v780c0 37 5 70 32 98q24 22 56 30l-3 6c-87 3-182-32-246-91q-32-30-53-69l-1-2q-23-47-32-98v-4a416 416 0 0 1-4-88V146Q5 92 42 52q17-16 38-25l3-1q22-9 45-9',
		} ),
		el( 'path', {
			fill: 'currentColor',
			d: 'm638 526 2 2 2 2 15 18 1 2 10 12-1 2-6 2c-33 9-52 32-68 60l-5 10-30 52-9 16-4 7-10 16-12 21-16 29-12 21-44 76-60 104-45 78-18-4q-18-8-33-20l-3-2c-21-18-34-46-36-74v-24l-1-32v-2l-1-146c0-54 0-54 15-74l7-11 20-34 1-2c16-28 16-28 25-39l2-3 6-7 1-2a218 218 0 0 1 71-58l2-1c65-38 174-50 234 5',
		} ),
		el( 'path', {
			fill: 'currentColor',
			d: 'M667 563q12 13 22 27l37 48 17 21 21 28 10 12 14 19 10 12 12 16 3 3 1 1 3 4c8 9 7 17 7 28v140c0 41-5 76-35 106l-2 2q-25 22-57 28l-32-41-24-32-10-12-14-19-10-12-48-63-10-12-14-19-10-12-19-25-3-3-1-2-21-26-2-3-2-3v-4l4-8 2-3 2-4 6-10 10-17 3-6 2-4 21-35 2-4 26-45c18-31 18-31 29-43l2-2q20-20 48-26',
		} )
	);

	const styles = {
		stack: { display: 'grid', gap: '16px' },
		row: { display: 'flex', gap: '8px', flexWrap: 'wrap', alignItems: 'center' },
		status: { display: 'flex', alignItems: 'center', gap: '8px', margin: 0 },
		price: {
			margin: 0,
			fontSize: '12px',
			color: '#1e1e1e',
			whiteSpace: 'nowrap',
			overflow: 'hidden',
			textOverflow: 'ellipsis',
		},
		note: { color: '#757575' },
		image: {
			display: 'block',
			width: '100%',
			height: 'auto',
			borderRadius: '2px',
			marginBottom: '8px',
		},
		muted: { margin: 0, color: '#757575', fontSize: '12px' },
	};

	function sleep( ms ) {
		return new Promise( ( resolve ) => setTimeout( resolve, ms ) );
	}

	function stageLabel( stage ) {
		switch ( stage ) {
			case 'queued':
				return __( 'Waiting in the queue', 'wiro-ai' );
			case 'preparing':
				return __( 'Preparing the model', 'wiro-ai' );
			case 'generating':
				return __( 'Working on it', 'wiro-ai' );
			case 'finishing':
				return __( 'Finishing', 'wiro-ai' );
			case 'saving':
				return __( 'Saving to the Media Library', 'wiro-ai' );
			case 'done':
				return __( 'Done', 'wiro-ai' );
			default:
				return __( 'Working', 'wiro-ai' );
		}
	}

	function errorMessage( error ) {
		// REST validation errors carry the readable message per parameter.
		const params = error && error.data && error.data.params;
		if ( params && typeof params === 'object' ) {
			const first = Object.values( params ).find(
				( value ) => typeof value === 'string' && value
			);
			if ( first ) {
				return first;
			}
		}
		if ( error && typeof error.message === 'string' && error.message ) {
			return error.message;
		}
		return __( 'Something went wrong. Try again.', 'wiro-ai' );
	}

	/**
	 * Starts a job and waits until it is done.
	 *
	 * @param {Object}   payload  Request body for POST /wiro-ai/v1/jobs.
	 * @param {Function} onStatus Called with each status response.
	 * @return {Promise<Object>} The finished job.
	 */
	async function runJob( payload, onStatus ) {
		const report = typeof onStatus === 'function' ? onStatus : () => {};
		const started = await apiFetch( {
			path: '/wiro-ai/v1/jobs',
			method: 'POST',
			data: payload,
		} );
		if ( started.status === 'failed' ) {
			throw new Error( started.error || __( 'The job failed.', 'wiro-ai' ) );
		}
		report( started );
		if ( started.status === 'done' ) {
			return started;
		}

		const begin = Date.now();
		for ( ;; ) {
			const elapsed = Date.now() - begin;
			if ( elapsed > TIMEOUT_MS ) {
				throw new Error(
					__(
						'This is taking longer than usual. The result will show up in Wiro AI > Usage when it is ready.',
						'wiro-ai'
					)
				);
			}
			await sleep( elapsed < 60000 ? 2500 : 5000 );
			const job = await apiFetch( {
				path: '/wiro-ai/v1/jobs/' + encodeURIComponent( started.id ),
			} );
			if ( job.status === 'failed' ) {
				throw new Error( job.error || __( 'The job failed.', 'wiro-ai' ) );
			}
			report( job );
			if ( job.status === 'done' ) {
				return job;
			}
		}
	}

	function buildPrompt( template, title, content ) {
		return String( template || data.promptTemplate )
			.split( '{title}' )
			.join( String( title || '' ).trim() )
			.split( '{content}' )
			.join( String( content || '' ).trim() )
			.replace( /\s+/g, ' ' )
			.replace( /\s+([.,;:!?])/g, '$1' )
			.trim();
	}

	/**
	 * Plain text of some HTML. DOMParser builds an inert document, so nothing
	 * in the HTML runs or loads.
	 *
	 * @param {string} html     Post content.
	 * @param {number} maxWords Words to keep, or 0 for all.
	 * @return {string} Text.
	 */
	function textFromHtml( html, maxWords ) {
		const spaced = String( html || '' ).replace( />\s*</g, '> <' );
		const doc = new window.DOMParser().parseFromString( spaced, 'text/html' );
		const text = ( doc.body.textContent || '' ).replace( /\s+/g, ' ' ).trim();
		if ( ! maxWords ) {
			return text;
		}
		const words = text.split( ' ' ).filter( Boolean );
		return words.length > maxWords
			? words.slice( 0, maxWords ).join( ' ' ) + '...'
			: words.join( ' ' );
	}

	function formatUsd( amount ) {
		const value = Number( amount );
		if ( ! isFinite( value ) || value <= 0 ) {
			return '$0';
		}
		if ( value >= 1 ) {
			return '$' + value.toFixed( 2 );
		}
		if ( value >= 0.01 ) {
			return '$' + String( parseFloat( value.toFixed( 3 ) ) );
		}
		return '$' + String( parseFloat( value.toPrecision( 3 ) ) );
	}

	function formatCost( cost ) {
		const value = parseFloat( cost );
		return isFinite( value ) && value > 0 ? formatUsd( value ) : '';
	}

	function useElapsed( busy ) {
		const [ elapsed, setElapsed ] = useState( 0 );
		useEffect( () => {
			if ( ! busy ) {
				return undefined;
			}
			const start = Date.now();
			setElapsed( 0 );
			const timer = setInterval( () => {
				setElapsed( Math.round( ( Date.now() - start ) / 1000 ) );
			}, 1000 );
			return () => clearInterval( timer );
		}, [ busy ] );
		return elapsed;
	}

	// Model lists, fetched once per page.
	const modelLists = new Map();
	function fetchModels( task ) {
		if ( ! modelLists.has( task ) ) {
			const request = apiFetch( {
				path: '/wiro-ai/v1/models?task=' + encodeURIComponent( task ),
			} );
			request.catch( () => modelLists.delete( task ) );
			modelLists.set( task, request );
		}
		return modelLists.get( task );
	}

	function useModels( task ) {
		const [ state, setState ] = useState( {
			loading: true,
			recommended: [],
			all: [],
		} );
		useEffect( () => {
			let alive = true;
			fetchModels( task )
				.then( ( result ) => {
					if ( alive ) {
						setState( {
							loading: false,
							recommended: result.recommended || [],
							all: result.all || [],
						} );
					}
				} )
				.catch( () => {
					if ( alive ) {
						setState( { loading: false, recommended: [], all: [] } );
					}
				} );
			return () => {
				alive = false;
			};
		}, [ task ] );
		return state;
	}

	/**
	 * Model menu: the recommended models first, then the rest of the catalog,
	 * each as owner/slug with its price.
	 *
	 * @param {Object} props Task, value, onChange, disabled and label.
	 * @return {Object} Element.
	 */
	function ModelSelect( props ) {
		const models = useModels( props.task );
		const known = new Set();
		const option = ( entry ) => {
			known.add( entry.value );
			return el(
				'option',
				{ key: entry.value, value: entry.value },
				entry.label
			);
		};
		const groups = [];
		if ( models.recommended.length ) {
			groups.push(
				el(
					'optgroup',
					{ key: 'recommended', label: __( 'Recommended', 'wiro-ai' ) },
					models.recommended.map( option )
				)
			);
		}
		if ( models.all.length ) {
			groups.push(
				el(
					'optgroup',
					{ key: 'all', label: __( 'All in the catalog', 'wiro-ai' ) },
					models.all.map( option )
				)
			);
		}
		if ( props.value && ! known.has( props.value ) ) {
			groups.unshift(
				el( 'option', { key: 'current', value: props.value }, props.value )
			);
		}
		return el(
			SelectControl,
			{
				label: props.label || __( 'Model', 'wiro-ai' ),
				value: props.value,
				onChange: props.onChange,
				disabled: props.disabled,
				help: models.loading ? __( 'Loading models', 'wiro-ai' ) : undefined,
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
			},
			groups
		);
	}

	/**
	 * One line with the price of a planned request, from POST /quote.
	 *
	 * @param {Object} props task, model, ratio, voice, scale, chars, images, count.
	 * @return {Object} Element.
	 */
	function PriceLine( props ) {
		const [ quote, setQuote ] = useState( null );
		const [ failed, setFailed ] = useState( false );
		const { task, model, ratio, voice, scale, chars, images } = props;

		useEffect( () => {
			if ( ! model ) {
				return undefined;
			}
			let alive = true;
			const timer = setTimeout( () => {
				apiFetch( {
					path: '/wiro-ai/v1/quote',
					method: 'POST',
					data: {
						task,
						model,
						ratio: ratio || '',
						voice: voice || '',
						scale: scale || '',
						chars: chars || 0,
						images: images || 0,
					},
				} )
					.then( ( result ) => {
						if ( alive ) {
							setQuote( result );
							setFailed( false );
						}
					} )
					.catch( () => {
						if ( alive ) {
							setQuote( null );
							setFailed( true );
						}
					} );
			}, 350 );
			return () => {
				alive = false;
				clearTimeout( timer );
			};
		}, [ task, model, ratio, voice, scale, chars, images ] );

		if ( failed ) {
			return el(
				'p',
				{ style: styles.price },
				__( 'Price: see the model page on wiro.ai', 'wiro-ai' )
			);
		}
		if ( ! quote ) {
			return el( 'p', { style: styles.price }, __( 'Price: checking', 'wiro-ai' ) );
		}
		let label = quote.label;
		const count = props.count || 1;
		if ( count > 1 && quote.amount ) {
			label = sprintf(
				/* translators: 1: price of one request, 2: total price, 3: number of requests. */
				__( '%1$s each, about %2$s for %3$d', 'wiro-ai' ),
				quote.label,
				formatUsd( quote.amount * count ),
				count
			);
		}
		return el(
			'p',
			{ style: styles.price, title: quote.note || '' },
			sprintf(
				/* translators: %s: price such as $0.034. */
				__( 'Price: %s', 'wiro-ai' ),
				label
			),
			quote.note ? el( 'span', { style: styles.note }, ' · ' + quote.note ) : null
		);
	}

	// Voices of text-to-speech models, fetched once per model.
	const modelInfo = new Map();
	function useVoices( model ) {
		const [ info, setInfo ] = useState( { voices: [], default_voice: '' } );
		useEffect( () => {
			if ( ! model ) {
				return undefined;
			}
			let alive = true;
			if ( ! modelInfo.has( model ) ) {
				const request = apiFetch( {
					path:
						'/wiro-ai/v1/model?task=narration&model=' +
						encodeURIComponent( model ),
				} );
				request.catch( () => modelInfo.delete( model ) );
				modelInfo.set( model, request );
			}
			modelInfo
				.get( model )
				.then( ( result ) => {
					if ( alive ) {
						setInfo( result );
					}
				} )
				.catch( () => {
					if ( alive ) {
						setInfo( { voices: [], default_voice: '' } );
					}
				} );
			return () => {
				alive = false;
			};
		}, [ model ] );
		return info;
	}

	function StatusLine( props ) {
		if ( ! props.busy ) {
			return null;
		}
		return el(
			'p',
			{ style: styles.status, 'aria-live': 'polite' },
			el( Spinner, { style: { margin: 0 } } ),
			sprintf(
				/* translators: 1: progress such as "Working on it", 2: seconds since the start. */
				__( '%1$s, %2$d s', 'wiro-ai' ),
				stageLabel( props.stage ),
				props.elapsed
			)
		);
	}

	function KeyNotice() {
		return el(
			Notice,
			{ status: 'warning', isDismissible: false },
			data.canManage && data.settingsUrl
				? el(
						'span',
						null,
						__( 'Add your Wiro AI API key to start.', 'wiro-ai' ),
						' ',
						el(
							'a',
							{ href: data.settingsUrl },
							__( 'Open settings', 'wiro-ai' )
						)
				  )
				: __(
						'Ask a site administrator to add a Wiro AI API key in Wiro AI > Settings.',
						'wiro-ai'
				  )
		);
	}

	const IMAGE_TOOLS = {
		edit: {
			button: __( 'Edit image', 'wiro-ai' ),
			help: __(
				'Describe the change. The result is saved as a new image; the original stays in the Media Library.',
				'wiro-ai'
			),
		},
		upscale: {
			button: __( 'Upscale', 'wiro-ai' ),
			help: __(
				'Makes a larger, sharper copy. The original stays in the Media Library.',
				'wiro-ai'
			),
		},
		background: {
			button: __( 'Remove background', 'wiro-ai' ),
			help: __(
				'Makes a copy with a transparent background. The original stays in the Media Library.',
				'wiro-ai'
			),
		},
		alt: {
			button: __( 'Write alt text', 'wiro-ai' ),
			help: __(
				'A model that reads images describes this one in a short sentence.',
				'wiro-ai'
			),
		},
	};

	/**
	 * One tool for a Media Library image: edit, upscale, background or alt.
	 *
	 * @param {Object} props tool, attachmentId, title, purpose, onResult.
	 * @return {Object} Element.
	 */
	function ImageTool( props ) {
		const tool = props.tool;
		const [ model, setModel ] = useState( data.defaults[ tool ] || '' );
		const [ prompt, setPrompt ] = useState( '' );
		const [ scale, setScale ] = useState( data.upscaleScale || '2' );
		const [ busy, setBusy ] = useState( false );
		const [ stage, setStage ] = useState( '' );
		const [ error, setError ] = useState( '' );
		const [ result, setResult ] = useState( null );
		const elapsed = useElapsed( busy );

		if ( ! data.hasKey ) {
			return el( KeyNotice );
		}

		async function onRun() {
			if ( tool === 'edit' && ! prompt.trim() ) {
				setError( __( 'Describe the change first.', 'wiro-ai' ) );
				return;
			}
			setBusy( true );
			setError( '' );
			setResult( null );
			setStage( 'queued' );
			try {
				const job = await runJob(
					{
						task: tool,
						model,
						attachment_id: props.attachmentId,
						prompt: tool === 'edit' ? prompt.trim() : '',
						scale: tool === 'upscale' ? scale : '',
						title: props.title || '',
						purpose: props.purpose || 'media',
					},
					( status ) => setStage( status.stage )
				);
				setResult( job );
				if ( typeof props.onResult === 'function' ) {
					props.onResult( job );
				}
			} catch ( caught ) {
				setError( errorMessage( caught ) );
			} finally {
				setBusy( false );
				setStage( '' );
			}
		}

		const children = [
			el( 'p', { key: 'help', style: styles.muted }, IMAGE_TOOLS[ tool ].help ),
		];
		if ( tool === 'edit' ) {
			children.push(
				el( TextareaControl, {
					key: 'prompt',
					label: __( 'What should change?', 'wiro-ai' ),
					value: prompt,
					onChange: setPrompt,
					rows: 3,
					placeholder: __(
						'For example: make it a rainy evening with warm street lights',
						'wiro-ai'
					),
					disabled: busy,
					__nextHasNoMarginBottom: true,
				} )
			);
		}
		children.push(
			el( ModelSelect, {
				key: 'model',
				task: tool,
				value: model,
				onChange: setModel,
				disabled: busy,
			} )
		);
		if ( tool === 'upscale' ) {
			children.push(
				el( SelectControl, {
					key: 'scale',
					label: __( 'Factor', 'wiro-ai' ),
					value: scale,
					options: [
						{ value: '2', label: '2x' },
						{ value: '4', label: '4x' },
					],
					onChange: setScale,
					disabled: busy,
					help: __( 'Used by models that take a factor.', 'wiro-ai' ),
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
				} )
			);
		}
		children.push(
			el( PriceLine, {
				key: 'price',
				task: tool,
				model,
				scale: tool === 'upscale' ? scale : '',
				images: tool === 'alt' ? 1 : 0,
			} ),
			el(
				'div',
				{ key: 'run', style: styles.row },
				el(
					Button,
					{
						variant: 'primary',
						onClick: onRun,
						isBusy: busy,
						disabled: busy,
						__next40pxDefaultSize: true,
					},
					IMAGE_TOOLS[ tool ].button
				)
			),
			el( StatusLine, { key: 'status', busy, stage, elapsed } )
		);
		if ( error ) {
			children.push(
				el(
					Notice,
					{
						key: 'error',
						status: 'error',
						isDismissible: true,
						onRemove: () => setError( '' ),
					},
					error
				)
			);
		}
		if ( result && result.attachment && props.showResult !== false ) {
			children.push(
				el(
					'div',
					{ key: 'result' },
					el( 'img', {
						src: result.attachment.thumb_url || result.attachment.url,
						alt: result.attachment.alt || '',
						style: styles.image,
					} ),
					el(
						'p',
						{ style: styles.muted },
						__( 'Saved to the Media Library as a new image.', 'wiro-ai' ),
						formatCost( result.cost )
							? ' ' +
									sprintf(
										/* translators: %s: cost such as $0.034. */
										__( 'Cost: %s.', 'wiro-ai' ),
										formatCost( result.cost )
									)
							: '',
						' ',
						result.attachment.edit_url
							? el(
									'a',
									{ href: result.attachment.edit_url },
									__( 'Open the new image', 'wiro-ai' )
							  )
							: null
					)
				)
			);
		}
		if ( result && result.result && result.result.alt && props.showResult !== false ) {
			children.push(
				el(
					'p',
					{ key: 'alt', style: styles.muted },
					sprintf(
						/* translators: 1: alt text, 2: cost such as $0.0004. */
						__( 'Alt text: "%1$s" Cost: %2$s.', 'wiro-ai' ),
						result.result.alt,
						formatCost( result.cost ) || '$0'
					)
				)
			);
		}
		return el( 'div', { style: styles.stack }, children );
	}

	window.wiroAi = {
		data,
		icon,
		styles,
		runJob,
		stageLabel,
		errorMessage,
		buildPrompt,
		textFromHtml,
		formatUsd,
		formatCost,
		useElapsed,
		useVoices,
		components: {
			ModelSelect,
			PriceLine,
			StatusLine,
			KeyNotice,
			ImageTool,
		},
	};
} )( window.wp );
