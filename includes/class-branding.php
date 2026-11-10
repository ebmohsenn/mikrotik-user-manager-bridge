<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MUMB_Branding {

	private const OPTION_KEY = 'mumb_branding';

	private static ?self $cache = null;
	/** @var array<int, string>|null */
	private static ?array $tokens   = null;
	private static bool $in_replace = false;

	private string $name;
	private string $logo_url;

	private function __construct( string $name, string $logo_url ) {
		$this->name     = $name;
		$this->logo_url = $logo_url;
	}

	/**
	 * @return array{name:string, logo_url:string}
	 */
	public static function defaults(): array {
		/** @var string $site_name */
		$site_name    = get_bloginfo( 'name', 'display' );
		$default_name = '' !== $site_name ? $site_name : 'MikroTik Bridge';
		/**
		 * Filter the default branding values.
		 *
		 * @param array{name:string, logo_url:string} $defaults
		 */
		/** @var array<string, mixed> $filtered */
		$filtered = apply_filters(
			'mumb_branding_defaults',
			array(
				'name'     => $default_name,
				'logo_url' => '',
			)
		);
		return array(
			'name'     => isset( $filtered['name'] ) && is_string( $filtered['name'] ) && $filtered['name'] !== '' ? $filtered['name'] : $default_name,
			'logo_url' => isset( $filtered['logo_url'] ) && is_string( $filtered['logo_url'] ) ? $filtered['logo_url'] : '',
		);
	}

	public static function load(): self {
		if ( self::$cache instanceof self ) {
			return self::$cache;
		}
		$defaults = self::defaults();
		/** @var array<string, mixed>|false $raw */
		$raw         = get_option( self::OPTION_KEY, array() );
		$opts        = is_array( $raw ) ? $raw : array();
		$name        = isset( $opts['name'] ) && is_string( $opts['name'] ) && $opts['name'] !== '' ? $opts['name'] : $defaults['name'];
		$logo        = isset( $opts['logo_url'] ) && is_string( $opts['logo_url'] ) ? $opts['logo_url'] : $defaults['logo_url'];
		self::$cache = new self( $name, $logo );
		return self::$cache;
	}

	public static function name(): string {
		return self::load()->name;
	}

	public static function logo_url(): string {
		return self::load()->logo_url;
	}

	/**
	 * @return array{name:string, logo_url:string}
	 */
	public static function branding(): array {
		return self::to_array();
	}

	/**
	 * @return array<int, string>
	 */
	private static function replacement_tokens(): array {
		if ( null !== self::$tokens ) {
			return self::$tokens;
		}
		/**
		 * Filter the literal tokens that are replaced with the configured brand name in this
		 * plugin's translated strings (e.g. a placeholder product name used in a custom .po file).
		 *
		 * @param array<int, string> $tokens Defaults to array( '{brand}' ).
		 */
		$tokens       = apply_filters( 'mumb_branding_tokens', array( '{brand}' ) );
		self::$tokens = array();
		if ( is_array( $tokens ) ) {
			foreach ( $tokens as $token ) {
				if ( is_string( $token ) && '' !== $token ) {
					self::$tokens[] = $token;
				}
			}
		}
		return self::$tokens;
	}

	private static function replace_tokens( string $value ): string {
		if ( self::$in_replace || '' === $value ) {
			return $value;
		}
		self::$in_replace = true;
		try {
			$brand = self::name();
			if ( '' === $brand ) {
				return $value;
			}
			$tokens = self::replacement_tokens();
			foreach ( $tokens as $token ) {
				if ( str_contains( $value, $token ) ) {
					$replacements = array_fill( 0, count( $tokens ), $brand );
					return str_replace( $tokens, $replacements, $value );
				}
			}
			return $value;
		} finally {
			self::$in_replace = false;
		}
	}

	public static function filter_gettext( string $translation, string $text, string $domain ): string {
		if ( 'mikrotik-user-manager-bridge' !== $domain ) {
			return $translation;
		}
		return self::replace_tokens( $translation );
	}

	public static function filter_gettext_with_context( string $translation, string $text, string $context, string $domain ): string {
		if ( 'mikrotik-user-manager-bridge' !== $domain ) {
			return $translation;
		}
		return self::replace_tokens( $translation );
	}

	public static function filter_ngettext( string $translation, string $single, string $plural, int $number, string $domain ): string {
		if ( 'mikrotik-user-manager-bridge' !== $domain ) {
			return $translation;
		}
		return self::replace_tokens( $translation );
	}

	/**
	 * @return array{name:string, logo_url:string}
	 */
	public static function to_array(): array {
		$inst = self::load();
		return array(
			'name'     => $inst->name,
			'logo_url' => $inst->logo_url,
		);
	}

	/**
	 * @param string|null $name
	 * @param string|null $logo_url
	 */
	public static function update( ?string $name, ?string $logo_url ): self {
		$current      = self::load();
		$updated_name = $current->name;
		if ( null !== $name ) {
			$clean        = sanitize_text_field( $name );
			$updated_name = '' !== $clean ? $clean : self::defaults()['name'];
		}
		$updated_logo = $current->logo_url;
		if ( null !== $logo_url ) {
			$clean_logo   = esc_url_raw( $logo_url );
			$updated_logo = '' !== $clean_logo ? $clean_logo : '';
		}
		$instance = new self( $updated_name, $updated_logo );
		update_option( self::OPTION_KEY, $instance->to_array(), false );
		self::$cache  = $instance;
		self::$tokens = null;
		return $instance;
	}
}

function mumb_brand_name(): string {
	return MUMB_Branding::name();
}

function mumb_brand_logo_url(): string {
	return MUMB_Branding::logo_url();
}

/**
 * @return array{name:string, logo_url:string}
 */
function mumb_branding(): array {
	return MUMB_Branding::to_array();
}

add_filter( 'gettext', array( 'MUMB_Branding', 'filter_gettext' ), 40, 3 );
add_filter( 'gettext_with_context', array( 'MUMB_Branding', 'filter_gettext_with_context' ), 40, 4 );
add_filter( 'ngettext', array( 'MUMB_Branding', 'filter_ngettext' ), 40, 5 );
