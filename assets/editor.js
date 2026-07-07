( function( wp ) {
	const { createElement: el, useRef, useCallback } = wp.element;
	const { PluginDocumentSettingPanel } = wp.editor;
	const { useSelect, useDispatch } = wp.data;
	const { Button } = wp.components;

	function calcPosition( e, wrapEl ) {
		const rect = wrapEl.getBoundingClientRect();
		const px = Math.max( 0, Math.min( 100, Math.round( ( e.clientX - rect.left ) / rect.width * 100 ) ) );
		const py = Math.max( 0, Math.min( 100, Math.round( ( e.clientY - rect.top ) / rect.height * 100 ) ) );
		return { px, py };
	}

	function FocalPointPanel() {
		const meta = useSelect( ( s ) =>
			s( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {}
		);
		const featuredId = useSelect( ( s ) =>
			s( 'core/editor' ).getEditedPostAttribute( 'featured_media' )
		);
		const media = useSelect( ( s ) =>
			featuredId ? s( 'core' ).getEntityRecord( 'postType', 'attachment', featuredId ) : null
		, [ featuredId ] );

		const { editPost } = useDispatch( 'core/editor' );
		const wrapRef = useRef( null );
		const dotRef = useRef( null );
		const dragging = useRef( false );

		const x = meta._corsivo_focal_point_x != null ? meta._corsivo_focal_point_x : 50;
		const y = meta._corsivo_focal_point_y != null ? meta._corsivo_focal_point_y : 50;

		// Move dot visually without waiting for React re-render
		const moveDot = useCallback( ( px, py ) => {
			if ( dotRef.current ) {
				dotRef.current.style.left = px + '%';
				dotRef.current.style.top = py + '%';
			}
		}, [] );

		const commitPosition = useCallback( ( px, py ) => {
			editPost( { meta: { _corsivo_focal_point_x: px, _corsivo_focal_point_y: py } } );
		}, [ editPost ] );

		const handlePointerDown = useCallback( ( e ) => {
			if ( ! wrapRef.current ) return;
			dragging.current = true;
			wrapRef.current.setPointerCapture( e.pointerId );
			const { px, py } = calcPosition( e, wrapRef.current );
			moveDot( px, py );
		}, [ moveDot ] );

		const handlePointerMove = useCallback( ( e ) => {
			if ( ! dragging.current || ! wrapRef.current ) return;
			const { px, py } = calcPosition( e, wrapRef.current );
			moveDot( px, py );
		}, [ moveDot ] );

		const handlePointerUp = useCallback( ( e ) => {
			if ( ! dragging.current || ! wrapRef.current ) return;
			dragging.current = false;
			const { px, py } = calcPosition( e, wrapRef.current );
			commitPosition( px, py );
		}, [ commitPosition ] );

		if ( ! featuredId || ! media ) {
			return el( PluginDocumentSettingPanel, { name: 'focal-point', title: 'Focal Point', icon: 'visibility' },
				el( 'p', { className: 'description' }, 'Imposta un\'immagine in evidenza per usare il focal point.' )
			);
		}

		let imgUrl = media.source_url || '';
		if ( media.media_details?.sizes ) {
			const sizes = media.media_details.sizes;
			imgUrl = sizes.medium_large?.source_url || sizes.medium?.source_url || imgUrl;
		}

		return el( PluginDocumentSettingPanel, { name: 'focal-point', title: 'Focal Point', icon: 'visibility' },
			el( 'div', { className: 'fp-picker' },
				el( 'div', {
					className: 'fp-picker__image-wrap',
					ref: wrapRef,
					onPointerDown: handlePointerDown,
					onPointerMove: handlePointerMove,
					onPointerUp: handlePointerUp,
				},
					el( 'img', { src: imgUrl, className: 'fp-picker__image', draggable: false } ),
					el( 'div', { className: 'fp-picker__dot', ref: dotRef, style: { left: x + '%', top: y + '%' } } )
				),
				el( 'p', { className: 'fp-picker__coords' }, `x: ${x}%  y: ${y}%` ),
				el( Button, {
					variant: 'tertiary',
					isSmall: true,
					onClick: () => commitPosition( 50, 50 ),
				}, 'Reset centro' )
			)
		);
	}

	wp.plugins.registerPlugin( 'focal-point-panel', {
		render: FocalPointPanel,
		icon: 'visibility',
	} );
} )( window.wp );
