/**
 * Wiro AI Image block. It asks for a prompt, generates the image and then
 * replaces itself with a core Image block, so posts keep their images even
 * if the plugin is turned off.
 */
( function ( wp ) {
	'use strict';

	const { registerBlockType, createBlock } = wp.blocks;
	const { useBlockProps } = wp.blockEditor;
	const { Button, Notice, Placeholder, SelectControl, Spinner, TextareaControl } =
		wp.components;
	const { createElement: el, useEffect, useRef, useState } = wp.element;
	const { select, dispatch } = wp.data;
	const { __, sprintf } = wp.i18n;

	const wiro = window.wiroAi;
	const data = wiro.data;

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

	function currentPostId() {
		const editor = select( 'core/editor' );
		const id =
			editor && editor.getCurrentPostId ? editor.getCurrentPostId() : 0;
		// The site editor uses string IDs for templates; those get no post.
		return typeof id === 'number' && id > 0 ? id : 0;
	}

	function Edit( { attributes, setAttributes, clientId } ) {
		const blockProps = useBlockProps();
		const [ busy, setBusy ] = useState( false );
		const [ stage, setStage ] = useState( '' );
		const [ elapsed, setElapsed ] = useState( 0 );
		const [ error, setError ] = useState( '' );
		const mounted = useRef( true );

		const model = attributes.model || data.defaultModel;
		const ratio = attributes.ratio || data.defaultRatio;

		useEffect( () => {
			mounted.current = true;
			return () => {
				mounted.current = false;
			};
		}, [] );

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

		async function onGenerate() {
			const prompt = ( attributes.prompt || '' ).trim();
			if ( ! prompt ) {
				setError( __( 'Write a prompt first.', 'wiro-ai' ) );
				return;
			}
			setBusy( true );
			setError( '' );
			setStage( 'queued' );
			try {
				const task = await wiro.generate( {
					prompt,
					model,
					ratio,
					postId: currentPostId(),
					title: '',
					purpose: 'block',
					onStatus: ( status ) => {
						if ( mounted.current ) {
							setStage( status.stage );
						}
					},
				} );
				const image = task.attachment;
				if ( select( 'core/block-editor' ).getBlock( clientId ) ) {
					dispatch( 'core/block-editor' ).replaceBlocks(
						clientId,
						createBlock( 'core/image', {
							id: image.id,
							url: image.large_url || image.url,
							alt: image.alt || '',
							sizeSlug: image.large_url ? 'large' : 'full',
							linkDestination: 'none',
						} )
					);
				} else {
					dispatch( 'core/notices' ).createSuccessNotice(
						__( 'The Wiro AI image is ready in the Media Library.', 'wiro-ai' ),
						{ type: 'snackbar' }
					);
				}
			} catch ( caught ) {
				const message = wiro.errorMessage( caught );
				if ( mounted.current ) {
					setError( message );
				} else {
					dispatch( 'core/notices' ).createErrorNotice( message, {
						type: 'snackbar',
					} );
				}
			} finally {
				if ( mounted.current ) {
					setBusy( false );
					setStage( '' );
				}
			}
		}

		let body;
		if ( ! data.canGenerate ) {
			body = el(
				Notice,
				{ status: 'warning', isDismissible: false },
				__( 'You are not allowed to generate images on this site.', 'wiro-ai' )
			);
		} else if ( ! data.hasKey ) {
			body = el(
				Notice,
				{ status: 'warning', isDismissible: false },
				data.canManage
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
							'Ask a site administrator to add a Wiro AI API key in Settings, Wiro AI.',
							'wiro-ai'
					  )
			);
		} else {
			body = el(
				'div',
				{ style: { display: 'grid', gap: '12px', width: '100%' } },
				el( TextareaControl, {
					label: __( 'Prompt', 'wiro-ai' ),
					value: attributes.prompt,
					onChange: ( value ) => setAttributes( { prompt: value } ),
					rows: 3,
					placeholder: __(
						'A watercolor map of a small harbor town at sunrise',
						'wiro-ai'
					),
					disabled: busy,
					__nextHasNoMarginBottom: true,
				} ),
				el(
					'div',
					{ style: { display: 'flex', gap: '12px', flexWrap: 'wrap' } },
					el(
						'div',
						{ style: { flex: '1 1 200px' } },
						el( SelectControl, {
							label: __( 'Model', 'wiro-ai' ),
							value: model,
							options: data.models,
							onChange: ( value ) => setAttributes( { model: value } ),
							disabled: busy,
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
						} )
					),
					el(
						'div',
						{ style: { flex: '1 1 160px' } },
						el( SelectControl, {
							label: __( 'Aspect ratio', 'wiro-ai' ),
							value: ratio,
							options: data.ratios,
							onChange: ( value ) => setAttributes( { ratio: value } ),
							disabled: busy,
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
						} )
					)
				),
				el(
					'div',
					{
						style: {
							display: 'flex',
							alignItems: 'center',
							gap: '12px',
							flexWrap: 'wrap',
						},
					},
					el(
						Button,
						{
							variant: 'primary',
							onClick: onGenerate,
							isBusy: busy,
							disabled: busy,
							__next40pxDefaultSize: true,
						},
						busy
							? __( 'Generating', 'wiro-ai' )
							: __( 'Generate image', 'wiro-ai' )
					),
					busy &&
						el(
							'span',
							{
								style: { display: 'inline-flex', alignItems: 'center', gap: '8px' },
								'aria-live': 'polite',
							},
							el( Spinner, { style: { margin: 0 } } ),
							sprintf(
								/* translators: 1: progress such as "Generating the image", 2: seconds since the start. */
								__( '%1$s, %2$d s', 'wiro-ai' ),
								wiro.stageLabel( stage ),
								elapsed
							)
						)
				),
				error &&
					el(
						Notice,
						{
							status: 'error',
							isDismissible: true,
							onRemove: () => setError( '' ),
						},
						error
					)
			);
		}

		return el(
			'div',
			blockProps,
			el(
				Placeholder,
				{
					icon,
					label: __( 'Wiro AI Image', 'wiro-ai' ),
					instructions: __(
						'Describe the image. When it is ready, it is saved to the Media Library and this block becomes an Image block.',
						'wiro-ai'
					),
				},
				body
			)
		);
	}

	registerBlockType( 'wiro-ai/image', {
		icon,
		edit: Edit,
		save: () => null,
	} );
} )( window.wp );
