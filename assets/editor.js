/* Greenlight SEO edit-screen box: checks (re-run after each save) and internal links. No build step. */
( function () {
	var box = document.querySelector( '.glseo-box' );
	if ( ! box || ! window.wp || ! window.wp.apiFetch ) {
		return;
	}
	var state = JSON.parse( box.getAttribute( 'data-state' ) || '{}' );
	var checksEl = box.querySelector( '.glseo-checks' );
	var linksEl = box.querySelector( '.glseo-links' );
	var SEV = { high: 'High', medium: 'Medium', low: 'Low', info: 'Note' };

	function h( tag, cls, text ) {
		var e = document.createElement( tag );
		if ( cls ) {
			e.className = cls;
		}
		if ( text !== undefined ) {
			e.textContent = text;
		}
		return e;
	}
	function note( el, text ) {
		el.replaceChildren( h( 'p', 'glseo-note', text ) );
	}

	function showCheck( r ) {
		if ( ! state.checksOn ) {
			return note( checksEl, 'Checks are switched off in Greenlight SEO.' );
		}
		if ( ! state.published ) {
			return note( checksEl, 'Publish the page to check it (search engines only see published pages).' );
		}
		if ( ! r ) {
			return note( checksEl, 'Not checked yet.' );
		}
		var list = h( 'ul', 'glseo-issues' );
		if ( ! r.issues.length ) {
			list.append( h( 'li', 'glseo-ok', 'No problems: one title, one H1, indexable, in the sitemap, canonical points here.' ) );
		}
		r.issues.forEach( function ( i ) {
			var li = h( 'li', 'glseo-sev-' + i.severity );
			li.append( h( 'span', 'glseo-badge', SEV[ i.severity ] || i.severity ), document.createTextNode( ' ' + i.text ) );
			list.append( li );
		} );
		var f = r.facts || {};
		var facts = h( 'p', 'glseo-facts' );
		facts.textContent = [ f.title ? 'Title: ' + f.title : '', f.canonical ? 'Canonical: ' + f.canonical : '', f.in_sitemap === true ? 'In the sitemap' : f.in_sitemap === false ? 'Not in the sitemap' : '', 'Checked ' + new Date( r.at * 1000 ).toLocaleString() ].filter( Boolean ).join( ' · ' );
		checksEl.replaceChildren( list, facts );
	}

	function runCheck() {
		if ( ! state.checksOn || ! state.published ) {
			return showCheck( null );
		}
		note( checksEl, 'Checking the page as search engines see it…' );
		wp.apiFetch( { path: '/greenlight-seo/v1/check/' + state.id, method: 'POST' } ).then( showCheck ).catch( function ( e ) {
			note( checksEl, 'The check could not run: ' + ( e.message || e.code ) );
		} );
	}

	function showLinks( r ) {
		var wrap = document.createDocumentFragment();
		var inb = h( 'p', 'glseo-note' );
		inb.textContent = r.inbound ? ( r.inbound === 1 ? '1 page links here from its content' : r.inbound + ' pages link here from their content' ) + ( r.in_menu ? ', and the menu does.' : '.' ) : 'No other page links here from its content' + ( r.in_menu ? ' (only the menu does).' : ', and it isn\'t in a menu: an orphan page.' );
		wrap.append( inb );
		if ( r.suggestions === null ) {
			wrap.append( h( 'p', 'glseo-note', 'Link suggestions are off: ' + ( r.why_off || 'switched off' ) + '.' ) );
		} else if ( ! r.suggestions.length ) {
			wrap.append( h( 'p', 'glseo-note', 'No link suggestions: no other page\'s title, keyphrase or service appears in this text yet.' ) );
		} else {
			wrap.append( h( 'p', 'glseo-sub', 'Worth linking from this page:' ) );
			var ul = h( 'ul', 'glseo-sugg' );
			r.suggestions.forEach( function ( s ) {
				var li = h( 'li' );
				var a = h( 'a', '', s.title );
				a.href = /^https?:\/\//.test( s.url ) ? s.url : '#';
				a.target = '_blank';
				var btn = h( 'button', 'button button-small', 'Copy link' );
				btn.type = 'button';
				btn.addEventListener( 'click', function () {
					navigator.clipboard.writeText( s.url ).then( function () {
						btn.textContent = 'Copied';
						setTimeout( function () { btn.textContent = 'Copy link'; }, 1500 );
					} );
				} );
				li.append( a, h( 'span', 'glseo-why', ' ' + s.reason + ' ' ), btn );
				ul.append( li );
			} );
			wrap.append( ul );
		}
		linksEl.replaceChildren( wrap );
	}

	function currentContent() {
		try {
			return wp.data.select( 'core/editor' ).getEditedPostContent();
		} catch ( e ) {
			var ta = document.getElementById( 'content' );
			return ta ? ta.value : null;
		}
	}

	function runLinks() {
		note( linksEl, 'Looking for pages to link…' );
		var content = currentContent();
		wp.apiFetch( { path: '/greenlight-seo/v1/links/' + state.id, method: 'POST', data: content !== null ? { content: content } : {} } ).then( showLinks ).catch( function ( e ) {
			note( linksEl, 'Could not load links: ' + ( e.message || e.code ) );
		} );
	}

	box.querySelector( '.glseo-run' ).addEventListener( 'click', runCheck );
	box.querySelector( '.glseo-links-run' ).addEventListener( 'click', runLinks );

	// First view: the stored result, or a fresh check when the page changed since.
	if ( state.stale && state.published && state.checksOn ) {
		runCheck();
	} else {
		showCheck( state.check );
	}
	runLinks();

	// Block editor: check again after every save (not autosaves).
	if ( window.wp.data && wp.data.select( 'core/editor' ) ) {
		var wasSaving = false;
		wp.data.subscribe( function () {
			var ed = wp.data.select( 'core/editor' );
			var saving = ed.isSavingPost() && ! ed.isAutosavingPost();
			if ( wasSaving && ! saving && ed.didPostSaveRequestSucceed() ) {
				state.published = ed.getEditedPostAttribute( 'status' ) === 'publish';
				setTimeout( function () { runCheck(); runLinks(); }, 300 );
			}
			wasSaving = saving;
		} );
	}

	// Show the service fields only when the box is ticked.
	var tick = box.querySelector( 'input[name="greenlight_seo_service[on]"]' );
	var fields = box.querySelector( '.glseo-service' );
	var sync = function () { fields.hidden = ! tick.checked; };
	tick.addEventListener( 'change', sync );
	sync();
} )();
