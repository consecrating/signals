<?php
/**
 * Lightweight transient-based cache for fast repeat responses.
 *
 * @package FnO_Signal_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FnOSP_Cache {

	const INDEX_OPTION = 'fnosp_cache_index';

	/**
	 * Build a cache key for an instrument + AI flag.
	 *
	 * @param string $instrument Instrument.
	 * @param bool    $use_ai     Whether AI was used.
	 * @return string
	 */
	public static function key( $instrument, $use_ai ) {
		return FNOSP_CACHE_PREFIX . md5( strtoupper( $instrument ) . '|' . ( $use_ai ? '1' : '0' ) );
	}

	/**
	 * Get a cached signal.
	 *
	 * @param string $key Cache key.
	 * @return mixed|false
	 */
	public static function get( $key ) {
		return get_transient( $key );
	}

	/**
	 * Store a signal in cache and track key in an index for flushing.
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Value.
	 * @param int    $ttl   TTL seconds.
	 */
	public static function set( $key, $value, $ttl ) {
		$ttl = max( 1, (int) $ttl );
		set_transient( $key, $value, $ttl );

		$index = get_option( self::INDEX_OPTION, array() );
		if ( ! is_array( $index ) ) {
			$index = array();
		}
		$index[ $key ] = time() + $ttl;
		update_option( self::INDEX_OPTION, $index, false );
	}

	/**
	 * Flush all tracked cached signals.
	 */
	public static function flush_all() {
		$index = get_option( self::INDEX_OPTION, array() );
		if ( is_array( $index ) ) {
			foreach ( array_keys( $index ) as $key ) {
				delete_transient( $key );
			}
		}
		delete_option( self::INDEX_OPTION );
	}
}
