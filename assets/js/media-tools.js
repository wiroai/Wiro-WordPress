/**
 * Wiro AI box on the edit screen of Media Library images: alt text, edit with
 * a prompt, upscale and remove the background.
 */
( function ( wp ) {
	'use strict';

	const { createElement: el, createRoot, useState } = wp.element;
	const { __ } = wp.i18n;
	const { Notice, PanelBody } = wp.components;

	const wiro = window.wiroAi;
	const { ImageTool } = wiro.components;

	const TITLES = {
		alt: __( 'Write alt text', 'wiro-ai' ),
		edit: __( 'Edit with a prompt', 'wiro-ai' ),
		upscale: __( 'Upscale', 'wiro-ai' ),
		background: __( 'Remove background', 'wiro-ai' ),
	};

	function MediaTools( props ) {
		const [ message, setMessage ] = useState( '' );

		function onAlt( job ) {
			const field = document.getElementById( 'attachment_alt' );
			if ( field && job.result && job.result.alt ) {
				field.value = job.result.alt;
				field.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );
				setMessage( __( 'Alt text filled in. Click Update to save it.', 'wiro-ai' ) );
			}
		}

		return el(
			'div',
			{ className: 'wiro-ai-media-tools' },
			message &&
				el(
					Notice,
					{ status: 'success', onRemove: () => setMessage( '' ) },
					message
				),
			Object.keys( TITLES ).map( ( tool ) =>
				el(
					PanelBody,
					{ key: tool, title: TITLES[ tool ], initialOpen: tool === 'alt' },
					el( ImageTool, {
						tool,
						attachmentId: props.attachmentId,
						title: props.title,
						purpose: 'media',
						onResult: tool === 'alt' ? onAlt : undefined,
					} )
				)
			)
		);
	}

	wp.domReady( () => {
		const root = document.getElementById( 'wiro-ai-media-tools-root' );
		if ( ! root ) {
			return;
		}
		const attachmentId = parseInt( root.getAttribute( 'data-attachment' ), 10 ) || 0;
		createRoot( root ).render(
			el( MediaTools, { attachmentId, title: root.getAttribute( 'data-title' ) || '' } )
		);
	} );
} )( window.wp );
