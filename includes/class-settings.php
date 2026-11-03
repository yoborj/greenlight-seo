<?php
namespace Greenlight_SEO;

defined( 'ABSPATH' ) || exit;

/**
 * Settings with safe defaults. Stored in one option, `greenlight_seo_settings`.
 * Switch key names are part of `wp greenlight-seo status --format=json` (and `wp greenlight-seo set`).
 */
class Settings {

	const OPTION = 'greenlight_seo_settings';

	/** Switches (1/0). */
	const SWITCHES = array( 'local_schema', 'service_schema', 'checks_on_save', 'check_title_suffix', 'link_suggestions', 'llms_txt' );

	/**
	 * schema.org LocalBusiness types a local-business site picks from. Attorney is deprecated by schema.org
	 * (use LegalService); there is no RealEstateBrokerage or Chiropractor type.
	 */
	const TYPES = array(
		'LocalBusiness'               => 'Local business (generic)',
		'ProfessionalService'         => 'Professional service',
		'HomeAndConstructionBusiness' => 'Home services (generic)',
		'Plumber'                     => 'Plumber',
		'Electrician'                 => 'Electrician',
		'HVACBusiness'                => 'HVAC',
		'RoofingContractor'           => 'Roofing contractor',
		'GeneralContractor'           => 'General contractor',
		'HousePainter'                => 'House painter',
		'Locksmith'                   => 'Locksmith',
		'MovingCompany'               => 'Moving company',
		'MedicalBusiness'             => 'Medical (generic)',
		'MedicalClinic'               => 'Medical clinic',
		'Physiotherapy'               => 'Physical therapy / chiropractic',
		'Dentist'                     => 'Dentist',
		'Physician'                   => 'Physician',
		'Optician'                    => 'Optician',
		'Pharmacy'                    => 'Pharmacy',
		'VeterinaryCare'              => 'Veterinary care',
		'LegalService'                => 'Law firm / legal service',
		'RealEstateAgent'             => 'Real estate agent or brokerage',
		'InsuranceAgency'             => 'Insurance agency',
		'AccountingService'           => 'Accounting',
		'FinancialService'            => 'Financial service',
		'AutoRepair'                  => 'Auto repair',
		'AutoDealer'                  => 'Auto dealer',
		'Restaurant'                  => 'Restaurant',
		'CafeOrCoffeeShop'            => 'Café / coffee shop',
		'BeautySalon'                 => 'Beauty salon',
		'DaySpa'                      => 'Day spa',
		'HealthClub'                  => 'Gym / health club',
		'ChildCare'                   => 'Child care',
		'DryCleaningOrLaundry'        => 'Dry cleaning / laundry',
		'Store'                       => 'Store',
	);

	/** Address and contact fields, shared by the main business and each location. */
	const PLACE = array( 'name', 'phone', 'email', 'street', 'city', 'region', 'postal', 'country', 'lat', 'lng', 'hours' );

	public static function defaults() {
		return array(
			// The business (the main location). Empty fields are left out of the schema.
			'name'               => '',
			'type'               => 'LocalBusiness',
			'description'        => '',
			'phone'              => '',
			'email'              => '',
			'street'             => '',
			'city'               => '',
			'region'             => '',
			'postal'             => '',
			'country'            => 'US',
			'lat'                => '',
			'lng'                => '',
			'hours'              => '', // One line per rule: "Mo-Fr 08:00-17:00", "Sa 09:00-12:00".
			'price_range'        => '',
			'area_served'        => '', // One city per line (service-area businesses).
			'same_as'            => '', // Profile URLs, one per line: Google Business Profile, Facebook, Yelp…
			'main_page'          => 0,  // The page the main LocalBusiness belongs to (0 = the homepage).
			'locations'          => array(), // Other locations: each has the PLACE fields plus page_id.

			// Schema.
			'local_schema'       => 1,
			'service_schema'     => 1,

			// Checks.
			'checks_on_save'     => 1,
			'check_title_suffix' => 0, // House style: every SEO title ends with "| Site name".

			// Internal links.
			'link_suggestions'   => 1, // Off by itself on Yoast SEO Premium, which has its own.

			// llms.txt. Off until switched on, and never while Yoast's or Rank Math's is on.
			'llms_txt'           => 0,
			'llms_intro'         => '',
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/** Lines of a textarea setting, trimmed, empty lines dropped. */
	public static function lines( $key ) {
		return array_values( array_filter( array_map( 'trim', explode( "\n", (string) self::get( $key ) ) ), 'strlen' ) );
	}

	public static function update( array $changes ) {
		update_option( self::OPTION, self::sanitize( array_merge( self::all(), $changes ) ) );
	}

	/** One place (the main business or a location). */
	private static function place( array $in ) {
		$out = array();
		foreach ( self::PLACE as $k ) {
			$v = $in[ $k ] ?? '';
			if ( 'hours' === $k ) {
				$out[ $k ] = sanitize_textarea_field( wp_unslash( $v ) );
			} elseif ( 'email' === $k ) {
				$out[ $k ] = sanitize_email( wp_unslash( $v ) );
			} elseif ( in_array( $k, array( 'lat', 'lng' ), true ) ) {
				$v         = trim( (string) wp_unslash( $v ) );
				$out[ $k ] = is_numeric( $v ) ? $v : '';
			} elseif ( 'country' === $k ) {
				$out[ $k ] = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $v ), 0, 2 ) );
			} else {
				$out[ $k ] = sanitize_text_field( wp_unslash( $v ) );
			}
		}
		return $out;
	}

	public static function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$out      = array();
		foreach ( $defaults as $key => $default ) {
			if ( in_array( $key, self::SWITCHES, true ) ) {
				$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
			} elseif ( 'type' === $key ) {
				$type        = 'Attorney' === ( $input[ $key ] ?? '' ) ? 'LegalService' : ( $input[ $key ] ?? '' );
				$out[ $key ] = isset( self::TYPES[ $type ] ) ? $type : 'LocalBusiness';
			} elseif ( 'main_page' === $key ) {
				$out[ $key ] = absint( $input[ $key ] ?? 0 );
			} elseif ( 'locations' === $key ) {
				$out[ $key ] = array();
				foreach ( (array) ( $input[ $key ] ?? array() ) as $loc ) {
					if ( ! is_array( $loc ) || ! empty( $loc['_remove'] ) ) {
						continue;
					}
					$place = self::place( $loc ) + array( 'page_id' => absint( $loc['page_id'] ?? 0 ) );
					if ( '' !== $place['name'] || '' !== $place['street'] || $place['page_id'] ) {
						$out[ $key ][] = $place;
					}
				}
			} elseif ( in_array( $key, array( 'hours', 'area_served', 'description', 'llms_intro' ), true ) ) {
				$out[ $key ] = sanitize_textarea_field( wp_unslash( $input[ $key ] ?? $default ) );
			} elseif ( 'same_as' === $key ) {
				$urls        = array_filter( array_map( 'esc_url_raw', array_map( 'trim', explode( "\n", (string) wp_unslash( $input[ $key ] ?? '' ) ) ) ) );
				$out[ $key ] = implode( "\n", $urls );
			} elseif ( in_array( $key, self::PLACE, true ) ) {
				$out[ $key ] = self::place( array( $key => $input[ $key ] ?? $default ) )[ $key ];
			} else {
				$out[ $key ] = sanitize_text_field( wp_unslash( $input[ $key ] ?? $default ) );
			}
		}
		if ( '' === $out['country'] ) {
			$out['country'] = 'US';
		}
		return $out;
	}
}
