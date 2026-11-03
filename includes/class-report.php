<?php
namespace Greenlight_SEO;

defined( 'ABSPATH' ) || exit;

/**
 * Greenlight SEO's state in one array: `wp greenlight-seo status --format=json`. Its shape is a contract
 * (readable by the QA app): any change bumps `schema`.
 */
class Report {

	const SCHEMA = 1;

	public static function build() {
		$s        = Settings::all();
		$features = array();
		foreach ( Detect::features() as $k => $f ) {
			$features[ $k ] = array( 'on' => (bool) $f['on'], 'why' => $f['on'] ? null : $f['why'] );
		}
		$pages = array();
		foreach ( Checks::flagged( 100 ) as $row ) {
			$last    = Checks::last( $row['id'] );
			$pages[] = array(
				'id'     => $row['id'],
				'url'    => get_permalink( $row['id'] ),
				'title'  => html_entity_decode( get_the_title( $row['id'] ), ENT_QUOTES, 'UTF-8' ),
				'issues' => array_values( array_filter( (array) ( $last['issues'] ?? array() ), function ( $i ) { return in_array( $i['severity'], array( 'high', 'medium' ), true ); } ) ),
			);
		}
		$dupes = array();
		foreach ( Checks::duplicate_groups() as $title => $ids ) {
			$dupes[] = array( 'title' => $title, 'urls' => array_map( 'get_permalink', $ids ) );
		}
		$links = Links::report();
		return array(
			'greenlight_seo_version' => GREENLIGHT_SEO_VERSION,
			'schema'                 => self::SCHEMA,
			'seo_plugin'             => Detect::seo_plugin(),
			'features'               => $features,
			'business'               => array(
				'name'        => Business::main()['name'],
				'type'        => $s['type'],
				'has_address' => Business::has_address( Business::main() ),
				'locations'   => count( (array) $s['locations'] ),
			),
			'problems'               => array_map( function ( $p ) { return array( 'severity' => $p[0], 'message' => $p[1] ); }, Schema::problems() ),
			'checks'                 => array(
				'flagged_pages' => count( $pages ),
				'queued'        => Checks::queued(),
				'pages'         => $pages,
			),
			'duplicate_titles'       => $dupes,
			'links'                  => array(
				'pages'      => $links['pages'],
				'indexed'    => $links['indexed'],
				'queued'     => Links::queued(),
				'orphans'    => array_map( function ( $o ) { return array( 'url' => get_permalink( $o['id'] ), 'in_menu' => $o['in_menu'] ); }, $links['orphans'] ),
				'dead_ends'  => array_map( 'get_permalink', $links['dead_ends'] ),
				'broken'     => array_map( function ( $b ) { return array( 'from' => get_permalink( $b['id'] ), 'url' => $b['url'] ); }, $links['broken'] ),
				'redirected' => array_map( function ( $r ) { return array( 'from' => get_permalink( $r['id'] ), 'url' => $r['url'], 'to' => $r['to'] ); }, $links['redirected'] ),
			),
			'llms_txt'               => array( 'on' => $features['llms_txt']['on'], 'url' => $features['llms_txt']['on'] ? Llms::url() : null ),
		);
	}
}
