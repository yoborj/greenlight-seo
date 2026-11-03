<?php
namespace Greenlight_SEO;

defined( 'ABSPATH' ) || exit;

/**
 * An optional /llms.txt (llmstxt.org): the business, its services, locations, main pages and recent posts, as
 * Markdown links for AI assistants. Off by default, and never served while Yoast SEO's or Rank Math's own
 * llms.txt is on or an llms.txt file sits on the server. Noindex pages are left out.
 */
class Llms {

	const CACHE = 'greenlight_seo_llms';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'serve' ), 1 );
		add_action( 'save_post', array( __CLASS__, 'flush' ) );
		add_action( 'update_option_' . Settings::OPTION, array( __CLASS__, 'flush' ) );
	}

	public static function flush() {
		delete_transient( self::CACHE );
	}

	public static function url() {
		return home_url( '/llms.txt' );
	}

	public static function serve() {
		$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$home = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		if ( $home . '/llms.txt' !== $path || ! Detect::on( 'llms_txt' ) ) {
			return;
		}
		$text = get_transient( self::CACHE );
		if ( false === $text ) {
			$text = self::build();
			set_transient( self::CACHE, $text, HOUR_IN_SECONDS );
		}
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo $text; // phpcs:ignore WordPress.Security.EscapeOutput -- plain text.
		exit;
	}

	private static function line( $id, $desc = '' ) {
		$title = html_entity_decode( wp_strip_all_tags( get_the_title( $id ) ), ENT_QUOTES, 'UTF-8' );
		$desc  = '' !== $desc ? $desc : self::description( $id );
		return '- [' . str_replace( array( '[', ']' ), '', $title ) . '](' . get_permalink( $id ) . ')' . ( '' !== $desc ? ': ' . $desc : '' );
	}

	private static function description( $id ) {
		$d = '';
		foreach ( array( '_yoast_wpseo_metadesc', 'rank_math_description' ) as $key ) {
			$d = $d ?: (string) get_post_meta( $id, $key, true );
		}
		if ( '' === $d ) {
			$d = (string) get_post_field( 'post_excerpt', $id );
		}
		$d = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( html_entity_decode( $d, ENT_QUOTES, 'UTF-8' ) ) ) );
		return false === strpos( $d, '%%' ) ? $d : ''; // An unrendered Yoast variable isn't a description.
	}

	private static function indexable( $id ) {
		return ! Checks::is_noindex( $id );
	}

	public static function build() {
		$s     = Settings::all();
		$main  = Business::main();
		$intro = trim( $s['llms_intro'] ) ?: trim( $s['description'] ) ?: get_bloginfo( 'description' );
		$out   = array( '# ' . $main['name'], '' );
		if ( '' !== $intro ) {
			$out[] = '> ' . preg_replace( '/\s+/', ' ', $intro );
			$out[] = '';
		}
		$contact = array_filter( array( Business::address_line( $main ), $main['phone'], $main['email'] ), 'strlen' );
		if ( $contact ) {
			$out[] = implode( ' · ', $contact );
			$areas = Settings::lines( 'area_served' );
			if ( $areas ) {
				$out[] = 'Areas served: ' . implode( ', ', $areas ) . '.';
			}
			$out[] = '';
		}

		$ids      = get_posts( array( 'post_type' => Checks::post_types(), 'post_status' => 'publish', 'numberposts' => 500, 'fields' => 'ids', 'has_password' => false, 'orderby' => 'menu_order title', 'order' => 'ASC' ) );
		$ids      = array_values( array_filter( $ids, array( __CLASS__, 'indexable' ) ) );
		$services = array_values( array_filter( $ids, function ( $id ) { return (bool) Schema::service_meta( $id ); } ) );
		$places   = array();
		foreach ( Business::places() as $p ) {
			if ( ! $p['main'] && $p['page_id'] && in_array( (int) $p['page_id'], $ids, true ) ) {
				$places[ (int) $p['page_id'] ] = $p;
			}
		}
		if ( $services ) {
			$out[] = '## Services';
			$out[] = '';
			foreach ( $services as $id ) {
				$m     = Schema::service_meta( $id );
				$out[] = self::line( $id, trim( wp_strip_all_tags( $m['description'] ?? '' ) ) );
			}
			$out[] = '';
		}
		if ( $places ) {
			$out[] = '## Locations';
			$out[] = '';
			foreach ( $places as $id => $p ) {
				$out[] = self::line( $id, implode( ' · ', array_filter( array( Business::address_line( $p ), $p['phone'] ), 'strlen' ) ) );
			}
			$out[] = '';
		}
		$pages = array_filter( $ids, function ( $id ) use ( $services, $places ) {
			return 'page' === get_post_type( $id ) && ! get_post_field( 'post_parent', $id ) && ! in_array( $id, $services, true ) && ! isset( $places[ $id ] ) && ! in_array( $id, array( (int) get_option( 'wp_page_for_privacy_policy' ), (int) get_option( 'page_on_front' ) ), true );
		} );
		if ( $pages ) {
			$out[] = '## Pages';
			$out[] = '';
			foreach ( $pages as $id ) {
				$out[] = self::line( $id );
			}
			$out[] = '';
		}
		$posts = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 10, 'fields' => 'ids', 'date_query' => array( array( 'after' => '12 months ago' ) ) ) );
		$posts = array_values( array_filter( $posts, array( __CLASS__, 'indexable' ) ) );
		if ( $posts ) {
			$out[] = '## Optional';
			$out[] = '';
			foreach ( $posts as $id ) {
				$out[] = self::line( $id );
			}
			$out[] = '';
		}
		return implode( "\n", $out );
	}
}
