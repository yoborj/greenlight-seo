/* Greenlight SEO: FAQ block editor (no build step). The front end is rendered in PHP (includes/class-faq.php). */
( function ( blocks, element, blockEditor, components ) {
	var el = element.createElement;

	blocks.registerBlockType( 'greenlight-seo/faq', {
		title: 'FAQ (Greenlight SEO)',
		icon: 'editor-help',
		category: 'text',
		edit: function ( props ) {
			var items = props.attributes.items && props.attributes.items.length ? props.attributes.items : [ { q: '', a: '' } ];
			var update = function ( i, key, value ) {
				props.setAttributes( {
					items: items.map( function ( it, j ) {
						var copy = { q: it.q, a: it.a };
						if ( j === i ) {
							copy[ key ] = value;
						}
						return copy;
					} ),
				} );
			};
			var remove = function ( i ) {
				props.setAttributes( { items: items.filter( function ( it, j ) { return j !== i; } ) } );
			};
			var add = function () {
				props.setAttributes( { items: items.concat( [ { q: '', a: '' } ] ) } );
			};

			return el(
				'div',
				blockEditor.useBlockProps( { className: 'greenlight-faq-editor' } ),
				el( 'p', { className: 'greenlight-faq-editor-note' }, 'FAQ: use questions people really ask, answered from the business\'s own copy. Adds FAQPage schema.' ),
				items.map( function ( it, i ) {
					return el(
						'div',
						{ key: i, className: 'greenlight-faq-editor-item', style: { borderTop: '1px solid #ddd', paddingTop: '12px', marginTop: '12px' } },
						el( components.TextControl, {
							label: 'Question ' + ( i + 1 ),
							value: it.q,
							onChange: function ( v ) { update( i, 'q', v ); },
						} ),
						el( components.TextareaControl, {
							label: 'Answer',
							help: 'Plain text; links, bold and lists are kept.',
							value: it.a,
							onChange: function ( v ) { update( i, 'a', v ); },
						} ),
						items.length > 1 ? el( components.Button, { isDestructive: true, variant: 'link', onClick: function () { remove( i ); } }, 'Remove this question' ) : null
					);
				} ),
				el( components.Button, { variant: 'secondary', onClick: add, style: { marginTop: '12px' } }, 'Add a question' )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components );
