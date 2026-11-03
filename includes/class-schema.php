<?php
namespace Greenlight_SEO;

defined( 'ABSPATH' ) || exit;

/**
 * LocalBusiness and Service schema (JSON-LD), added to the SEO plugin's own graph so a page has one schema
 * graph, not two:
 * - Yoast SEO / Rank Math: when the graph has the plugin's Organization (Yoast prints it once the site is set to
 *   an organization with a name and logo), the main business is merged into it (as Yoast Local SEO does), and
 *   location and service nodes join the graph pointing at it. Without one, the main business joins the graph as
 *   a LocalBusiness of its own.
 * - Neither: one <script type="application/ld+json"> graph of Greenlight SEO's own.
 */
class Schema {

	const SERVICE_META = '_greenlight_seo_service';

	public static function init() {
		add_action( 'wp', array( __CLASS__, 'hook' ) );
	}

	public static function hook() {
		if ( is_admin() || ( ! Detect::on( 'local_schema' ) && ! Detect::on( 'service_schema' ) ) ) {
			return;
		}
		if ( Detect::yoast() ) {
			add_filter( 'wpseo_schema_graph', array( __CLASS__, 'yoast_graph' ), 20 );
		} elseif ( Detect::rank_math() ) {
			add_filter( 'rank_math/json_ld', array( __CLASS__, 'rank_math' ), 99 );
		} else {
			add_action( 'wp_head', array( __CLASS__, 'print_graph' ), 20 );
		}
	}

	public static function id( array $p ) {
		if ( $p['main'] ) {
			return home_url( '/#/schema/localbusiness' );
		}
		$base = $p['page_id'] ? get_permalink( $p['page_id'] ) : home_url( '/' );
		return $base . '#/schema/location-' . ( (int) $p['index'] + 1 );
	}

	/** A place → LocalBusiness properties (no @type/@id). Empty values are left out. */
	public static function place_props( array $p ) {
		$s     = Settings::all();
		$props = array(
			'name'      => $p['name'],
			'url'       => $p['page_id'] ? get_permalink( $p['page_id'] ) : home_url( '/' ),
			'telephone' => $p['phone'],
			'email'     => $p['email'],
		);
		if ( Business::has_address( $p ) ) {
			$props['address'] = array_filter( array(
				'@type'           => 'PostalAddress',
				'streetAddress'   => $p['street'],
				'addressLocality' => $p['city'],
				'addressRegion'   => $p['region'],
				'postalCode'      => $p['postal'],
				'addressCountry'  => $p['country'],
			), 'strlen' );
		}
		if ( '' !== $p['lat'] && '' !== $p['lng'] ) {
			$props['geo'] = array( '@type' => 'GeoCoordinates', 'latitude' => (float) $p['lat'], 'longitude' => (float) $p['lng'] );
		}
		list( $hours ) = Business::hours( '' !== trim( $p['hours'] ) ? $p['hours'] : ( $p['main'] ? '' : $s['hours'] ) );
		if ( $hours ) {
			$props['openingHoursSpecification'] = $hours;
		}
		if ( '' !== $s['price_range'] ) {
			$props['priceRange'] = $s['price_range'];
		}
		if ( $p['main'] ) {
			if ( '' !== $s['description'] ) {
				$props['description'] = $s['description'];
			}
			$areas = Settings::lines( 'area_served' );
			if ( $areas ) {
				$props['areaServed'] = self::areas( $areas );
			}
			$same = Settings::lines( 'same_as' );
			if ( $same ) {
				$props['sameAs'] = $same;
			}
			$icon = get_site_icon_url( 512 );
			if ( $icon ) {
				$props['image'] = $icon;
			}
		}
		return self::text( array_filter( $props, function ( $v ) { return '' !== $v && null !== $v && array() !== $v; } ) );
	}

	/**
	 * Plain text for JSON-LD: no tags, and entities decoded (WordPress stores "<" and "&" from text fields as
	 * "&lt;" and "&amp;"). Safe to print: the graph is encoded with JSON_HEX_TAG and JSON_HEX_AMP.
	 */
	private static function text( $v ) {
		if ( is_array( $v ) ) {
			return array_map( array( __CLASS__, 'text' ), $v );
		}
		return is_string( $v ) ? html_entity_decode( wp_strip_all_tags( $v ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : $v;
	}

	private static function areas( array $names ) {
		return array_map( function ( $n ) { return array( '@type' => 'City', 'name' => $n ); }, $names );
	}

	public static function local_business( array $p, $org = null ) {
		$node = array(
			'@type' => Settings::get( 'type' ),
			'@id'   => self::id( $p ),
		) + self::place_props( $p );
		if ( ! $p['main'] ) {
			// Name and URL too, so the reference stands on its own on pages that don't carry the main node.
			$node['parentOrganization'] = array( '@id' => $org ?: self::id( Business::main() ), 'name' => Business::main()['name'], 'url' => home_url( '/' ) );
		}
		return $node;
	}

	/** The page's Service settings, or null when the page isn't marked as a service page. */
	public static function service_meta( $post_id ) {
		$m = get_post_meta( $post_id, self::SERVICE_META, true );
		return is_array( $m ) && ! empty( $m['on'] ) ? $m : null;
	}

	public static function service( $post_id, $provider ) {
		$m    = self::service_meta( $post_id );
		$name = '' !== trim( $m['name'] ?? '' ) ? trim( $m['name'] ) : wp_strip_all_tags( get_the_title( $post_id ) );
		$desc = trim( $m['description'] ?? '' );
		if ( '' === $desc && Detect::yoast() ) {
			$desc = (string) get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
		}
		if ( '' === $desc ) {
			$desc = (string) get_the_excerpt( $post_id );
		}
		$areas = array_values( array_filter( array_map( 'trim', explode( "\n", (string) ( $m['area'] ?? '' ) ) ), 'strlen' ) );
		$areas = $areas ?: Settings::lines( 'area_served' );
		$node  = array(
			'@type'       => 'Service',
			'@id'         => get_permalink( $post_id ) . '#/schema/service',
			'name'        => $name,
			'serviceType' => $name,
			'url'         => get_permalink( $post_id ),
			'description' => wp_strip_all_tags( $desc ),
			'provider'    => $provider,
		);
		if ( $areas ) {
			$node['areaServed'] = self::areas( $areas );
		}
		return self::text( array_filter( $node, function ( $v ) { return '' !== $v && null !== $v && array() !== $v; } ) );
	}

	/**
	 * The nodes this request needs. $org: the @id of the SEO plugin's Organization the main business was merged
	 * into (then it isn't added again, and services and locations point at it), or null.
	 */
	public static function nodes( $org = null ) {
		$nodes       = array();
		$merged_main = null !== $org;
		$main        = Business::main();
		$post_id     = is_singular() ? get_queried_object_id() : 0;
		$here        = $post_id ? Business::place_for_page( $post_id ) : ( is_front_page() ? Business::main() : null );
		$local       = Detect::on( 'local_schema' ) && Business::has_address( $main );

		if ( $local && $here && ! ( $here['main'] && $merged_main ) ) {
			$nodes[] = self::local_business( $here, $org );
		}
		if ( Detect::on( 'service_schema' ) && $post_id && self::service_meta( $post_id ) ) {
			if ( $merged_main ) {
				$provider = array( '@id' => $org );
			} elseif ( $local && $here && $here['main'] ) {
				$provider = array( '@id' => self::id( $main ) );
			} elseif ( $local ) {
				// The LocalBusiness node isn't on this page: describe the provider inline.
				$provider = array( '@type' => Settings::get( 'type' ), '@id' => self::id( $main ) ) + array_intersect_key( self::place_props( $main ), array_flip( array( 'name', 'url', 'telephone', 'address' ) ) );
			} else {
				$provider = array( '@type' => 'Organization', 'name' => $main['name'], 'url' => home_url( '/' ) );
			}
			$nodes[] = self::service( $post_id, $provider );
		}
		return $nodes;
	}

	/** The main business merged into the SEO plugin's Organization node (one entity, as Yoast Local SEO does it). */
	public static function merge_main( array $data ) {
		$main          = Business::main();
		$types         = array_values( array_unique( array_merge( (array) ( $data['@type'] ?? 'Organization' ), array( Settings::get( 'type' ) ) ) ) );
		$props         = self::place_props( $main );
		$data['@type'] = $types;
		foreach ( $props as $k => $v ) {
			if ( 'sameAs' === $k ) {
				$data['sameAs'] = array_values( array_unique( array_merge( (array) ( $data['sameAs'] ?? array() ), $v ) ) );
			} elseif ( 'image' === $k || ( 'name' === $k && ! empty( $data['name'] ) ) || ( 'url' === $k && ! empty( $data['url'] ) ) ) {
				continue; // Yoast's own logo, name and URL stay.
			} else {
				$data[ $k ] = $v;
			}
		}
		return $data;
	}

	/** Whether a node is an Organization (Yoast's and Rank Math's are typed "Organization" or [ "Organization", … ]). */
	private static function is_org( $node ) {
		return is_array( $node ) && ! empty( $node['@id'] ) && in_array( 'Organization', (array) ( $node['@type'] ?? array() ), true );
	}

	private static function can_merge() {
		return Detect::on( 'local_schema' ) && Business::has_address( Business::main() );
	}

	public static function yoast_graph( $graph ) {
		$org = null;
		if ( self::can_merge() ) {
			foreach ( $graph as $i => $node ) {
				if ( self::is_org( $node ) && '#organization' === substr( $node['@id'], -13 ) ) {
					$graph[ $i ] = self::merge_main( $node );
					$org         = $node['@id'];
					break;
				}
			}
		}
		foreach ( self::nodes( $org ) as $n ) {
			$graph[] = $n;
		}
		return $graph;
	}

	public static function rank_math( $data ) {
		$org = null;
		if ( self::can_merge() ) {
			foreach ( $data as $key => $node ) {
				if ( self::is_org( $node ) ) {
					$data[ $key ] = self::merge_main( $node );
					$org          = $node['@id'];
					break;
				}
			}
		}
		foreach ( self::nodes( $org ) as $i => $n ) {
			$data[ 'greenlight_seo_' . $i ] = $n;
		}
		return $data;
	}

	public static function print_graph() {
		$nodes = self::nodes();
		if ( ! $nodes ) {
			return;
		}
		echo "\n<script type=\"application/ld+json\" class=\"greenlight-seo-schema\">" . wp_json_encode( array( '@context' => 'https://schema.org', '@graph' => $nodes ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** Problems with the business details, for the settings page and `status`: [ [ severity, text ] ]. */
	public static function problems() {
		$out = array();
		foreach ( Business::places() as $p ) {
			$who = $p['main'] ? 'The business' : 'Location “' . $p['name'] . '”';
			if ( ! Business::has_address( $p ) ) {
				$out[] = array( $p['main'] ? 'high' : 'medium', $who . ' has no street address and city: Google needs both for LocalBusiness.' );
			}
			if ( '' === $p['phone'] ) {
				$out[] = array( 'medium', $who . ' has no phone number.' );
			}
			foreach ( array( 'lat', 'lng' ) as $k ) {
				if ( '' !== $p[ $k ] && strlen( (string) substr( strrchr( $p[ $k ], '.' ), 1 ) ) < 5 ) {
					$out[] = array( 'low', $who . ': use at least 5 decimals for latitude and longitude (about 1 m).' );
					break;
				}
			}
			list( , $bad ) = Business::hours( $p['hours'] );
			foreach ( $bad as $line ) {
				$out[] = array( 'medium', $who . ': opening hours line not understood: “' . $line . '” (use e.g. “Mo-Fr 08:00-17:00”).' );
			}
			if ( ! $p['main'] && ! $p['page_id'] ) {
				$out[] = array( 'medium', $who . ' has no page: pick the location page its schema belongs on.' );
			}
			foreach ( array( 'name', 'street', 'city', 'phone' ) as $k ) {
				if ( preg_match( '/\[|\]|lorem|example|xxx|000-0000|555-01/i', (string) $p[ $k ] ) ) {
					$out[] = array( 'high', $who . ': ' . $k . ' looks like a placeholder: “' . $p[ $k ] . '”.' );
				}
			}
		}
		return $out;
	}
}
