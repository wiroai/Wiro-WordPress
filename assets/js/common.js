/**
 * Shared code for the Wiro AI editor scripts: settings from PHP, the
 * generate-and-wait loop and prompt helpers. Exposed as window.wiroAi.
 */
( function ( wp ) {
	'use strict';

	const { __ } = wp.i18n;
	const apiFetch = wp.apiFetch;

	const data = Object.assign(
		{
			hasKey: false,
			canGenerate: false,
			canManage: false,
			settingsUrl: '',
			models: [],
			defaultModel: '',
			ratios: [],
			defaultRatio: '16:9',
			promptTemplate:
				'A featured image for the article "{title}". {content} No text, letters or logos in the image.',
		},
		window.wiroAiData || {}
	);

	// Give up waiting after six minutes. The image keeps rendering on Wiro AI.
	const TIMEOUT_MS = 6 * 60 * 1000;

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
				return __( 'Generating the image', 'wiro-ai' );
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
	 * Starts an image and waits until it is in the Media Library.
	 *
	 * @param {Object}   options          Request options.
	 * @param {string}   options.prompt   Prompt.
	 * @param {string}   options.model    Model slug.
	 * @param {string}   options.ratio    Aspect ratio such as "16:9".
	 * @param {number}   options.postId   Post the image belongs to, or 0.
	 * @param {string}   options.title    Post title, used for the file name and alt text.
	 * @param {string}   options.purpose  "featured" or "block".
	 * @param {Function} options.onStatus Called with each status response.
	 * @return {Promise<Object>} The finished task, with its attachment.
	 */
	async function generate( options ) {
		const onStatus =
			typeof options.onStatus === 'function'
				? options.onStatus
				: () => {};
		const started = await apiFetch( {
			path: '/wiro-ai/v1/tasks',
			method: 'POST',
			data: {
				prompt: options.prompt,
				model: options.model || '',
				ratio: options.ratio || '',
				post_id: options.postId || 0,
				title: options.title || '',
				purpose: options.purpose || 'featured',
			},
		} );
		onStatus( started );

		const begin = Date.now();
		for ( ;; ) {
			const elapsed = Date.now() - begin;
			if ( elapsed > TIMEOUT_MS ) {
				throw new Error(
					__(
						'The image is taking longer than usual. Try again in a few minutes, or pick a faster model.',
						'wiro-ai'
					)
				);
			}
			await sleep( elapsed < 60000 ? 2500 : 5000 );
			const task = await apiFetch( {
				path: '/wiro-ai/v1/tasks/' + encodeURIComponent( started.id ),
			} );
			if ( task.status === 'failed' ) {
				throw new Error(
					task.error || __( 'The image could not be made.', 'wiro-ai' )
				);
			}
			onStatus( task );
			if ( task.status === 'done' ) {
				return task;
			}
		}
	}

	/**
	 * Fills the prompt template.
	 *
	 * @param {string} template Template with {title} and {content}.
	 * @param {string} title    Post title.
	 * @param {string} content  First words of the post, or an empty string.
	 * @return {string} Prompt.
	 */
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
	 * Plain text of the first words of some HTML. DOMParser builds an inert
	 * document, so nothing in the HTML runs or loads.
	 *
	 * @param {string} html     Post content.
	 * @param {number} maxWords Number of words to keep.
	 * @return {string} Text.
	 */
	function textFromHtml( html, maxWords ) {
		const spaced = String( html || '' ).replace( />\s*</g, '> <' );
		const doc = new window.DOMParser().parseFromString( spaced, 'text/html' );
		const words = ( doc.body.textContent || '' )
			.replace( /\s+/g, ' ' )
			.trim()
			.split( ' ' )
			.filter( Boolean );
		if ( words.length > maxWords ) {
			return words.slice( 0, maxWords ).join( ' ' ) + '...';
		}
		return words.join( ' ' );
	}

	/**
	 * Cost in US dollars as text, such as "$0.034".
	 *
	 * @param {string|number} cost Cost from the task.
	 * @return {string} Formatted cost, or an empty string.
	 */
	function formatCost( cost ) {
		const value = parseFloat( cost );
		if ( ! isFinite( value ) || value <= 0 ) {
			return '';
		}
		return '$' + String( parseFloat( value.toFixed( 4 ) ) );
	}

	window.wiroAi = {
		data,
		generate,
		stageLabel,
		errorMessage,
		buildPrompt,
		textFromHtml,
		formatCost,
	};
} )( window.wp );
