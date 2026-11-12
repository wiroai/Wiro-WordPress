/**
 * Wiro AI Image block. It asks for a prompt, generates the image and then
 * replaces itself with a core Image block, so posts keep their images even
 * if the plugin is turned off.
 */
( function ( wp ) {
	'use strict';

	const { registerBlockType, createBlock } = wp.blocks;
	const { useBlockProps } = wp.blockEditor;
	const { Button, Notice, Placeholder, SelectControl, TextareaControl } = wp.components;
	const { createElement: el, useEffect, useRef, useState } = wp.element;
	const { select, dispatch } = wp.data;
	const { __ } = wp.i18n;

	const wiro = window.wiroAi;
	const data = wiro.data;
	const { ModelSelect, PriceLine, StatusLine, KeyNotice } = wiro.components;

	function currentPostId() {
		const editor = select( 'core/editor' );
		const id = editor && editor.getCurrentPostId ? editor.getCurrentPostId() : 0;
		// The site editor uses string IDs for templates; those get no post.
		return typeof id === 'number' && id > 0 ? id : 0;
	}

	function Edit( { attributes, setAttributes, clientId } ) {
		const blockProps = useBlockProps();
		const [ busy, setBusy ] = useState( false );
		const [ stage, setStage ] = useState( '' );
		const [ error, setError ] = useState( '' );
		const mounted = useRef( true );
		const elapsed = wiro.useElapsed( busy );

		const model = attributes.model || data.defaults.image || '';
		const ratio = attributes.ratio || data.defaultRatio;

		useEffect( () => {
			mounted.current = true;
			return () => {
				mounted.current = false;
			};
		}, [] );

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
				const job = await wiro.runJob(
					{
						task: 'image',
						model,
						ratio,
						prompt,
						post_id: currentPostId(),
						purpose: 'block',
					},
					( status ) => mounted.current && setStage( status.stage )
				);
				const image = job.attachment;
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
					dispatch( 'core/notices' ).createErrorNotice( message, { type: 'snackbar' } );
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
				__( 'You are not allowed to use Wiro AI on this site.', 'wiro-ai' )
			);
		} else if ( ! data.hasKey ) {
			body = el( KeyNotice );
		} else {
			body = el(
				'div',
				{ style: { display: 'grid', gap: '12px', width: '100%' } },
				el( TextareaControl, {
					label: __( 'Prompt', 'wiro-ai' ),
					value: attributes.prompt,
					onChange: ( value ) => setAttributes( { prompt: value } ),
					rows: 3,
					placeholder: __( 'A watercolor map of a small harbor town at sunrise', 'wiro-ai' ),
					disabled: busy,
					__nextHasNoMarginBottom: true,
				} ),
				el(
					'div',
					{ style: { display: 'flex', gap: '12px', flexWrap: 'wrap' } },
					el(
						'div',
						{ style: { flex: '1 1 220px' } },
						el( ModelSelect, {
							task: 'image',
							value: model,
							onChange: ( value ) => setAttributes( { model: value } ),
							disabled: busy,
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
				el( PriceLine, { task: 'image', model, ratio } ),
				el(
					'div',
					{ style: { display: 'flex', alignItems: 'center', gap: '12px', flexWrap: 'wrap' } },
					el(
						Button,
						{
							variant: 'primary',
							onClick: onGenerate,
							isBusy: busy,
							disabled: busy,
							__next40pxDefaultSize: true,
						},
						busy ? __( 'Generating', 'wiro-ai' ) : __( 'Generate image', 'wiro-ai' )
					),
					el( StatusLine, { busy, stage, elapsed } )
				),
				error &&
					el(
						Notice,
						{ status: 'error', isDismissible: true, onRemove: () => setError( '' ) },
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
					icon: wiro.icon,
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
		icon: wiro.icon,
		edit: Edit,
		save: () => null,
	} );
} )( window.wp );
