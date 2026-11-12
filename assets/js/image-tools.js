/**
 * Wiro AI menu in the toolbar of Image blocks: edit with a prompt, upscale,
 * remove the background and write alt text. Image results replace the
 * block's image with the new Media Library copy; the original stays in the
 * library and the change can be undone like any edit.
 */
( function ( wp ) {
	'use strict';

	const { createElement: el, Fragment, useState } = wp.element;
	const { __ } = wp.i18n;
	const { createHigherOrderComponent } = wp.compose;
	const { BlockControls } = wp.blockEditor;
	const { Modal, ToolbarDropdownMenu } = wp.components;
	const { dispatch } = wp.data;

	const wiro = window.wiroAi;
	const { ImageTool } = wiro.components;

	const TITLES = {
		edit: __( 'Edit with a prompt', 'wiro-ai' ),
		upscale: __( 'Upscale', 'wiro-ai' ),
		background: __( 'Remove background', 'wiro-ai' ),
		alt: __( 'Write alt text', 'wiro-ai' ),
	};

	function notify( message ) {
		dispatch( 'core/notices' ).createSuccessNotice( message, { type: 'snackbar' } );
	}

	const withWiroTools = createHigherOrderComponent(
		( BlockEdit ) =>
			function WiroImageTools( props ) {
				const [ tool, setTool ] = useState( '' );
				if ( props.name !== 'core/image' || ! wiro.data.canGenerate ) {
					return el( BlockEdit, props );
				}
				const id = props.attributes.id;

				function onResult( job ) {
					if ( tool === 'alt' && job.result && job.result.alt ) {
						props.setAttributes( { alt: job.result.alt } );
						notify( __( 'Alt text updated. You can change it in the block settings.', 'wiro-ai' ) );
					} else if ( job.attachment ) {
						props.setAttributes( {
							id: job.attachment.id,
							url: job.attachment.large_url || job.attachment.url,
							sizeSlug: job.attachment.large_url ? 'large' : 'full',
						} );
						notify(
							__(
								'The block now shows the new image. The original stays in the Media Library.',
								'wiro-ai'
							)
						);
					}
					setTool( '' );
				}

				return el(
					Fragment,
					null,
					el( BlockEdit, props ),
					id &&
						props.isSelected &&
						el(
							BlockControls,
							{ group: 'other' },
							el( ToolbarDropdownMenu, {
								icon: wiro.icon,
								label: __( 'Wiro AI', 'wiro-ai' ),
								controls: Object.keys( TITLES ).map( ( key ) => ( {
									title: TITLES[ key ],
									onClick: () => setTool( key ),
								} ) ),
							} )
						),
					tool &&
						el(
							Modal,
							{
								title: TITLES[ tool ],
								onRequestClose: () => setTool( '' ),
								className: 'wiro-ai-modal',
							},
							el(
								'div',
								{ style: { maxWidth: '480px' } },
								el( ImageTool, {
									tool,
									attachmentId: id,
									title: props.attributes.alt || '',
									purpose: 'editor',
									onResult,
									showResult: false,
								} )
							)
						)
				);
			},
		'withWiroTools'
	);

	wp.hooks.addFilter( 'editor.BlockEdit', 'wiro-ai/image-tools', withWiroTools );
} )( window.wp );
