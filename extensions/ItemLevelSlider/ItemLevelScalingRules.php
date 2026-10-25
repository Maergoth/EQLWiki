<?php

/** Read the tracked, public rules; this class never reads or writes wiki pages. */
final class ItemLevelScalingRules {
	public static function read(): array {
		$source = file_get_contents( __DIR__ . '/item-scaling-rules.json' );
		$rules = json_decode( $source, true, 32, JSON_THROW_ON_ERROR );
		if ( !is_array( $rules ) || ( $rules['schemaVersion'] ?? null ) !== 1 ||
			( $rules['engineVersion'] ?? null ) !== 1 || !is_int( $rules['revision'] ?? null ) ||
			$rules['revision'] < 1 || ( $rules['maxTier'] ?? null ) !== 10 ) {
			throw new UnexpectedValueException( 'Unsupported EQL item scaling rules.' );
		}
		return $rules;
	}
}
