( function( wp, config ) {
	const { createElement: el, createRoot, render, useEffect, useRef, useState } = wp.element;
	const { Button, FocalPointPicker, Notice } = wp.components;
	const { __ } = wp.i18n;
	const featuredImageEvent = 'corsivo-focal-point-featured-image-change';

	function clampCoordinate( value ) {
		const coordinate = Number( value );

		if ( ! Number.isFinite( coordinate ) ) {
			return 50;
		}

		return Math.max( 0, Math.min( 100, Math.round( coordinate ) ) );
	}

	function getAttachmentId( value ) {
		const attachmentId = Number( value );

		return Number.isInteger( attachmentId ) && attachmentId > 0 ? attachmentId : 0;
	}

	function ClassicFocalPointPicker( { node } ) {
		const fallbackPosition = {
			x: clampCoordinate( node.dataset.fallbackX ),
			y: clampCoordinate( node.dataset.fallbackY ),
		};
		const persistFallback = '1' === node.dataset.persistFallback;
		const initialAttachmentId = getAttachmentId( node.dataset.attachmentId );
		const storedState = config.initialState || {};
		const storedAttachmentId = getAttachmentId( storedState.attachment_id );
		const initialPosition = {
			x: clampCoordinate( node.dataset.x ),
			y: clampCoordinate( node.dataset.y ),
		};
		const [ featuredImage, setFeaturedImage ] = useState( {
			attachmentId: initialAttachmentId,
			url: node.dataset.url || '',
		} );
		const [ position, setPosition ] = useState( initialPosition );
		const [ hasChanges, setHasChanges ] = useState( persistFallback && 0 < initialAttachmentId );
		const positionCache = useRef( new Map() );

		useEffect( () => {
			if ( storedState.has_position && storedState.has_attachment_link && storedAttachmentId ) {
				positionCache.current.set( storedAttachmentId, {
					position: {
						x: clampCoordinate( storedState.x ),
						y: clampCoordinate( storedState.y ),
					},
					hasChanges: false,
				} );
			}

			if ( initialAttachmentId ) {
				positionCache.current.set( initialAttachmentId, {
					position: initialPosition,
					hasChanges: persistFallback,
				} );
			}
		}, [] );

		useEffect( () => {
			const handleFeaturedImageChange = ( event ) => {
				const attachmentId = getAttachmentId( event.detail?.attachmentId );
				const url = event.detail?.url || '';

				if ( attachmentId === featuredImage.attachmentId ) {
					if ( url !== featuredImage.url ) {
						setFeaturedImage( { attachmentId, url } );
					}
					return;
				}

				if ( featuredImage.attachmentId ) {
					positionCache.current.set( featuredImage.attachmentId, { position, hasChanges } );
				}

				const cached = positionCache.current.get( attachmentId );

				setFeaturedImage( { attachmentId, url } );
				setPosition( cached?.position || fallbackPosition );
				setHasChanges( cached ? cached.hasChanges : persistFallback && 0 < attachmentId );
			};

			node.addEventListener( featuredImageEvent, handleFeaturedImageChange );

			return () => node.removeEventListener( featuredImageEvent, handleFeaturedImageChange );
		}, [ featuredImage, hasChanges, position ] );

		const updatePosition = ( point ) => {
			setPosition( {
				x: clampCoordinate( point.x * 100 ),
				y: clampCoordinate( point.y * 100 ),
			} );
			setHasChanges( true );
		};

		if ( ! featuredImage.attachmentId ) {
			return el( 'p', { className: 'description' }, __( 'Imposta un’immagine in evidenza per configurare il focal point.', 'corsivo-focal-point' ) );
		}

		if ( ! featuredImage.url ) {
			return el( Notice, { status: 'warning', isDismissible: false }, __( 'L’anteprima dell’immagine non è disponibile.', 'corsivo-focal-point' ) );
		}

		return el(
			'div',
			{ className: 'corsivo-focal-point-picker' },
			el( FocalPointPicker, {
				label: __( 'Punto focale', 'corsivo-focal-point' ),
				url: featuredImage.url,
				value: { x: position.x / 100, y: position.y / 100 },
				onChange: updatePosition,
			} ),
			hasChanges && el( 'input', { type: 'hidden', name: config.metaX, value: position.x } ),
			hasChanges && el( 'input', { type: 'hidden', name: config.metaY, value: position.y } ),
			hasChanges && el( 'input', { type: 'hidden', name: config.metaAttachment, value: featuredImage.attachmentId } ),
			el( Button, {
				variant: 'tertiary',
				size: 'small',
				disabled: 50 === position.x && 50 === position.y,
				onClick: () => {
					setPosition( { x: 50, y: 50 } );
					setHasChanges( true );
				},
			}, __( 'Reimposta al centro', 'corsivo-focal-point' ) )
		);
	}

	const nodes = document.querySelectorAll( '.corsivo-focal-point-classic' );

	nodes.forEach( ( node ) => {
		const component = el( ClassicFocalPointPicker, { node } );

		if ( createRoot ) {
			createRoot( node ).render( component );
			return;
		}

		render( component, node );
	} );

	const featuredImageBox = document.getElementById( 'postimagediv' );

	if ( ! featuredImageBox || ! nodes.length ) {
		return;
	}

	let animationFrame = 0;
	let previousSignature = '';
	const notifyFeaturedImageChange = () => {
		window.cancelAnimationFrame( animationFrame );
		animationFrame = window.requestAnimationFrame( () => {
			const input = document.getElementById( '_thumbnail_id' );
			const image = featuredImageBox.querySelector( '#set-post-thumbnail img' );
			const attachmentId = getAttachmentId( input?.value );
			const url = image?.currentSrc || image?.getAttribute( 'src' ) || '';
			const signature = `${ attachmentId }:${ url }`;

			if ( signature === previousSignature ) {
				return;
			}

			previousSignature = signature;

			nodes.forEach( ( node ) => {
				node.dispatchEvent( new CustomEvent( featuredImageEvent, { detail: { attachmentId, url } } ) );
			} );
		} );
	};
	const observer = new MutationObserver( notifyFeaturedImageChange );

	observer.observe( featuredImageBox, { attributes: true, childList: true, subtree: true } );
	notifyFeaturedImageChange();
} )( window.wp, window.corsivoFocalPointClassicEditor );
