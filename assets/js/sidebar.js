/**
 * Wiro AI in the post editor: the "Wiro AI featured image" panel in the Post
 * sidebar, and the Wiro AI sidebar (the W button in the top bar) with the
 * featured image, title and SEO suggestions, narration and image tools.
 */
( function ( wp ) {
	'use strict';

	const { createElement: el, Fragment, useEffect, useRef, useState } = wp.element;
	const { __, sprintf } = wp.i18n;
	const { registerPlugin } = wp.plugins;
	const {
		PluginDocumentSettingPanel,
		PluginSidebar,
		PluginSidebarMoreMenuItem,
	} = wp.editor;
	const { useSelect, select, dispatch } = wp.data;
	const { createBlock } = wp.blocks;
	const {
		Button,
		ExternalLink,
		Notice,
		PanelBody,
		SelectControl,
		TextareaControl,
		ToggleControl,
	} = wp.components;

	const wiro = window.wiroAi;
	const data = wiro.data;
	const { ModelSelect, PriceLine, StatusLine, KeyNotice } = wiro.components;
	const styles = wiro.styles;

	function usePost() {
		return useSelect( ( storeSelect ) => {
			const editor = storeSelect( 'core/editor' );
			return {
				id: editor.getCurrentPostId(),
				title: editor.getEditedPostAttribute( 'title' ) || '',
				featured: editor.getEditedPostAttribute( 'featured_media' ) || 0,
			};
		}, [] );
	}

	function useMounted() {
		const mounted = useRef( true );
		useEffect( () => {
			mounted.current = true;
			return () => {
				mounted.current = false;
			};
		}, [] );
		return mounted;
	}

	function postText() {
		return wiro.textFromHtml( select( 'core/editor' ).getEditedPostContent(), 0 );
	}

	// Text to read aloud: the post without its audio players, so a second
	// narration does not read the caption of the first one.
	function narrationText() {
		const blocks = select( 'core/block-editor' )
			.getBlocks()
			.filter( ( block ) => block.name !== 'core/audio' );
		return wiro.textFromHtml( wp.blocks.serialize( blocks ), 0 );
	}

	function notify( message, isError ) {
		const notices = dispatch( 'core/notices' );
		if ( isError ) {
			notices.createErrorNotice( message, { type: 'snackbar' } );
		} else {
			notices.createSuccessNotice( message, { type: 'snackbar' } );
		}
	}

	function costText( cost ) {
		const formatted = wiro.formatCost( cost );
		return formatted
			? ' ' +
					sprintf(
						/* translators: %s: cost such as $0.034. */
						__( 'Cost: %s.', 'wiro-ai' ),
						formatted
					)
			: '';
	}

	/**
	 * Featured image from a prompt, the post title or its content.
	 *
	 * @return {Object} Element.
	 */
	function FeaturedImageTool() {
		const post = usePost();
		const mounted = useMounted();
		const [ prompt, setPrompt ] = useState( '' );
		const [ model, setModel ] = useState( data.defaults.image || '' );
		const [ ratio, setRatio ] = useState( data.defaultRatio );
		const [ makeFeatured, setMakeFeatured ] = useState( true );
		const [ busy, setBusy ] = useState( false );
		const [ stage, setStage ] = useState( '' );
		const [ error, setError ] = useState( '' );
		const [ result, setResult ] = useState( null );
		const elapsed = wiro.useElapsed( busy );

		if ( ! data.hasKey ) {
			return el( KeyNotice );
		}

		const title = post.title.trim();
		const titlePrompt = title ? wiro.buildPrompt( data.promptTemplate, title, '' ) : '';

		async function onGenerate() {
			let text = prompt.trim();
			if ( ! text ) {
				if ( ! titlePrompt ) {
					setError( __( 'Write a prompt, or give the post a title first.', 'wiro-ai' ) );
					return;
				}
				text = titlePrompt;
				setPrompt( text );
			}
			const setAsFeatured = makeFeatured && data.thumbnails;
			setBusy( true );
			setError( '' );
			setResult( null );
			setStage( 'queued' );
			try {
				const job = await wiro.runJob(
					{
						task: 'image',
						model,
						ratio,
						prompt: text,
						post_id: post.id,
						title,
						purpose: setAsFeatured ? 'featured' : 'editor',
					},
					( status ) => mounted.current && setStage( status.stage )
				);
				if ( setAsFeatured ) {
					dispatch( 'core/editor' ).editPost( { featured_media: job.attachment.id } );
				}
				if ( mounted.current ) {
					setResult( job );
				} else {
					notify( __( 'The Wiro AI image is ready.', 'wiro-ai' ) );
				}
			} catch ( caught ) {
				if ( mounted.current ) {
					setError( wiro.errorMessage( caught ) );
				} else {
					notify( wiro.errorMessage( caught ), true );
				}
			} finally {
				if ( mounted.current ) {
					setBusy( false );
					setStage( '' );
				}
			}
		}

		const isFeatured = result && result.attachment && post.featured === result.attachment.id;

		return el(
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
					placeholder: titlePrompt || __( 'Describe the image you want.', 'wiro-ai' ),
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
							onClick: () => setPrompt( titlePrompt ),
							disabled: busy || ! title,
						},
						__( 'From title', 'wiro-ai' )
					),
					el(
						Button,
						{
							variant: 'secondary',
							size: 'small',
							onClick: () =>
								setPrompt(
									wiro.buildPrompt(
										data.promptTemplate,
										title,
										wiro.textFromHtml( select( 'core/editor' ).getEditedPostContent(), 60 )
									)
								),
							disabled: busy,
						},
						__( 'From content', 'wiro-ai' )
					)
				)
			),
			el( ModelSelect, { task: 'image', value: model, onChange: setModel, disabled: busy } ),
			el( SelectControl, {
				label: __( 'Aspect ratio', 'wiro-ai' ),
				value: ratio,
				options: data.ratios,
				onChange: setRatio,
				disabled: busy,
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
			} ),
			data.thumbnails &&
				el( ToggleControl, {
					label: __( 'Set as featured image', 'wiro-ai' ),
					checked: makeFeatured,
					onChange: setMakeFeatured,
					disabled: busy,
					__nextHasNoMarginBottom: true,
				} ),
			el( PriceLine, { task: 'image', model, ratio } ),
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
					busy ? __( 'Generating', 'wiro-ai' ) : __( 'Generate image', 'wiro-ai' )
				)
			),
			el( StatusLine, { busy, stage, elapsed } ),
			error &&
				el(
					Notice,
					{ status: 'error', isDismissible: true, onRemove: () => setError( '' ) },
					error
				),
			result &&
				el(
					'div',
					null,
					el( 'img', {
						src: result.attachment.thumb_url || result.attachment.url,
						alt: result.attachment.alt || '',
						style: styles.image,
					} ),
					el(
						'p',
						{ style: { margin: '0 0 8px' } },
						isFeatured
							? __( 'Saved to the Media Library and set as the featured image.', 'wiro-ai' )
							: __( 'Saved to the Media Library.', 'wiro-ai' ),
						costText( result.cost )
					),
					el(
						'div',
						{ style: styles.row },
						data.thumbnails &&
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
		);
	}

	/**
	 * Title, excerpt and SEO description suggestions from an LLM.
	 *
	 * @return {Object} Element.
	 */
	function TextTool() {
		const post = usePost();
		const mounted = useMounted();
		const [ model, setModel ] = useState( data.defaults.text || '' );
		const [ busy, setBusy ] = useState( false );
		const [ error, setError ] = useState( '' );
		const [ result, setResult ] = useState( null );
		const [ chars, setChars ] = useState( 0 );
		const [ copied, setCopied ] = useState( false );

		useEffect( () => {
			setChars( Math.min( 6000, postText().length ) + post.title.length );
		}, [ post.title ] );

		if ( ! data.hasKey ) {
			return el( KeyNotice );
		}

		async function onSuggest() {
			setBusy( true );
			setError( '' );
			setResult( null );
			setCopied( false );
			try {
				const job = await wiro.runJob( {
					task: 'text',
					model,
					post_id: post.id,
					title: post.title,
					text: postText().slice( 0, 6000 ),
					purpose: 'editor',
				} );
				if ( mounted.current ) {
					setResult( job );
				}
			} catch ( caught ) {
				if ( mounted.current ) {
					setError( wiro.errorMessage( caught ) );
				}
			} finally {
				if ( mounted.current ) {
					setBusy( false );
				}
			}
		}

		function copy( text ) {
			if ( window.navigator.clipboard ) {
				window.navigator.clipboard.writeText( text ).then( () => setCopied( true ) );
			}
		}

		const suggestions = result && result.result;
		return el(
			'div',
			{ style: styles.stack },
			el(
				'p',
				{ style: styles.muted },
				__(
					'Reads the title and content and suggests new titles, an excerpt and an SEO meta description. Nothing changes until you pick one.',
					'wiro-ai'
				)
			),
			el( ModelSelect, { task: 'text', value: model, onChange: setModel, disabled: busy } ),
			el( PriceLine, { task: 'text', model, chars } ),
			el(
				'div',
				null,
				el(
					Button,
					{
						variant: 'primary',
						onClick: onSuggest,
						isBusy: busy,
						disabled: busy,
						__next40pxDefaultSize: true,
					},
					busy ? __( 'Thinking', 'wiro-ai' ) : __( 'Suggest', 'wiro-ai' )
				)
			),
			error &&
				el(
					Notice,
					{ status: 'error', isDismissible: true, onRemove: () => setError( '' ) },
					error
				),
			suggestions &&
				el(
					'div',
					{ style: styles.stack },
					suggestions.titles.length > 0 &&
						el(
							'div',
							null,
							el( 'strong', null, __( 'Titles', 'wiro-ai' ) ),
							suggestions.titles.map( ( title ) =>
								el(
									'div',
									{ key: title, style: Object.assign( { marginTop: '6px' }, styles.row ) },
									el( 'span', { style: { flex: '1 1 160px' } }, title ),
									el(
										Button,
										{
											variant: 'secondary',
											size: 'small',
											onClick: () => dispatch( 'core/editor' ).editPost( { title } ),
										},
										__( 'Use', 'wiro-ai' )
									)
								)
							)
						),
					suggestions.excerpt &&
						el(
							'div',
							null,
							el( 'strong', null, __( 'Excerpt', 'wiro-ai' ) ),
							el( 'p', { style: { margin: '6px 0' } }, suggestions.excerpt ),
							el(
								Button,
								{
									variant: 'secondary',
									size: 'small',
									onClick: () =>
										dispatch( 'core/editor' ).editPost( { excerpt: suggestions.excerpt } ),
								},
								__( 'Use as excerpt', 'wiro-ai' )
							)
						),
					suggestions.seo_description &&
						el(
							'div',
							null,
							el( 'strong', null, __( 'SEO description', 'wiro-ai' ) ),
							el( 'p', { style: { margin: '6px 0' } }, suggestions.seo_description ),
							el(
								'p',
								{ style: styles.muted },
								sprintf(
									/* translators: %d: number of characters. */
									__( '%d characters. Paste it into your SEO plugin.', 'wiro-ai' ),
									suggestions.seo_description.length
								)
							),
							el(
								Button,
								{
									variant: 'secondary',
									size: 'small',
									onClick: () => copy( suggestions.seo_description ),
								},
								copied ? __( 'Copied', 'wiro-ai' ) : __( 'Copy', 'wiro-ai' )
							)
						),
					el( 'p', { style: styles.muted }, costText( result.cost ).trim() )
				)
		);
	}

	/**
	 * Narration: reads the post aloud with a text-to-speech model and adds an
	 * audio player to the post.
	 *
	 * @return {Object} Element.
	 */
	function NarrationTool() {
		const post = usePost();
		const mounted = useMounted();
		const [ model, setModel ] = useState( data.defaults.narration || '' );
		const [ voice, setVoice ] = useState( '' );
		const [ position, setPosition ] = useState( data.narrationPosition || 'top' );
		const [ busy, setBusy ] = useState( false );
		const [ stage, setStage ] = useState( '' );
		const [ error, setError ] = useState( '' );
		const [ result, setResult ] = useState( null );
		const [ chars, setChars ] = useState( 0 );
		const elapsed = wiro.useElapsed( busy );
		const info = wiro.useVoices( model );

		useEffect( () => {
			const text = ( post.title ? post.title + '. ' : '' ) + narrationText();
			setChars( Math.min( data.maxChars, text.length ) );
		}, [ post.title, busy ] );

		useEffect( () => {
			if ( info.voices.length && ! info.voices.includes( voice ) ) {
				setVoice( info.default_voice || info.voices[ 0 ] );
			}
		}, [ info ] );

		if ( ! data.hasKey ) {
			return el( KeyNotice );
		}

		async function onCreate() {
			const text = ( post.title ? post.title + '. ' : '' ) + narrationText();
			if ( ! text.trim() ) {
				setError( __( 'There is no text to read yet.', 'wiro-ai' ) );
				return;
			}
			setBusy( true );
			setError( '' );
			setResult( null );
			setStage( 'queued' );
			try {
				const job = await wiro.runJob(
					{
						task: 'narration',
						model,
						voice,
						post_id: post.id,
						title: post.title,
						text: text.slice( 0, data.maxChars ),
						purpose: 'editor',
					},
					( status ) => mounted.current && setStage( status.stage )
				);
				const audio = createBlock( 'core/audio', {
					id: job.attachment.id,
					src: job.attachment.url,
					caption: __( 'Listen to this post', 'wiro-ai' ),
				} );
				const count = select( 'core/block-editor' ).getBlockCount();
				dispatch( 'core/block-editor' ).insertBlocks( audio, position === 'bottom' ? count : 0 );
				if ( mounted.current ) {
					setResult( job );
				} else {
					notify( __( 'The narration is ready and added to the post.', 'wiro-ai' ) );
				}
			} catch ( caught ) {
				if ( mounted.current ) {
					setError( wiro.errorMessage( caught ) );
				} else {
					notify( wiro.errorMessage( caught ), true );
				}
			} finally {
				if ( mounted.current ) {
					setBusy( false );
					setStage( '' );
				}
			}
		}

		return el(
			'div',
			{ style: styles.stack },
			el(
				'p',
				{ style: styles.muted },
				sprintf(
					/* translators: 1: number of characters, 2: largest number of characters. */
					__(
						'Reads the title and content aloud (%1$s characters, up to %2$s) and adds an audio player to the post.',
						'wiro-ai'
					),
					chars.toLocaleString(),
					data.maxChars.toLocaleString()
				)
			),
			el( ModelSelect, { task: 'narration', value: model, onChange: setModel, disabled: busy } ),
			info.voices.length > 0 &&
				el( SelectControl, {
					label: __( 'Voice', 'wiro-ai' ),
					value: voice,
					options: info.voices.map( ( name ) => ( { value: name, label: name } ) ),
					onChange: setVoice,
					disabled: busy,
					__nextHasNoMarginBottom: true,
					__next40pxDefaultSize: true,
				} ),
			el( SelectControl, {
				label: __( 'Player position', 'wiro-ai' ),
				value: position,
				options: [
					{ value: 'top', label: __( 'At the top of the post', 'wiro-ai' ) },
					{ value: 'bottom', label: __( 'At the end of the post', 'wiro-ai' ) },
				],
				onChange: setPosition,
				disabled: busy,
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
			} ),
			el( PriceLine, { task: 'narration', model, voice, chars } ),
			el(
				'div',
				null,
				el(
					Button,
					{
						variant: 'primary',
						onClick: onCreate,
						isBusy: busy,
						disabled: busy || chars === 0,
						__next40pxDefaultSize: true,
					},
					busy ? __( 'Recording', 'wiro-ai' ) : __( 'Create narration', 'wiro-ai' )
				)
			),
			el( StatusLine, { busy, stage, elapsed } ),
			error &&
				el(
					Notice,
					{ status: 'error', isDismissible: true, onRemove: () => setError( '' ) },
					error
				),
			result &&
				el(
					'div',
					null,
					el( 'audio', { controls: true, src: result.attachment.url, style: { width: '100%' } } ),
					el(
						'p',
						{ style: styles.muted },
						__( 'Saved to the Media Library and added to the post as an Audio block.', 'wiro-ai' ),
						costText( result.cost )
					)
				)
		);
	}

	function ImageToolsHint() {
		return el(
			'p',
			{ style: styles.muted },
			__(
				'Select an Image block and open the W menu in its toolbar to edit it with a prompt, upscale it, remove its background or write its alt text. The Wiro AI Image block makes a new image anywhere in the post.',
				'wiro-ai'
			)
		);
	}

	function FeaturedImagePanel() {
		return el(
			PluginDocumentSettingPanel,
			{
				name: 'wiro-ai-featured-image',
				title: __( 'Wiro AI featured image', 'wiro-ai' ),
			},
			el( FeaturedImageTool )
		);
	}

	function WiroSidebar() {
		return el(
			Fragment,
			null,
			el(
				PluginSidebarMoreMenuItem,
				{ target: 'wiro-ai-sidebar', icon: wiro.icon },
				__( 'Wiro AI', 'wiro-ai' )
			),
			el(
				PluginSidebar,
				{ name: 'wiro-ai-sidebar', title: __( 'Wiro AI', 'wiro-ai' ), icon: wiro.icon },
				data.thumbnails &&
					el(
						PanelBody,
						{ title: __( 'Featured image', 'wiro-ai' ), initialOpen: true },
						el( FeaturedImageTool )
					),
				el(
					PanelBody,
					{ title: __( 'Titles, excerpt and SEO', 'wiro-ai' ), initialOpen: ! data.thumbnails },
					el( TextTool )
				),
				el(
					PanelBody,
					{ title: __( 'Narration', 'wiro-ai' ), initialOpen: false },
					el( NarrationTool )
				),
				el(
					PanelBody,
					{ title: __( 'Image tools', 'wiro-ai' ), initialOpen: false },
					el( ImageToolsHint )
				)
			)
		);
	}

	registerPlugin( 'wiro-ai', { render: WiroSidebar, icon: wiro.icon } );
	if ( data.thumbnails ) {
		registerPlugin( 'wiro-ai-featured-image', { render: FeaturedImagePanel, icon: null } );
	}
} )( window.wp );
