/**
 * "Wiro AI featured image" panel in the Post sidebar of the block editor.
 */
( function ( wp ) {
	'use strict';

	const { createElement: el, useEffect, useRef, useState } = wp.element;
	const { __, sprintf } = wp.i18n;
	const { registerPlugin } = wp.plugins;
	const { PluginDocumentSettingPanel } = wp.editor;
	const { useSelect, select, dispatch } = wp.data;
	const {
		Button,
		ExternalLink,
		Notice,
		SelectControl,
		Spinner,
		TextareaControl,
		ToggleControl,
	} = wp.components;

	const wiro = window.wiroAi;
	const data = wiro.data;

	const styles = {
		stack: { display: 'grid', gap: '16px' },
		row: { display: 'flex', gap: '8px', flexWrap: 'wrap' },
		status: { display: 'flex', alignItems: 'center', gap: '8px', margin: 0 },
		image: {
			display: 'block',
			width: '100%',
			height: 'auto',
			borderRadius: '2px',
			marginBottom: '8px',
		},
		note: { margin: '0 0 8px' },
	};

	function KeyNotice() {
		if ( data.canManage && data.settingsUrl ) {
			return el(
				Notice,
				{ status: 'warning', isDismissible: false },
				__( 'Add your Wiro AI API key to start.', 'wiro-ai' ),
				' ',
				el( 'a', { href: data.settingsUrl }, __( 'Open settings', 'wiro-ai' ) )
			);
		}
		return el(
			Notice,
			{ status: 'warning', isDismissible: false },
			__(
				'Ask a site administrator to add a Wiro AI API key in Settings > Wiro AI.',
				'wiro-ai'
			)
		);
	}

	function FeaturedImagePanel() {
		const post = useSelect( ( storeSelect ) => {
			const editor = storeSelect( 'core/editor' );
			return {
				id: editor.getCurrentPostId(),
				title: editor.getEditedPostAttribute( 'title' ) || '',
				featured: editor.getEditedPostAttribute( 'featured_media' ) || 0,
			};
		}, [] );

		const [ prompt, setPrompt ] = useState( '' );
		const [ model, setModel ] = useState( data.defaultModel );
		const [ ratio, setRatio ] = useState( data.defaultRatio );
		const [ makeFeatured, setMakeFeatured ] = useState( true );
		const [ busy, setBusy ] = useState( false );
		const [ stage, setStage ] = useState( '' );
		const [ elapsed, setElapsed ] = useState( 0 );
		const [ error, setError ] = useState( '' );
		const [ result, setResult ] = useState( null );
		const mounted = useRef( true );

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

		if ( ! data.hasKey ) {
			return el(
				PluginDocumentSettingPanel,
				{
					name: 'wiro-ai-featured-image',
					title: __( 'Wiro AI featured image', 'wiro-ai' ),
				},
				el( KeyNotice )
			);
		}

		const title = post.title.trim();
		const titlePrompt = title
			? wiro.buildPrompt( data.promptTemplate, title, '' )
			: '';

		function fillFromTitle() {
			setPrompt( titlePrompt );
		}

		function fillFromContent() {
			const html = select( 'core/editor' ).getEditedPostContent();
			const text = wiro.textFromHtml( html, 60 );
			setPrompt( wiro.buildPrompt( data.promptTemplate, title, text ) );
		}

		async function onGenerate() {
			let text = prompt.trim();
			if ( ! text ) {
				if ( ! titlePrompt ) {
					setError(
						__(
							'Write a prompt, or give the post a title first.',
							'wiro-ai'
						)
					);
					return;
				}
				text = titlePrompt;
				setPrompt( text );
			}

			const setAsFeatured = makeFeatured;
			setBusy( true );
			setError( '' );
			setResult( null );
			setStage( 'queued' );

			try {
				const task = await wiro.generate( {
					prompt: text,
					model,
					ratio,
					postId: post.id,
					title,
					purpose: 'featured',
					onStatus: ( status ) => {
						if ( mounted.current ) {
							setStage( status.stage );
						}
					},
				} );
				if ( setAsFeatured ) {
					dispatch( 'core/editor' ).editPost( {
						featured_media: task.attachment.id,
					} );
				}
				if ( mounted.current ) {
					setResult( task );
				} else {
					dispatch( 'core/notices' ).createSuccessNotice(
						setAsFeatured
							? __(
									'The Wiro AI image is ready and set as the featured image.',
									'wiro-ai'
							  )
							: __(
									'The Wiro AI image is ready in the Media Library.',
									'wiro-ai'
							  ),
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

		const isFeatured =
			result && result.attachment && post.featured === result.attachment.id;
		const cost = result ? wiro.formatCost( result.cost ) : '';

		return el(
			PluginDocumentSettingPanel,
			{
				name: 'wiro-ai-featured-image',
				title: __( 'Wiro AI featured image', 'wiro-ai' ),
			},
			el(
				'div',
				{ style: styles.stack },
				el(
					'div',
					null,
					el( TextareaControl, {
						label: __( 'Prompt', 'wiro-ai' ),
						value: prompt,
						onChange: setPrompt,
						rows: 5,
						placeholder:
							titlePrompt ||
							__( 'Describe the image you want.', 'wiro-ai' ),
						disabled: busy,
						__nextHasNoMarginBottom: true,
					} ),
					el(
						'div',
						{ style: Object.assign( { marginTop: '8px' }, styles.row ) },
						el(
							Button,
							{
								variant: 'secondary',
								size: 'small',
								onClick: fillFromTitle,
								disabled: busy || ! title,
							},
							__( 'From title', 'wiro-ai' )
						),
						el(
							Button,
							{
								variant: 'secondary',
								size: 'small',
								onClick: fillFromContent,
								disabled: busy,
							},
							__( 'From content', 'wiro-ai' )
						)
					)
				),
				el( SelectControl, {
					label: __( 'Model', 'wiro-ai' ),
					value: model,
					options: data.models,
					onChange: setModel,
					disabled: busy,
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
				} ),
				el( SelectControl, {
					label: __( 'Aspect ratio', 'wiro-ai' ),
					value: ratio,
					options: data.ratios,
					onChange: setRatio,
					disabled: busy,
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
				} ),
				el( ToggleControl, {
					label: __( 'Set as featured image', 'wiro-ai' ),
					checked: makeFeatured,
					onChange: setMakeFeatured,
					disabled: busy,
					__nextHasNoMarginBottom: true,
				} ),
				el(
					'div',
					null,
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
					)
				),
				busy &&
					el(
						'p',
						{ style: styles.status, 'aria-live': 'polite' },
						el( Spinner, { style: { margin: 0 } } ),
						sprintf(
							/* translators: 1: progress such as "Generating the image", 2: seconds since the start. */
							__( '%1$s, %2$d s', 'wiro-ai' ),
							wiro.stageLabel( stage ),
							elapsed
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
					),
				result &&
					el(
						'div',
						null,
						el( 'img', {
							src:
								result.attachment.thumb_url ||
								result.attachment.url,
							alt: result.attachment.alt || '',
							style: styles.image,
						} ),
						el(
							'p',
							{ style: styles.note },
							isFeatured
								? __(
										'Saved to the Media Library and set as the featured image.',
										'wiro-ai'
								  )
								: __( 'Saved to the Media Library.', 'wiro-ai' ),
							cost
								? ' ' +
										sprintf(
											/* translators: %s: cost such as $0.034. */
											__( 'Cost: %s.', 'wiro-ai' ),
											cost
										)
								: ''
						),
						el(
							'div',
							{ style: styles.row },
							! isFeatured &&
								el(
									Button,
									{
										variant: 'secondary',
										size: 'small',
										onClick: () =>
											dispatch( 'core/editor' ).editPost( {
												featured_media: result.attachment.id,
											} ),
									},
									__( 'Set as featured image', 'wiro-ai' )
								),
							result.attachment.edit_url &&
								el(
									ExternalLink,
									{ href: result.attachment.edit_url },
									__( 'Edit in the Media Library', 'wiro-ai' )
								)
						)
					)
			)
		);
	}

	registerPlugin( 'wiro-ai-featured-image', {
		render: FeaturedImagePanel,
		icon: null,
	} );
} )( window.wp );
