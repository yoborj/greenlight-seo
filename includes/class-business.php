<?php
namespace Greenlight_SEO;

defined( 'ABSPATH' ) || exit;

/**
 * The business and its locations: one place for name, address, phone (NAP) and hours, used by the schema,
 * the [greenlight_nap] shortcode and llms.txt, so the footer, the pages and the schema can't drift apart.
 */
class Business {

	const DAYS = array(
		'Mo' => 'Monday',
		'Tu' => 'Tuesday',
		'We' => 'Wednesday',
		'Th' => 'Thursday',
		'Fr' => 'Friday',
		'Sa' => 'Saturday',
		'Su' => 'Sunday',
	);

	public static function init() {
		add_shortcode( 'greenlight_nap', array( __CLASS__, 'shortcode' ) );
	}

	/** The main business as a place (falls back to the site title for its name). */
	public static function main() {
		$s     = Settings::all();
		$place = array();
		foreach ( Settings::PLACE as $k ) {
			$place[ $k ] = $s[ $k ];
		}
		$place['name']    = '' !== $place['name'] ? $place['name'] : get_bloginfo( 'name' );
		$place['page_id'] = (int) $s['main_page'];
		$place['main']    = true;
		return $place;
	}

	/** Main business first, then the other locations (each inheriting the main name, type and email when empty). */
	public static function places() {
		$main   = self::main();
		$places = array( $main );
		foreach ( (array) Settings::get( 'locations' ) as $i => $loc ) {
			$loc['name']    = '' !== $loc['name'] ? $loc['name'] : $main['name'];
			$loc['email']   = '' !== $loc['email'] ? $loc['email'] : $main['email'];
			$loc['country'] = '' !== $loc['country'] ? $loc['country'] : $main['country'];
			$loc['main']    = false;
			$loc['index']   = $i;
			$places[]       = $loc;
		}
		return $places;
	}

	/** The location that belongs to a page, if any (the main business owns its page, or the homepage). */
	public static function place_for_page( $post_id ) {
		$front = (int) get_option( 'page_on_front' );
		foreach ( self::places() as $p ) {
			$page = $p['page_id'] ?: ( $p['main'] ? $front : 0 );
			if ( $page && (int) $page === (int) $post_id ) {
				return $p;
			}
		}
		return null;
	}

	/** Whether the main business has enough to be a LocalBusiness (Google requires name and address). */
	public static function has_address( array $p ) {
		return '' !== $p['street'] && '' !== $p['city'];
	}

	/**
	 * "Mo-Fr 08:00-17:00" lines → OpeningHoursSpecification items. Returns [ specs, bad lines ].
	 * Accepts day ranges and lists (Mo-Fr, Mo,We,Fr), "closed" lines (skipped) and 24-hour days (00:00-23:59).
	 */
	public static function hours( $text ) {
		$specs = array();
		$bad   = array();
		foreach ( array_filter( array_map( 'trim', explode( "\n", (string) $text ) ), 'strlen' ) as $line ) {
			if ( preg_match( '/^([A-Za-z,\- ]+?)\s+closed$/i', $line ) ) {
				continue;
			}
			if ( ! preg_match( '/^([A-Za-z,\- ]+?)\s+(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})$/', $line, $m ) ) {
				$bad[] = $line;
				continue;
			}
			$days = self::days( $m[1] );
			if ( ! $days ) {
				$bad[] = $line;
				continue;
			}
			$specs[] = array(
				'@type'     => 'OpeningHoursSpecification',
				'dayOfWeek' => array_map( function ( $d ) { return self::DAYS[ $d ]; }, $days ),
				'opens'     => str_pad( $m[2], 5, '0', STR_PAD_LEFT ),
				'closes'    => str_pad( $m[3], 5, '0', STR_PAD_LEFT ),
			);
		}
		return array( $specs, $bad );
	}

	/** "Mo-Fr" / "Mo,We,Fr" / "Sa" → [ 'Mo', 'Tu', … ], or [] if any part isn't a day. */
	private static function days( $spec ) {
		$keys = array_keys( self::DAYS );
		$out  = array();
		foreach ( explode( ',', str_replace( ' ', '', $spec ) ) as $part ) {
			$range = explode( '-', $part );
			$from  = array_search( ucfirst( strtolower( substr( $range[0], 0, 2 ) ) ), $keys, true );
			$to    = isset( $range[1] ) ? array_search( ucfirst( strtolower( substr( $range[1], 0, 2 ) ) ), $keys, true ) : $from;
			if ( false === $from || false === $to || $to < $from ) {
				return array();
			}
			for ( $i = $from; $i <= $to; $i++ ) {
				$out[] = $keys[ $i ];
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** Digits for a tel: link (+1 added to 10-digit US/CA numbers). */
	public static function tel( $phone ) {
		$digits = preg_replace( '/[^\d+]/', '', (string) $phone );
		if ( '' === $digits ) {
			return '';
		}
		if ( '+' !== $digits[0] ) {
			$digits = 10 === strlen( $digits ) ? '+1' . $digits : '+' . $digits;
		}
		return $digits;
	}

	public static function address_line( array $p ) {
		$region = trim( $p['region'] . ' ' . $p['postal'] );
		return implode( ', ', array_filter( array( $p['street'], $p['city'], $region ), 'strlen' ) );
	}

	/**
	 * [greenlight_nap] — the main business. [greenlight_nap location="2"] — the second location (1 = the first
	 * after the main business), or location="Downtown" by name. [greenlight_nap location="all"] — one card per place.
	 * show="name,address,phone,hours,email" picks the parts (default name,address,phone).
	 */
	public static function shortcode( $atts ) {
		$atts   = shortcode_atts( array( 'location' => '', 'show' => 'name,address,phone' ), $atts, 'greenlight_nap' );
		$show   = array_map( 'trim', explode( ',', strtolower( $atts['show'] ) ) );
		$places = self::places();
		if ( 'all' === strtolower( $atts['location'] ) ) {
			$pick = $places;
		} elseif ( '' === $atts['location'] || '0' === $atts['location'] ) {
			$pick = array( $places[0] );
		} elseif ( ctype_digit( (string) $atts['location'] ) ) {
			$pick = isset( $places[ (int) $atts['location'] ] ) ? array( $places[ (int) $atts['location'] ] ) : array();
		} else {
			$pick = array_values( array_filter( $places, function ( $p ) use ( $atts ) {
				return 0 === strcasecmp( $p['name'], $atts['location'] ) || 0 === strcasecmp( $p['city'], $atts['location'] );
			} ) );
		}
		$html = '';
		foreach ( $pick as $p ) {
			$parts = array();
			if ( in_array( 'name', $show, true ) ) {
				$link    = $p['page_id'] && ! $p['main'] ? get_permalink( $p['page_id'] ) : '';
				$parts[] = '<strong class="greenlight-nap-name">' . ( $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $p['name'] ) . '</a>' : esc_html( $p['name'] ) ) . '</strong>';
			}
			if ( in_array( 'address', $show, true ) && self::address_line( $p ) ) {
				$parts[] = '<span class="greenlight-nap-address">' . esc_html( self::address_line( $p ) ) . '</span>';
			}
			if ( in_array( 'phone', $show, true ) && '' !== $p['phone'] ) {
				$parts[] = '<a class="greenlight-nap-phone" href="tel:' . esc_attr( self::tel( $p['phone'] ) ) . '">' . esc_html( $p['phone'] ) . '</a>';
			}
			if ( in_array( 'email', $show, true ) && '' !== $p['email'] ) {
				$parts[] = '<a class="greenlight-nap-email" href="mailto:' . esc_attr( antispambot( $p['email'] ) ) . '">' . esc_html( antispambot( $p['email'] ) ) . '</a>';
			}
			if ( in_array( 'hours', $show, true ) && '' !== trim( $p['hours'] ) ) {
				$parts[] = '<span class="greenlight-nap-hours">' . nl2br( esc_html( trim( $p['hours'] ) ) ) . '</span>';
			}
			$html .= '<address class="greenlight-nap">' . implode( '<br>', $parts ) . '</address>';
		}
		return $html;
	}
}
