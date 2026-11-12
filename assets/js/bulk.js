/**
 * Bulk featured images: pick posts without a featured image, check the
 * price, confirm, and a queue makes an image for each one, two at a time.
 */
( function ( wp ) {
	'use strict';

	const { createElement: el, createRoot, useRef, useState } = wp.element;
	const { __, _n, sprintf } = wp.i18n;
	const { Button, SelectControl, Spinner } = wp.components;

	const wiro = window.wiroAi;
	const data = wiro.data;
	const { ModelSelect, PriceLine, KeyNotice } = wiro.components;

	const CONCURRENCY = 2;

	function BulkApp( props ) {
		const posts = props.posts;
		const [ selected, setSelected ] = useState( () => posts.map( ( post ) => post.id ) );
		const [ model, setModel ] = useState( data.defaults.image || '' );
		const [ ratio, setRatio ] = useState( data.defaultRatio );
		const [ source, setSource ] = useState( 'title' );
		const [ running, setRunning ] = useState( false );
		const [ rows, setRows ] = useState( {} );
		const [ spent, setSpent ] = useState( 0 );
		const stop = useRef( false );

		if ( ! data.hasKey ) {
			return el( KeyNotice );
		}
		if ( ! posts.length ) {
			return el( 'p', null, __( 'Every post on this page has a featured image.', 'wiro-ai' ) );
		}

		function setRow( id, value ) {
			setRows( ( previous ) =>
				Object.assign( {}, previous, { [ id ]: Object.assign( {}, previous[ id ], value ) } )
			);
		}

		function toggle( id, checked ) {
			setSelected( ( previous ) =>
				checked ? previous.concat( id ) : previous.filter( ( item ) => item !== id )
			);
		}

		async function worker( queue ) {
			while ( queue.length && ! stop.current ) {
				const post = queue.shift();
				setRow( post.id, { state: 'running', stage: 'queued', error: '' } );
				try {
					const job = await wiro.runJob(
						{
							task: 'image',
							model,
							ratio,
							source,
							post_id: post.id,
							purpose: 'bulk',
							apply: true,
						},
						( status ) => setRow( post.id, { state: 'running', stage: status.stage } )
					);
					const cost = parseFloat( job.cost ) || 0;
					setSpent( ( total ) => total + cost );
					setRow( post.id, {
						state: 'done',
						thumb: job.attachment.thumb_url || job.attachment.url,
						cost: job.cost,
					} );
				} catch ( caught ) {
					setRow( post.id, { state: 'failed', error: wiro.errorMessage( caught ) } );
				}
			}
		}

		async function start() {
			const queue = posts.filter(
				( post ) =>
					selected.includes( post.id ) &&
					( ! rows[ post.id ] || rows[ post.id ].state !== 'done' )
			);
			if ( ! queue.length ) {
				return;
			}
			const question = sprintf(
				/* translators: 1: number of images, 2: model slug. */
				_n(
					'Generate %1$d featured image with %2$s? Check the price line before you go on.',
					'Generate %1$d featured images with %2$s? Check the price line before you go on.',
					queue.length,
					'wiro-ai'
				),
				queue.length,
				model
			);
			// An explicit yes before spending money.
			if ( ! window.confirm( question ) ) {
				return;
			}
			stop.current = false;
			setRunning( true );
			const workers = [];
			for ( let index = 0; index < Math.min( CONCURRENCY, queue.length ); index++ ) {
				workers.push( worker( queue ) );
			}
			await Promise.all( workers );
			setRunning( false );
		}

		const counts = { done: 0, failed: 0 };
		Object.keys( rows ).forEach( ( id ) => {
			if ( rows[ id ].state === 'done' ) {
				counts.done++;
			} else if ( rows[ id ].state === 'failed' ) {
				counts.failed++;
			}
		} );
		const pending = posts.filter(
			( post ) => selected.includes( post.id ) && ( ! rows[ post.id ] || rows[ post.id ].state !== 'done' )
		).length;

		function statusCell( post ) {
			const row = rows[ post.id ];
			if ( ! row ) {
				return el( 'span', { className: 'wiro-ai-muted' }, __( 'Waiting', 'wiro-ai' ) );
			}
			if ( row.state === 'running' ) {
				return el(
					'span',
					{ className: 'wiro-ai-running' },
					el( Spinner, { style: { margin: '0 6px 0 0' } } ),
					wiro.stageLabel( row.stage )
				);
			}
			if ( row.state === 'done' ) {
				return el(
					'span',
					{ className: 'wiro-ai-done' },
					row.thumb ? el( 'img', { src: row.thumb, alt: '', className: 'wiro-ai-thumb' } ) : null,
					__( 'Set as featured image', 'wiro-ai' ),
					wiro.formatCost( row.cost ) ? ' · ' + wiro.formatCost( row.cost ) : ''
				);
			}
			return el( 'span', { className: 'wiro-ai-failed' }, row.error );
		}

		return el(
			'div',
			{ className: 'wiro-ai-bulk' },
			el(
				'div',
				{ className: 'wiro-ai-bulk-controls' },
				el( ModelSelect, { task: 'image', value: model, onChange: setModel, disabled: running } ),
				el( SelectControl, {
					label: __( 'Aspect ratio', 'wiro-ai' ),
					value: ratio,
					options: data.ratios,
					onChange: setRatio,
					disabled: running,
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
				} ),
				el( SelectControl, {
					label: __( 'Prompt from', 'wiro-ai' ),
					value: source,
					options: [
						{ value: 'title', label: __( 'The post title', 'wiro-ai' ) },
						{ value: 'content', label: __( 'The title and the first words', 'wiro-ai' ) },
					],
					onChange: setSource,
					disabled: running,
					help: __( 'The prompt template in the settings shapes the prompt.', 'wiro-ai' ),
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
				} )
			),
			el( PriceLine, { task: 'image', model, ratio, count: pending } ),
			el(
				'div',
				{ className: 'wiro-ai-bulk-actions' },
				el(
					Button,
					{ variant: 'primary', onClick: start, disabled: running || pending === 0, isBusy: running },
					sprintf(
						/* translators: %d: number of images. */
						_n( 'Generate %d image', 'Generate %d images', pending, 'wiro-ai' ),
						pending
					)
				),
				running &&
					el(
						Button,
						{
							variant: 'secondary',
							onClick: () => {
								stop.current = true;
							},
						},
						__( 'Stop after the current images', 'wiro-ai' )
					),
				el(
					'span',
					{ className: 'wiro-ai-muted' },
					sprintf(
						/* translators: 1: images done, 2: images failed, 3: amount spent. */
						__( 'Done: %1$d. Failed: %2$d. Spent: %3$s.', 'wiro-ai' ),
						counts.done,
						counts.failed,
						wiro.formatUsd( spent )
					)
				)
			),
			el(
				'table',
				{ className: 'widefat striped wiro-ai-bulk-table' },
				el(
					'thead',
					null,
					el(
						'tr',
						null,
						el(
							'td',
							{ className: 'manage-column check-column' },
							el( 'input', {
								type: 'checkbox',
								checked: selected.length === posts.length,
								onChange: ( event ) =>
									setSelected( event.target.checked ? posts.map( ( post ) => post.id ) : [] ),
								disabled: running,
								'aria-label': __( 'Select all', 'wiro-ai' ),
							} )
						),
						el( 'th', null, __( 'Post', 'wiro-ai' ) ),
						el( 'th', null, __( 'Status', 'wiro-ai' ) ),
						el( 'th', null, __( 'Result', 'wiro-ai' ) )
					)
				),
				el(
					'tbody',
					null,
					posts.map( ( post ) =>
						el(
							'tr',
							{ key: post.id },
							el(
								'th',
								{ className: 'check-column', scope: 'row' },
								el( 'input', {
									type: 'checkbox',
									checked: selected.includes( post.id ),
									onChange: ( event ) => toggle( post.id, event.target.checked ),
									disabled: running,
									'aria-label': post.title,
								} )
							),
							el( 'td', null, el( 'a', { href: post.edit }, post.title ) ),
							el( 'td', null, post.status ),
							el( 'td', null, statusCell( post ) )
						)
					)
				)
			)
		);
	}

	wp.domReady( () => {
		const root = document.getElementById( 'wiro-ai-bulk-root' );
		if ( ! root ) {
			return;
		}
		let posts;
		try {
			posts = JSON.parse( root.getAttribute( 'data-posts' ) || '[]' );
		} catch {
			posts = [];
		}
		createRoot( root ).render( el( BulkApp, { posts } ) );
	} );
} )( window.wp );
