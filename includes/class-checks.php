<?php
namespace Greenlight_SEO;

defined( 'ABSPATH' ) || exit;

/**
 * Indexing and on-page checks, run when a page is saved (and on demand): the page is fetched the way a search
 * engine sees it (logged out) and compared with the sitemap and the rest of the site.
 *
 * Indexing: noindex pages still in the sitemap, more than one canonical, a canonical pointing elsewhere or at a
 * page that doesn't answer 200, a theme or plugin overriding the SEO plugin's canonical, pages that redirect,
 * indexable pages missing from the sitemap. Titles: missing, more than one, duplicated on another page, and
 * (optional house style) not ending with "| Site name". Headings and images: not exactly one H1, the H1 being
 * the SEO title word for word, content images without alt text.
 *
 * Read-only: titles, robots and canonicals are the SEO plugin's job; this only reports.
 */
class Checks {

	const META   = '_greenlight_seo_check';  // Last result: at, issues, facts.
	const COUNT  = '_greenlight_seo_issues'; // Number of high + medium issues, for the site-wide list.
	const TITLES = 'greenlight_seo_titles';  // Site-wide title index: [ post id => [ normalized title, noindex ] ].
	const EVENT  = 'greenlight_seo_check';   // One page, in the background after a save.
	const BATCH  = 'greenlight_seo_check_batch';

	/** Sitemaps fetched during this request: [ url => [ locs ] ]. */
	private static $sitemaps = array();

	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'on_save' ), 20, 2 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_status' ), 10, 3 );
		add_action( self::EVENT, array( __CLASS__, 'run' ) );
		add_action( self::BATCH, array( __CLASS__, 'batch' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'rest' ) );
	}

	/** Public post types whose single pages are indexable in principle. */
	public static function post_types() {
		return array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
	}

	public static function checkable( $post ) {
		$post = get_post( $post );
		return $post && 'publish' === $post->post_status && in_array( $post->post_type, self::post_types(), true ) && '' === $post->post_password;
	}

	public static function on_save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! Detect::on( 'checks_on_save' ) || ! self::checkable( $post ) ) {
			return;
		}
		// Background run for saves the editor screen doesn't follow up (quick edit, page builders, REST).
		wp_clear_scheduled_hook( self::EVENT, array( (int) $post_id ) );
		wp_schedule_single_event( time() + 10, self::EVENT, array( (int) $post_id ) );
	}

	public static function on_status( $new, $old, $post ) {
		if ( 'publish' === $old && 'publish' !== $new ) {
			$titles = get_option( self::TITLES, array() );
			unset( $titles[ $post->ID ] );
			update_option( self::TITLES, $titles, false );
			delete_post_meta( $post->ID, self::COUNT );
		}
	}

	public static function rest() {
		register_rest_route( 'greenlight-seo/v1', '/check/(?P<id>\d+)', array(
			'methods'             => array( 'GET', 'POST' ),
			'permission_callback' => function ( $req ) {
				return current_user_can( 'edit_post', (int) $req['id'] );
			},
			'callback'            => function ( $req ) {
				$id = (int) $req['id'];
				if ( 'POST' === $req->get_method() ) {
					return rest_ensure_response( self::run( $id ) );
				}
				return rest_ensure_response( self::last( $id ) );
			},
		) );
	}

	public static function last( $post_id ) {
		$r = get_post_meta( $post_id, self::META, true );
		return is_array( $r ) ? $r : null;
	}

	/** Whether the last result is older than the page's last edit. */
	public static function stale( $post_id ) {
		$r = self::last( $post_id );
		return ! $r || strtotime( get_post_field( 'post_modified_gmt', $post_id ) . ' UTC' ) > (int) $r['at'];
	}

	// ---------------------------------------------------------------- The SEO plugin's view (no HTTP).

	/** The title the SEO plugin will output (Yoast exactly; otherwise WordPress's default "Title – Site"). */
	public static function expected_title( $post_id ) {
		if ( Detect::yoast() && function_exists( 'YoastSEO' ) ) {
			try {
				$meta = YoastSEO()->meta->for_post( $post_id );
				if ( $meta && '' !== (string) $meta->title ) {
					return (string) $meta->title;
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
				// Fall through.
			}
		}
		if ( (int) get_option( 'page_on_front' ) === (int) $post_id ) {
			return trim( get_bloginfo( 'name' ) . ' – ' . get_bloginfo( 'description' ), ' –' );
		}
		return wp_strip_all_tags( get_the_title( $post_id ) ) . ' – ' . get_bloginfo( 'name' );
	}

	/** Whether the SEO plugin marks the page noindex. */
	public static function expected_noindex( $post_id ) {
		if ( ! get_option( 'blog_public' ) ) {
			return true;
		}
		if ( Detect::yoast() && function_exists( 'YoastSEO' ) ) {
			try {
				$robots = YoastSEO()->meta->for_post( $post_id )->robots;
				return isset( $robots['index'] ) && 'noindex' === $robots['index'];
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
				// Fall through.
			}
		}
		if ( Detect::rank_math() ) {
			$robots = get_post_meta( $post_id, 'rank_math_robots', true );
			return is_array( $robots ) && in_array( 'noindex', $robots, true );
		}
		return false;
	}

	/** Noindex by the SEO plugin, or as last seen on the page (a theme or another plugin can add it). */
	public static function is_noindex( $post_id ) {
		$last = self::last( $post_id );
		return self::expected_noindex( $post_id ) || ! empty( $last['facts']['noindex'] );
	}

	public static function normalize_title( $t ) {
		return strtolower( trim( preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( (string) $t ), ENT_QUOTES, 'UTF-8' ) ) ) );
	}

	/** Records a page's title in the site-wide index. */
	public static function index_title( $post_id, $title = null, $noindex = null ) {
		$titles             = get_option( self::TITLES, array() );
		$titles[ $post_id ] = array(
			self::normalize_title( null !== $title ? $title : self::expected_title( $post_id ) ),
			null !== $noindex ? (bool) $noindex : self::expected_noindex( $post_id ),
		);
		update_option( self::TITLES, $titles, false );
	}

	/** Other indexable published pages with the same title. */
	public static function duplicates( $post_id, $title ) {
		$norm = self::normalize_title( $title );
		$out  = array();
		if ( '' === $norm ) {
			return $out;
		}
		foreach ( get_option( self::TITLES, array() ) as $id => $t ) {
			if ( (int) $id !== (int) $post_id && $t[0] === $norm && empty( $t[1] ) && 'publish' === get_post_status( $id ) ) {
				$out[] = (int) $id;
			}
		}
		return $out;
	}

	/** Site-wide: groups of indexable pages sharing a title. [ title => [ ids ] ] */
	public static function duplicate_groups() {
		$by = array();
		foreach ( get_option( self::TITLES, array() ) as $id => $t ) {
			if ( '' !== $t[0] && empty( $t[1] ) && 'publish' === get_post_status( $id ) ) {
				$by[ $t[0] ][] = (int) $id;
			}
		}
		return array_filter( $by, function ( $ids ) { return count( $ids ) > 1; } );
	}

	// ---------------------------------------------------------------- Fetching.

	private static function fetch( $url, $method = 'GET' ) {
		$args = array(
			'timeout'     => 15,
			'redirection' => 0,
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // Same as WordPress's own loopback requests.
			'headers'     => array( 'Cache-Control' => 'no-cache' ),
			'user-agent'  => 'Mozilla/5.0 (compatible; GreenlightSEO/' . GREENLIGHT_SEO_VERSION . '; +' . home_url( '/' ) . ')',
		);
		$r    = 'HEAD' === $method ? wp_remote_head( $url, $args ) : wp_remote_get( $url, $args );
		if ( is_wp_error( $r ) ) {
			return array( 'code' => 0, 'error' => $r->get_error_message(), 'body' => '', 'headers' => array() );
		}
		return array(
			'code'    => (int) wp_remote_retrieve_response_code( $r ),
			'body'    => (string) wp_remote_retrieve_body( $r ),
			'headers' => wp_remote_retrieve_headers( $r ),
			'error'   => null,
		);
	}

	/** Comparable form of a URL: no scheme, no trailing slash, lowercase host. */
	public static function same_url( $a, $b ) {
		$n = function ( $u ) {
			$p = wp_parse_url( trim( (string) $u ) );
			if ( empty( $p['host'] ) ) {
				return '';
			}
			return strtolower( preg_replace( '/^www\./', '', $p['host'] ) ) . untrailingslashit( $p['path'] ?? '' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
		};
		return $n( $a ) === $n( $b );
	}

	private static function locs( $xml ) {
		preg_match_all( '#<loc>\s*(.*?)\s*</loc>#is', (string) $xml, $m );
		return array_map( function ( $u ) { return html_entity_decode( $u, ENT_QUOTES | ENT_XML1, 'UTF-8' ); }, $m[1] );
	}

	private static function sitemap( $url ) {
		if ( ! isset( self::$sitemaps[ $url ] ) ) {
			$r                      = self::fetch( $url );
			self::$sitemaps[ $url ] = 200 === $r['code'] ? self::locs( $r['body'] ) : null;
		}
		return self::$sitemaps[ $url ];
	}

	/** The sitemap index URL and its child sitemaps, or null when the site has no sitemap. */
	public static function sitemap_index() {
		$candidates = Detect::yoast() || Detect::rank_math() ? array( '/sitemap_index.xml' ) : array( '/wp-sitemap.xml', '/sitemap_index.xml', '/sitemap.xml' );
		foreach ( $candidates as $path ) {
			$locs = self::sitemap( home_url( $path ) );
			if ( null !== $locs && $locs ) {
				$children = array_values( array_filter( $locs, function ( $u ) { return (bool) preg_match( '/\.xml(\?|$)/i', $u ); } ) );
				return array( 'url' => home_url( $path ), 'children' => $children ?: array( home_url( $path ) ), 'flat' => ! $children );
			}
		}
		return null;
	}

	/** Whether the URL is listed in the sitemap (null: no sitemap found). */
	public static function in_sitemap( $url, $post_type ) {
		$index = self::sitemap_index();
		if ( ! $index ) {
			return null;
		}
		$children = $index['children'];
		if ( ! $index['flat'] ) {
			$type     = preg_quote( $post_type, '/' );
			$matching = array_values( array_filter( $children, function ( $u ) use ( $type ) {
				return (bool) preg_match( '/(' . $type . '-sitemap\d*\.xml|wp-sitemaps-posts-' . $type . '-\d+\.xml)/i', $u );
			} ) );
			$children = $matching ?: array_slice( $children, 0, 20 );
		}
		foreach ( $children as $child ) {
			foreach ( (array) self::sitemap( $child ) as $loc ) {
				if ( self::same_url( $loc, $url ) ) {
					return true;
				}
			}
		}
		return false;
	}

	// ---------------------------------------------------------------- Reading the page.

	/** What a search engine sees: titles, robots, canonicals, H1s, images without alt in the main content. */
	public static function read_page( $html, $headers ) {
		$facts = array( 'titles' => array(), 'robots' => array(), 'canonicals' => array(), 'h1' => array(), 'no_alt' => array() );
		$x     = $headers['x-robots-tag'] ?? '';
		foreach ( (array) $x as $v ) {
			if ( '' !== trim( (string) $v ) ) {
				$facts['robots'][] = 'X-Robots-Tag: ' . trim( (string) $v );
			}
		}
		if ( '' === trim( $html ) || ! class_exists( '\\DOMDocument' ) ) {
			return $facts;
		}
		$dom  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$xp = new \DOMXPath( $dom );
		foreach ( $xp->query( '//head/title | //title' ) as $t ) {
			$facts['titles'][] = trim( preg_replace( '/\s+/', ' ', $t->textContent ) );
		}
		$facts['titles'] = array_values( array_unique( $facts['titles'] ) );
		foreach ( $xp->query( '//meta[@name]' ) as $m ) {
			$name = strtolower( $m->getAttribute( 'name' ) );
			if ( in_array( $name, array( 'robots', 'googlebot' ), true ) ) {
				$facts['robots'][] = $name . ': ' . $m->getAttribute( 'content' );
			}
		}
		foreach ( $xp->query( '//link[@rel]' ) as $l ) {
			if ( in_array( 'canonical', preg_split( '/\s+/', strtolower( $l->getAttribute( 'rel' ) ) ), true ) ) {
				$facts['canonicals'][] = $l->getAttribute( 'href' );
			}
		}
		foreach ( $xp->query( '//body//h1' ) as $h ) {
			$facts['h1'][] = trim( preg_replace( '/\s+/', ' ', $h->textContent ) );
		}
		// Main content: <main>, else the first <article>, else the body without header/footer/nav.
		$scope = $xp->query( '//main' )->item( 0 ) ?: $xp->query( '//article' )->item( 0 );
		$imgs  = $scope ? $xp->query( './/img', $scope ) : $xp->query( '//body//img[not(ancestor::header) and not(ancestor::footer) and not(ancestor::nav)]' );
		foreach ( $imgs as $img ) {
			$src = $img->getAttribute( 'src' ) ?: $img->getAttribute( 'data-src' );
			$w   = (int) $img->getAttribute( 'width' );
			if ( '' === $src || 0 === strpos( $src, 'data:' ) || ( $w && $w <= 2 ) || 'presentation' === $img->getAttribute( 'role' ) || 'true' === $img->getAttribute( 'aria-hidden' ) ) {
				continue;
			}
			if ( ! $img->hasAttribute( 'alt' ) || '' === trim( $img->getAttribute( 'alt' ) ) ) {
				$facts['no_alt'][] = basename( (string) wp_parse_url( $src, PHP_URL_PATH ) );
			}
		}
		$facts['no_alt'] = array_values( array_unique( $facts['no_alt'] ) );
		return $facts;
	}

	private static function noindex( array $robots ) {
		foreach ( $robots as $r ) {
			if ( preg_match( '/\b(noindex|none)\b/i', $r ) ) {
				return true;
			}
		}
		return false;
	}

	// ---------------------------------------------------------------- The check.

	/** Checks one page, stores and returns { at, url, issues: [ { id, severity, text } ], facts }. */
	public static function run( $post_id ) {
		$post_id = (int) $post_id;
		$issues  = array();
		$add     = function ( $id, $severity, $text ) use ( &$issues ) {
			$issues[] = array( 'id' => $id, 'severity' => $severity, 'text' => $text );
		};
		$url   = get_permalink( $post_id );
		$facts = array( 'title' => null, 'canonical' => null, 'noindex' => null, 'in_sitemap' => null, 'h1' => array() );

		if ( ! self::checkable( $post_id ) ) {
			$add( 'not_checked', 'info', 'Only published, public, non-password-protected pages are checked.' );
			return self::store( $post_id, $url, $issues, $facts );
		}

		$page = self::fetch( add_query_arg( 'greenlight_seo_check', time(), $url ) ); // The query string gets past page caches.
		if ( $page['code'] >= 300 && $page['code'] < 400 && self::same_url( (string) ( $page['headers']['location'] ?? '' ), $url ) ) {
			$page = self::fetch( $url ); // The site strips unknown query strings (e.g. Yoast's crawl cleanup).
		}
		if ( 0 === $page['code'] || $page['code'] >= 400 ) {
			$add( 'fetch_failed', 'info', 'The page could not be fetched to check it (' . ( $page['error'] ?: 'HTTP ' . $page['code'] ) . '). A password-protected staging site answers 401.' );
			return self::store( $post_id, $url, $issues, $facts );
		}
		$in_sitemap          = self::in_sitemap( $url, get_post_type( $post_id ) );
		$facts['in_sitemap'] = $in_sitemap;
		if ( $page['code'] >= 300 ) {
			$to = (string) ( $page['headers']['location'] ?? '' );
			$add( 'redirects', $in_sitemap ? 'high' : 'medium', 'The page redirects (' . $page['code'] . ') to ' . $to . ( $in_sitemap ? ', but the sitemap still lists it.' : '.' ) );
			return self::store( $post_id, $url, $issues, $facts );
		}

		$f                  = self::read_page( $page['body'], $page['headers'] );
		$site_public        = (bool) get_option( 'blog_public' );
		$noindex            = self::noindex( $f['robots'] );
		$facts['noindex']   = $noindex;
		$facts['title']     = $f['titles'][0] ?? '';
		$facts['canonical'] = $f['canonicals'][0] ?? null;
		$facts['h1']        = $f['h1'];
		$facts['robots']    = $f['robots'];

		// Indexing.
		if ( ! $site_public ) {
			$add( 'site_noindex', 'info', '“Discourage search engines” is on (Settings → Reading), so every page is noindex: fine on staging, not after launch. Noindex checks are skipped.' );
		} elseif ( $noindex && $in_sitemap ) {
			$add( 'noindex_in_sitemap', 'high', 'The page is noindex (' . implode( '; ', $f['robots'] ) . ') but the sitemap lists it. Remove it from the sitemap, or make it indexable.' );
		} elseif ( $noindex && ! self::expected_noindex( $post_id ) && Detect::seo_plugin() ) {
			$add( 'noindex_override', 'high', 'The page is noindex (' . implode( '; ', $f['robots'] ) . ') although ' . Detect::seo_plugin() . ' has it as indexable: a theme or another plugin adds the noindex.' );
		} elseif ( ! $noindex && false === $in_sitemap ) {
			$add( 'not_in_sitemap', 'low', 'The page is indexable but not in the sitemap.' );
		}

		$canons = array_values( array_unique( $f['canonicals'] ) );
		if ( count( $f['canonicals'] ) > 1 ) {
			$add( 'canonical_multiple', count( $canons ) > 1 ? 'high' : 'medium', count( $f['canonicals'] ) . ' canonical tags' . ( count( $canons ) > 1 ? ' pointing at different URLs (' . implode( ', ', $canons ) . '): search engines may ignore them all.' : ' (same URL): two plugins or the theme each add one.' ) );
		}
		if ( $canons && ! $noindex ) {
			$canon = $canons[0];
			if ( ! self::same_url( $canon, $url ) ) {
				$add( 'canonical_elsewhere', $in_sitemap ? 'medium' : 'low', 'The canonical points to another URL (' . $canon . ')' . ( $in_sitemap ? ' while the sitemap lists this one: list the canonical URL, or point the canonical here.' : '.' ) );
				$target = self::fetch( $canon, 'HEAD' );
				if ( 200 !== $target['code'] ) {
					$add( 'canonical_target', 'high', 'The canonical URL answers ' . ( $target['code'] ?: $target['error'] ) . ', not 200.' );
				}
			}
			if ( Detect::yoast() && function_exists( 'YoastSEO' ) ) {
				try {
					$expected = (string) YoastSEO()->meta->for_post( $post_id )->canonical;
					if ( '' !== $expected && ! self::same_url( $expected, $canon ) ) {
						$add( 'canonical_override', 'medium', 'Yoast SEO sets the canonical to ' . $expected . ', but the page shows ' . $canon . ': a theme or another plugin overrides it.' );
					}
				} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
					// Yoast's API not available.
				}
			}
		} elseif ( ! $canons && ! $noindex && Detect::seo_plugin() ) {
			$add( 'canonical_missing', 'low', 'No canonical tag on the page.' );
		}

		// Titles.
		$title = $facts['title'];
		if ( '' === $title ) {
			$add( 'title_missing', 'high', 'The page has no <title>.' );
		} else {
			if ( count( $f['titles'] ) > 1 ) {
				$add( 'title_multiple', 'medium', count( $f['titles'] ) . ' different <title> tags: the theme and a plugin each print one.' );
			}
			self::index_title( $post_id, $title, $noindex || ! $site_public );
			$dupes = $noindex ? array() : self::duplicates( $post_id, $title );
			if ( $dupes ) {
				$add( 'title_duplicate', 'medium', 'Same title as ' . implode( ', ', array_map( function ( $id ) { return '“' . get_the_title( $id ) . '” (' . get_permalink( $id ) . ')'; }, array_slice( $dupes, 0, 5 ) ) ) . '.' );
			}
			if ( Settings::get( 'check_title_suffix' ) ) {
				$site = get_bloginfo( 'name' );
				if ( '' !== $site && ! preg_match( '/\|\s*' . preg_quote( $site, '/' ) . '\s*$/u', html_entity_decode( $title, ENT_QUOTES, 'UTF-8' ) ) ) {
					$add( 'title_suffix', 'low', 'The title doesn\'t end with “| ' . $site . '” (house style; in Yoast: end the SEO title with %%sep%% %%sitename%% and use the | separator).' );
				}
			}
		}

		// Headings.
		$h1 = array_values( array_filter( $f['h1'], 'strlen' ) );
		if ( ! $h1 ) {
			$add( 'h1_missing', 'medium', 'The page has no H1.' );
		} elseif ( count( $h1 ) > 1 ) {
			$add( 'h1_multiple', 'medium', count( $h1 ) . ' H1s (' . implode( ' · ', array_map( function ( $h ) { return '“' . $h . '”'; }, array_slice( $h1, 0, 3 ) ) ) . '): keep one.' );
		} elseif ( '' !== $title && self::normalize_title( $h1[0] ) === self::normalize_title( $title ) ) {
			$add( 'h1_is_title', 'low', 'The H1 is the SEO title word for word. Write it as a headline for people: the service and city in a natural sentence.' );
		}

		// Images.
		if ( $f['no_alt'] ) {
			$add( 'img_no_alt', 'low', count( $f['no_alt'] ) . ' image' . ( 1 === count( $f['no_alt'] ) ? '' : 's' ) . ' in the content without alt text: ' . implode( ', ', array_slice( $f['no_alt'], 0, 6 ) ) . ( count( $f['no_alt'] ) > 6 ? ', …' : '' ) . '.' );
		}

		return self::store( $post_id, $url, $issues, $facts );
	}

	private static function store( $post_id, $url, array $issues, array $facts ) {
		$r = array( 'at' => time(), 'url' => $url, 'issues' => $issues, 'facts' => $facts );
		update_post_meta( $post_id, self::META, $r );
		$n = count( array_filter( $issues, function ( $i ) { return in_array( $i['severity'], array( 'high', 'medium' ), true ); } ) );
		if ( $n ) {
			update_post_meta( $post_id, self::COUNT, $n );
		} else {
			delete_post_meta( $post_id, self::COUNT );
		}
		return $r;
	}

	// ---------------------------------------------------------------- The whole site.

	/** Checks every published page in the background, a few per WP-Cron run (each one is an HTTP request). */
	public static function start_all() {
		$ids = get_posts( array( 'post_type' => self::post_types(), 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'has_password' => false ) );
		update_option( 'greenlight_seo_check_queue', $ids, false );
		wp_clear_scheduled_hook( self::BATCH );
		wp_schedule_single_event( time(), self::BATCH );
		return count( $ids );
	}

	public static function batch() {
		$queue = get_option( 'greenlight_seo_check_queue', array() );
		$start = time();
		while ( $queue && time() - $start < 20 ) {
			self::run( array_shift( $queue ) );
		}
		update_option( 'greenlight_seo_check_queue', $queue, false );
		if ( $queue ) {
			wp_schedule_single_event( time() + 5, self::BATCH );
		}
	}

	public static function queued() {
		return count( (array) get_option( 'greenlight_seo_check_queue', array() ) );
	}

	/** Pages with high or medium issues from their last check: [ [ id, count ] ]. */
	public static function flagged( $limit = 200 ) {
		$ids = get_posts( array( 'post_type' => self::post_types(), 'post_status' => 'publish', 'numberposts' => $limit, 'fields' => 'ids', 'meta_key' => self::COUNT, 'orderby' => 'meta_value_num', 'order' => 'DESC' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		return array_map( function ( $id ) { return array( 'id' => $id, 'count' => (int) get_post_meta( $id, self::COUNT, true ) ); }, $ids );
	}
}
