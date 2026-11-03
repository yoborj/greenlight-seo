/* Greenlight SEO settings page: tabs without a reload, and "Add a location". */
( function () {
	var app = document.getElementById( 'gls-app' );
	if ( ! app ) {
		return;
	}
	var referer = app.querySelector( '[data-referer]' );

	function show( slug, push ) {
		app.querySelectorAll( '.gls-tab' ).forEach( function ( t ) {
			t.setAttribute( 'aria-selected', t.dataset.tab === slug ? 'true' : 'false' );
		} );
		app.querySelectorAll( '.gls-panel' ).forEach( function ( p ) {
			p.classList.toggle( 'is-active', p.dataset.panel === slug );
		} );
		var url = new URL( window.location.href );
		url.searchParams.set( 'tab', slug );
		if ( push ) {
			history.replaceState( null, '', url );
		}
		// After saving, come back to the same tab.
		if ( referer ) {
			referer.value = url.pathname + url.search;
		}
	}

	app.addEventListener( 'click', function ( e ) {
		var a = e.target.closest( '[data-tab], [data-goto]' );
		if ( ! a || e.metaKey || e.ctrlKey ) {
			return;
		}
		e.preventDefault();
		show( a.dataset.tab || a.dataset.goto, true );
		window.scrollTo( { top: 0, behavior: 'smooth' } );
	} );

	var add = app.querySelector( '[data-add-location]' );
	var list = app.querySelector( '[data-locations]' );
	var tpl = document.getElementById( 'gls-location-template' );
	if ( add && list && tpl ) {
		add.addEventListener( 'click', function () {
			var i = list.querySelectorAll( '.gls-location' ).length + Date.now() % 1000;
			var html = tpl.innerHTML.replace( /__i__/g, String( i ) );
			var wrap = document.createElement( 'div' );
			wrap.innerHTML = html;
			wrap.querySelectorAll( '[id]' ).forEach( function ( el ) { el.id = el.id + '-' + i; } );
			wrap.querySelectorAll( 'label[for]' ).forEach( function ( el ) { el.htmlFor = el.htmlFor + '-' + i; } );
			var node = wrap.firstElementChild;
			list.appendChild( node );
			var first = node.querySelector( 'input' );
			if ( first ) {
				first.focus();
			}
		} );
	}
} )();
