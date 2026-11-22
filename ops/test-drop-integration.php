<?php
/** Synthetic rendered-guide contract checks. No wiki bootstrap, requests or data writes. */
declare( strict_types = 1 );
namespace MediaWiki\SpecialPage { class SpecialPage {} }
namespace MediaWiki\Html {
	class Html {
		public static function element( $tag, $attrs = [], $text = '' ): string { return self::rawElement( $tag, $attrs, htmlspecialchars( (string)$text, ENT_QUOTES ) ); }
		public static function rawElement( $tag, $attrs, $html ): string {
			$attributes = '';foreach ( $attrs as $key => $value ) { $attributes .= ' ' . $key . '="' . htmlspecialchars( (string)$value, ENT_QUOTES ) . '"'; }
			return '<' . $tag . $attributes . '>' . $html . '</' . $tag . '>';
		}
	}
}
namespace MediaWiki\Title {
	class Title {
		public function __construct( private string $text ) {}
		public static function newFromText( $text ): self { return new self( $text ); }
		public function getLocalURL(): string { return '/wiki/' . str_replace( ' ', '_', $this->text ); }
	}
}
namespace {
	const PROTO_CANONICAL = 2;
	function wfScript( $script ): string { if ( $script !== 'api' ) { throw new RuntimeException( 'Unexpected script.' ); }return '/w/api.php'; }
	function wfExpandUrl( $path, $protocol ): string { if ( $protocol !== PROTO_CANONICAL ) { throw new RuntimeException( 'Canonical origin required.' ); }return 'https://fixture.example' . $path; }
	require __DIR__ . '/../extensions/EQLClientData/includes/ObservationPayload.php';
	require __DIR__ . '/../extensions/EQLClientData/includes/SpecialDropDatabase.php';
	$checks = 0;
	function check( bool $ok, string $label ): void { global $checks;if ( !$ok ) { throw new RuntimeException( $label ); }$checks++; }
	$reflection = new ReflectionClass( \EQLClientData\SpecialDropDatabase::class );
	$html = $reflection->getMethod( 'integrationGuide' )->invoke( $reflection->newInstanceWithoutConstructor() );
	$dom = new DOMDocument();check( $dom->loadHTML( '<meta charset="utf-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING ), 'Guide is parseable HTML.' );
	$xpath = new DOMXPath( $dom );$details = $dom->getElementsByTagName( 'details' );
	check( $details->length === 1 && !$details->item( 0 )->hasAttribute( 'open' ), 'The integration guide starts collapsed without script.' );
	check( $dom->getElementsByTagName( 'summary' )->item( 0 )->textContent === 'Share observations from another tool', 'The disclosure has a clear native control.' );
	check( $xpath->query( '//form|//input|//button|//script' )->length === 0, 'Guide never creates an upload flow or sends an example.' );
	$examples = $dom->getElementsByTagName( 'pre' );check( $examples->length === 1, 'One copyable JSON example is present.' );
	$example = $examples->item( 0 )->textContent;
	$batch = \EQLClientData\ObservationPayload::batch( $example, strtotime( '2026-11-22T00:00:00Z' ) );
	check( $batch['schemaVersion'] === 1 && count( $batch['events'] ) === 1 && $batch['rejected'] === [], 'The displayed JSON passes the real schema1 validator.' );
	check( $batch['events'][0]['sourceKind'] === 'explicit-corpse' && $batch['events'][0]['quantity'] === 1, 'Example is an eligible per-event corpse observation.' );
	$raw = json_decode( $example, true, 16, JSON_THROW_ON_ERROR );
	check( array_intersect( [ 'actor', 'account', 'characterName', 'path' ], array_merge( array_keys( $raw ), array_keys( $raw['events'][0] ) ) ) === [], 'Example carries no submitted identity, private path or unconsented name.' );
	$text = $dom->textContent;
	foreach ( [ 'Synthetic example', 'POST https://fixture.example/w/api.php', 'action=eozobservations', 'operation=status', 'enabled:true', 'available:true',
		'operation=submit', 'assert=user', 'assertuser=', 'format=json', 'formatversion=2', 'CSRF', 'confirmed email', 'scoped bot password',
		'stable for retries', 'accepted or duplicate', 'aggregate counts', 'claimed drop chances', number_format( \EQLClientData\ObservationPayload::MAX_BYTES ),
		number_format( \EQLClientData\ObservationPayload::MAX_EVENT_BYTES ) ] as $required ) {
		check( str_contains( $text, $required ), 'Guide retains required contract: ' . $required );
	}
	$links = [];foreach ( $dom->getElementsByTagName( 'a' ) as $link ) { $links[] = $link->getAttribute( 'href' ); }
	check( in_array( 'https://fixture.example/w/api.php?action=help&modules=eozobservations', $links, true ), 'Upload help uses the current wiki origin and script path.' );
	check( in_array( '/wiki/API_Documentation#Private_kill/loot_observations:_schema_1', $links, true ), 'Full public documentation opens at the observation contract.' );
	check( in_array( 'https://github.com/Maergoth/EQLWiki/blob/main/docs/CLIENT_API.md#observation-schema-1-and-backlog-delivery', $links, true ) &&
		in_array( 'https://github.com/Maergoth/EQLWiki/blob/main/extensions/EQLClientData/sql/mysql/observations.sql', $links, true ), 'The event and storage references resolve to tracked canonical paths.' );
	echo "Drop integration guide: $checks synthetic checks passed; no requests or writes.\n";
}
