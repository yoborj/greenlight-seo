<?php
namespace Greenlight_SEO;

defined( 'ABSPATH' ) || exit;

/**
 * Internal links: an index of the links stored in every page's content (block/classic content and Elementor
 * data), built in small background batches and kept current on save. From it: orphan pages (no links from
 * other pages' content), dead ends (no links out), links to missing or redirected pages, and, in the editor,
 * suggestions of pages to link from the one being written (pages whose title, focus keyphrase, service name or
 * location city appear in its text and aren't linked yet). Service and location pages rank first: they are the
 * pages that bring in business.
 */
class Links {

	const META    = '_greenlight_seo_links';
	const BATCH   = 'greenlight_seo_links_batch';
	const QUEUE   = 'greenlight_seo_links_queue';
	const INBOUND = 'greenlight_seo_inbound'; // [ target id => [ source ids ] ]

	const STOP = array( 'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'for', 'from', 'how', 'in', 'is', 'it', 'of', 'on', 'or', 'our', 'the', 'to', 'we', 'what', 'when', 'why', 'with', 'you', 'your', 'about', 'us', 'home', 'page', 'contact' );

	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'on_save' ), 30, 2 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_status' ), 10, 3 );
		add_action( self::BATCH, array( __CLASS__, 'batch' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'rest' ) );
	}

	public static function on_save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'publish' !== $post->post_status || ! in_array( $post->post_type, Checks::post_types(), true ) ) {
			return;
		}
		self::index( $post_id, false );
		self::rebuild_inbound();
		// Links that don't map to a page get an HTTP check in the background.
		$queue = (array) get_option( self::QUEUE, array() );
		array_unshift( $queue, (int) $post_id );
		update_option( self::QUEUE, array_values( array_unique( $queue ) ), false );
		if ( ! wp_next_scheduled( self::BATCH ) ) {
			wp_schedule_single_event( time() + 15, self::BATCH );
		}
	}

	public static function on_status( $new, $old, $post ) {
		if ( 'publish' === $old && 'publish' !== $new ) {
			delete_post_meta( $post->ID, self::META );
			self::rebuild_inbound();
		}
	}

	public static function start_index() {
		$ids = get_posts( array( 'post_type' => Checks::post_types(), 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ) );
		update_option( self::QUEUE, $ids, false );
		wp_clear_scheduled_hook( self::BATCH );
		wp_schedule_single_event( time(), self::BATCH );
		return count( $ids );
	}

	public static function batch() {
		$queue = (array) get_option( self::QUEUE, array() );
		$start = time();
		while ( $queue && time() - $start < 20 ) {
			$id = (int) array_shift( $queue );
			if ( 'publish' === get_post_status( $id ) ) {
				self::index( $id, true );
				Checks::index_title( $id );
			}
		}
		update_option( self::QUEUE, $queue, false );
		self::rebuild_inbound();
		if ( $queue ) {
			wp_schedule_single_event( time() + 5, self::BATCH );
		}
	}

	public static function queued() {
		return count( (array) get_option( self::QUEUE, array() ) );
	}

	// ---------------------------------------------------------------- Reading links out of content.

	/** Everything a page stores that holds its links: post content, plus Elementor's data when it's built with Elementor. */
	public static function content( $post_id ) {
		$text = (string) get_post_field( 'post_content', $post_id );
		if ( 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
			$text .= ' ' . wp_unslash( (string) get_post_meta( $post_id, '_elementor_data', true ) );
		}
		return $text;
	}

	/** Internal URLs in the content (absolute), without anchors-only, mail, phone and file links. */
	public static function urls( $content ) {
		preg_match_all( '/href=["\']([^"\']+)["\']|"url"\s*:\s*"([^"]+)"/i', (string) $content, $m, PREG_SET_ORDER );
		$home = wp_parse_url( home_url( '/' ) );
		$host = preg_replace( '/^www\./', '', strtolower( $home['host'] ?? '' ) );
		$out  = array();
		foreach ( $m as $match ) {
			$u = html_entity_decode( str_replace( '\\/', '/', $match[1] ?: $match[2] ), ENT_QUOTES, 'UTF-8' );
			if ( '' === $u || '#' === $u[0] || preg_match( '/^(mailto|tel|sms|javascript):/i', $u ) ) {
				continue;
			}
			if ( '/' === $u[0] && ( ! isset( $u[1] ) || '/' !== $u[1] ) ) {
				$u = untrailingslashit( home_url() ) . $u;
			}
			$p = wp_parse_url( $u );
			if ( empty( $p['host'] ) || preg_replace( '/^www\./', '', strtolower( $p['host'] ) ) !== $host ) {
				continue;
			}
			$path = $p['path'] ?? '/';
			if ( preg_match( '#/wp-(content|admin|includes|json)/|\.(jpe?g|png|gif|webp|svg|pdf|zip|docx?|xlsx?|mp4|mp3)$#i', $path ) ) {
				continue;
			}
			$out[] = strtok( $u, '#' );
		}
		return array_values( array_unique( $out ) );
	}

	/** Indexes one page: the published pages it links to, and (with $http) links to missing or redirected pages. */
	public static function index( $post_id, $http = true ) {
		$targets    = array();
		$broken     = array();
		$redirected = array();
		$checked    = 0;
		foreach ( self::urls( self::content( $post_id ) ) as $u ) {
			$id = url_to_postid( $u );
			if ( ! $id && untrailingslashit( strtok( $u, '?' ) ) === untrailingslashit( home_url() ) ) {
				$id = (int) get_option( 'page_on_front' );
			}
			if ( $id && 'publish' === get_post_status( $id ) ) {
				if ( (int) $id !== (int) $post_id ) {
					$targets[] = (int) $id;
				}
				continue;
			}
			if ( ! $http || $checked >= 15 ) {
				continue;
			}
			$checked++;
			$r    = wp_remote_head( $u, array( 'timeout' => 8, 'redirection' => 0, 'sslverify' => apply_filters( 'https_local_ssl_verify', false ) ) );
			$code = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
			if ( $code >= 300 && $code < 400 ) {
				$redirected[] = array( $u, (string) wp_remote_retrieve_header( $r, 'location' ) );
			} elseif ( 404 === $code || 410 === $code ) {
				$broken[] = $u;
			}
		}
		$data = array( 'out' => array_values( array_unique( $targets ) ), 'broken' => $broken, 'redirected' => $redirected, 'at' => time() );
		if ( ! $http ) {
			// A save without HTTP keeps the last background results for links it couldn't map.
			$old                = get_post_meta( $post_id, self::META, true );
			$data['broken']     = is_array( $old ) ? ( $old['broken'] ?? array() ) : array();
			$data['redirected'] = is_array( $old ) ? ( $old['redirected'] ?? array() ) : array();
		}
		update_post_meta( $post_id, self::META, $data );
		return $data;
	}

	public static function rebuild_inbound() {
		global $wpdb;
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT m.post_id, m.meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_status = 'publish'", self::META ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inbound = array();
		foreach ( $rows as $row ) {
			$d = maybe_unserialize( $row->meta_value );
			foreach ( (array) ( $d['out'] ?? array() ) as $t ) {
				$inbound[ (int) $t ][] = (int) $row->post_id;
			}
		}
		update_option( self::INBOUND, $inbound, false );
		return $inbound;
	}

	/** Pages linked from a navigation menu. */
	public static function in_menus() {
		$ids = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			foreach ( (array) wp_get_nav_menu_items( $menu ) as $item ) {
				if ( 'post_type' === $item->type ) {
					$ids[] = (int) $item->object_id;
				} elseif ( ! empty( $item->url ) ) {
					$id = url_to_postid( $item->url );
					if ( $id ) {
						$ids[] = (int) $id;
					}
				}
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * The site's link health: orphans (no content links in; flagged "in_menu" when the menu still reaches them),
	 * dead ends, broken and redirected links, and the most-linked pages.
	 */
	public static function report() {
		$inbound = get_option( self::INBOUND, array() );
		$menu    = self::in_menus();
		$front   = (int) get_option( 'page_on_front' );
		$ids     = get_posts( array( 'post_type' => Checks::post_types(), 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ) );
		$out     = array( 'pages' => count( $ids ), 'indexed' => 0, 'orphans' => array(), 'dead_ends' => array(), 'broken' => array(), 'redirected' => array(), 'top' => array() );
		foreach ( $ids as $id ) {
			$d = get_post_meta( $id, self::META, true );
			if ( ! is_array( $d ) ) {
				continue;
			}
			$out['indexed']++;
			if ( $id !== $front && empty( $inbound[ $id ] ) && ! Checks::is_noindex( $id ) ) {
				$out['orphans'][] = array( 'id' => $id, 'in_menu' => in_array( $id, $menu, true ) );
			}
			if ( empty( $d['out'] ) ) {
				$out['dead_ends'][] = $id;
			}
			foreach ( (array) $d['broken'] as $u ) {
				$out['broken'][] = array( 'id' => $id, 'url' => $u );
			}
			foreach ( (array) $d['redirected'] as $r ) {
				$out['redirected'][] = array( 'id' => $id, 'url' => $r[0], 'to' => $r[1] );
			}
		}
		// Orphans nobody can reach (not even from the menu) first.
		usort( $out['orphans'], function ( $a, $b ) { return (int) $a['in_menu'] - (int) $b['in_menu']; } );
		$counts = array_map( 'count', $inbound );
		arsort( $counts );
		foreach ( array_slice( $counts, 0, 10, true ) as $id => $n ) {
			$out['top'][] = array( 'id' => (int) $id, 'inbound' => $n );
		}
		return $out;
	}

	// ---------------------------------------------------------------- Suggestions.

	private static function words( $text ) {
		$words = preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );
		return array_values( array_filter( $words, function ( $w ) { return strlen( $w ) > 2 && ! in_array( $w, self::STOP, true ); } ) );
	}

	/** A page's title without the site-name suffix ("Drain Cleaning | Acme Plumbing" → "Drain Cleaning"). */
	private static function clean_title( $id ) {
		$t = html_entity_decode( wp_strip_all_tags( get_the_title( $id ) ), ENT_QUOTES, 'UTF-8' );
		return trim( preg_split( '/\s+[|–—-]\s+/u', $t )[0] );
	}

	/** Phrases that, found in another page's text, make a good anchor for a link to this page. */
	private static function phrases( $id ) {
		$p = array( self::clean_title( $id ) );
		foreach ( array( '_yoast_wpseo_focuskw', 'rank_math_focus_keyword' ) as $key ) {
			foreach ( explode( ',', (string) get_post_meta( $id, $key, true ) ) as $kw ) {
				$p[] = trim( $kw );
			}
		}
		$service = Schema::service_meta( $id );
		if ( $service && '' !== trim( $service['name'] ?? '' ) ) {
			$p[] = trim( $service['name'] );
		}
		$place = Business::place_for_page( $id );
		if ( $place && ! $place['main'] && '' !== $place['city'] ) {
			$p[] = $place['city'];
		}
		// A one-word title that is an everyday word ("About", "Contact") isn't an anchor.
		return array_values( array_unique( array_filter( $p, function ( $x ) { return mb_strlen( $x ) >= 4 && ! in_array( mb_strtolower( $x ), self::STOP, true ); } ) ) );
	}

	/** Pages worth linking from $post_id (optionally for unsaved $content): [ { id, title, url, anchor, reason, score } ]. */
	public static function suggestions( $post_id, $content = null, $limit = 8 ) {
		$content = null !== $content ? (string) $content : self::content( $post_id );
		$plain   = ' ' . mb_strtolower( html_entity_decode( wp_strip_all_tags( preg_replace( '/<!--.*?-->/s', ' ', $content ) ), ENT_QUOTES, 'UTF-8' ) ) . ' ';
		$plain   = preg_replace( '/\s+/u', ' ', $plain );
		$linked  = array();
		foreach ( self::urls( $content ) as $u ) {
			$linked[] = (int) url_to_postid( $u );
		}
		$mine    = array_flip( self::words( $plain ) );
		$titles  = get_option( Checks::TITLES, array() );
		$ids     = get_posts( array( 'post_type' => Checks::post_types(), 'post_status' => 'publish', 'numberposts' => 2000, 'fields' => 'ids', 'post__not_in' => array( (int) $post_id ), 'has_password' => false ) );
		$out     = array();
		foreach ( $ids as $id ) {
			if ( in_array( $id, $linked, true ) || ! empty( $titles[ $id ][1] ) ) {
				continue; // Already linked, or noindex.
			}
			$score  = 0;
			$anchor = '';
			$reason = '';
			foreach ( self::phrases( $id ) as $phrase ) {
				// Whole words only: "plumb" doesn't match "plumbing".
				if ( ! preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $phrase, '/' ) . '(?![\p{L}\p{N}])/iu', $plain, $m ) ) {
					continue;
				}
				$s = 10 + min( 10, mb_strlen( $phrase ) / 4 );
				if ( $s > $score ) {
					$score  = $s;
					$anchor = trim( $m[0] );
					$reason = 'Your text mentions “' . $anchor . '”.';
				}
			}
			if ( ! $score ) {
				// Weaker: most of the target title's words appear in the text.
				$tw = self::words( self::clean_title( $id ) );
				if ( count( $tw ) >= 2 ) {
					$hits = count( array_filter( $tw, function ( $w ) use ( $mine ) { return isset( $mine[ $w ] ); } ) );
					if ( $hits >= 2 && $hits / count( $tw ) >= 0.66 ) {
						$score  = 3 + $hits;
						$reason = 'Your text uses ' . $hits . ' of the words in its title.';
					}
				}
			}
			if ( ! $score ) {
				continue;
			}
			if ( Schema::service_meta( $id ) ) {
				$score  += 5;
				$reason .= ' Service page.';
			} elseif ( ( $place = Business::place_for_page( $id ) ) && ! $place['main'] ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
				$score  += 5;
				$reason .= ' Location page.';
			}
			$out[] = array( 'id' => $id, 'title' => self::clean_title( $id ), 'url' => get_permalink( $id ), 'anchor' => $anchor, 'reason' => trim( $reason ), 'score' => round( $score, 1 ) );
		}
		usort( $out, function ( $a, $b ) { return $b['score'] <=> $a['score']; } );
		return array_slice( $out, 0, $limit );
	}

	public static function rest() {
		register_rest_route( 'greenlight-seo/v1', '/links/(?P<id>\d+)', array(
			'methods'             => array( 'GET', 'POST' ),
			'permission_callback' => function ( $req ) {
				return current_user_can( 'edit_post', (int) $req['id'] );
			},
			'callback'            => function ( $req ) {
				$id      = (int) $req['id'];
				$inbound = get_option( self::INBOUND, array() );
				$from    = array_map( function ( $s ) { return array( 'id' => $s, 'title' => get_the_title( $s ), 'url' => get_permalink( $s ) ); }, array_slice( $inbound[ $id ] ?? array(), 0, 20 ) );
				$content = $req->get_param( 'content' );
				return rest_ensure_response( array(
					'inbound'     => count( $inbound[ $id ] ?? array() ),
					'from'        => $from,
					'in_menu'     => in_array( $id, self::in_menus(), true ),
					'outbound'    => count( self::urls( null !== $content ? $content : self::content( $id ) ) ),
					'suggestions' => Detect::on( 'link_suggestions' ) ? self::suggestions( $id, $content ) : null,
					'why_off'     => Detect::features()['link_suggestions']['why'],
				) );
			},
		) );
	}
}
