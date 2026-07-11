( function( wp, config ) {
	const { createElement: el, useCallback } = wp.element;
	const { PluginDocumentSettingPanel } = wp.editor;
	const { useDispatch, useSelect } = wp.data;
	const { Button, FocalPointPicker, Notice, Spinner } = wp.components;
	const { __ } = wp.i18n;

	function clampCoordinate( value ) {
		const coordinate = Number( value );

		if ( ! Number.isFinite( coordinate ) ) {
			return 50;
		}

		return Math.max( 0, Math.min( 100, Math.round( coordinate ) ) );
	}

	function getPreviewUrl( media ) {
		if ( ! media ) {
			return '';
		}

		const sizes = media.media_details?.sizes || {};

		return sizes.medium_large?.source_url || sizes.medium?.source_url || media.source_url || '';
	}

	function FocalPointPanel() {
		const { meta, featuredId } = useSelect( ( select ) => ( {
			meta: select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {},
			featuredId: select( 'core/editor' ).getEditedPostAttribute( 'featured_media' ) || 0,
		} ), [] );
		const { media, mediaResolutionFinished } = useSelect( ( select ) => {
			if ( ! featuredId ) {
				return { media: null, mediaResolutionFinished: true };
			}

			const query = [ 'postType', 'attachment', featuredId ];
			const core = select( 'core' );

			return {
				media: core.getEntityRecord( ...query ),
				mediaResolutionFinished: core.hasFinishedResolution( 'getEntityRecord', query ),
			};
		}, [ featuredId ] );
		const { editPost } = useDispatch( 'core/editor' );
		const storedAttachmentId = Number( meta[ config.metaAttachment ] ) || 0;
		const initialState = config.initialState || {};
		const initialAttachmentId = Number( initialState.attachment_id ) || 0;
		const sourcePosition = config.sourcePosition || {};
		let x = 50;
		let y = 50;

		if ( featuredId && storedAttachmentId === featuredId ) {
			x = clampCoordinate( meta[ config.metaX ] );
			y = clampCoordinate( meta[ config.metaY ] );
		} else if ( initialState.has_position && initialAttachmentId === featuredId ) {
			x = clampCoordinate( initialState.x );
			y = clampCoordinate( initialState.y );
		} else if ( ! initialState.has_position && config.sourcePosition ) {
			x = clampCoordinate( sourcePosition.x );
			y = clampCoordinate( sourcePosition.y );
		}

		const commitPosition = useCallback( ( point ) => {
			editPost( {
				meta: {
					[ config.metaX ]: clampCoordinate( point.x * 100 ),
					[ config.metaY ]: clampCoordinate( point.y * 100 ),
					[ config.metaAttachment ]: featuredId,
				},
			} );
		}, [ editPost, featuredId ] );

		const panelProps = {
			name: 'corsivo-focal-point',
			title: __( 'Focal Point', 'corsivo-focal-point' ),
			icon: 'visibility',
		};

		if ( ! featuredId ) {
			return el(
				PluginDocumentSettingPanel,
				panelProps,
				el( 'p', { className: 'description' }, __( 'Imposta un’immagine in evidenza per configurare il focal point.', 'corsivo-focal-point' ) )
			);
		}

		if ( ! mediaResolutionFinished ) {
			return el( PluginDocumentSettingPanel, panelProps, el( Spinner ) );
		}

		const previewUrl = getPreviewUrl( media );

		if ( ! previewUrl ) {
			return el(
				PluginDocumentSettingPanel,
				panelProps,
				el( Notice, { status: 'warning', isDismissible: false }, __( 'L’anteprima dell’immagine non è disponibile.', 'corsivo-focal-point' ) )
			);
		}

		return el(
			PluginDocumentSettingPanel,
			panelProps,
			el(
				'div',
				{ className: 'corsivo-focal-point-picker' },
				el( FocalPointPicker, {
					label: __( 'Punto focale', 'corsivo-focal-point' ),
					url: previewUrl,
					value: { x: x / 100, y: y / 100 },
					onChange: commitPosition,
				} ),
				el( Button, {
					variant: 'tertiary',
					size: 'small',
					disabled: 50 === x && 50 === y,
					onClick: () => commitPosition( { x: 0.5, y: 0.5 } ),
				}, __( 'Reimposta al centro', 'corsivo-focal-point' ) )
			)
		);
	}

	wp.plugins.registerPlugin( 'corsivo-focal-point', {
		render: FocalPointPanel,
		icon: 'visibility',
	} );
} )( window.wp, window.corsivoFocalPointEditor );
