<?php
/** Synthetic configuration and hook checks; no private settings, database or network. */
declare( strict_types = 1 );

namespace MediaWiki\Hook {
	interface BeforePageDisplayHook {}
	interface ParserAfterParseHook {}
	interface SkinBuildSidebarHook {}
	interface SkinTemplateNavigation__UniversalHook {}
}
namespace MediaWiki\Revision {
	class RevisionRecord {
		public const DELETED_TEXT = 1;
		public const FOR_PUBLIC = 1;
	}
}
namespace MediaWiki {
	class MediaWikiServices {
		public static object $fixture;
		public static function getInstance(): object { return self::$fixture; }
	}
}
namespace {
	define( 'NS_MAIN', 0 );
	define( 'NS_HELP', 12 );
	define( 'NS_CATEGORY', 14 );
	require __DIR__ . '/../skins/EQLImmersive/includes/Analytics.php';
	require __DIR__ . '/../skins/EQLImmersive/includes/Hooks.php';

	function analyticsFixture( array $options = [] ): object {
		return new class( $options ) {
			public array $jsConfig = [];
			public array $modules = [];
			public function __construct( private array $options ) {}
			public function addJsConfigVars( string $key, mixed $value ): void { $this->jsConfig[$key] = $value; }
			public function addModules( array $modules ): void { $this->modules = array_merge( $this->modules, $modules ); }
			public function getConfig(): object {
				$values = array_replace( [
					'EQLAnalyticsMeasurementId' => 'G-1234567890',
					'EQLAnalyticsConsentPublisher' => 'pub-1234567890123456',
					'Server' => 'https://eqlwiki.com', 'CanonicalServer' => 'https://eqlwiki.com',
					'DefaultRobotPolicy' => 'index,follow',
					'EQLAdSenseClient' => '', 'EQLAdSenseFormat' => ''
				], $this->options['config'] ?? [] );
				return new class( $values, $this->options['unregistered'] ?? [] ) {
					public function __construct( private array $values, private array $unregistered ) {}
					public function has( string $key ): bool { return !in_array( $key, $this->unregistered, true ); }
					public function get( string $key ): mixed {
						if ( !$this->has( $key ) ) { throw new RuntimeException( 'Unregistered setting read: ' . $key ); }
						return $this->values[$key];
					}
				};
			}
			public function getTitle(): ?object {
				if ( $this->options['missingTitle'] ?? false ) { return null; }
				return new class( $this->options ) {
					public function __construct( private array $options ) {}
					public function getNamespace(): int { return $this->options['namespace'] ?? NS_MAIN; }
					public function exists(): bool { return $this->options['exists'] ?? true; }
					public function isRedirect(): bool { return $this->options['redirect'] ?? false; }
					public function getPrefixedText(): string {
						return match ( $this->getNamespace() ) {
							NS_HELP => 'Help:Synthetic article', NS_CATEGORY => 'Category:Synthetic article',
							default => 'Synthetic article'
						};
					}
					public function getCanonicalURL(): string {
						return $this->options['canonical'] ?? 'https://eqlwiki.com/' . str_replace( ' ', '_', $this->getPrefixedText() );
					}
				};
			}
			public function getRequest(): object {
				return new class( $this->options['query'] ?? [] ) {
					public function __construct( private array $query ) {}
					public function getVal( string $key ): mixed { return $this->query[$key] ?? null; }
				};
			}
			public function getContext(): object {
				return new class( $this->options['action'] ?? 'view' ) {
					public function __construct( private string $action ) {}
				public function getActionName(): string { return $this->action; }
				};
			}
			public function isArticle(): bool { return $this->options['article'] ?? true; }
			public function isRevisionCurrent(): bool { return $this->options['current'] ?? true; }
			public function getRevisionId(): ?int {
				return array_key_exists( 'revisionId', $this->options ) ? $this->options['revisionId'] : 123;
			}
			public function getRobotPolicy(): string { return $this->options['policy'] ?? 'index,follow'; }
		};
	}

	$checks = 0;
	function analyticsCheck( bool $condition, string $message ): void {
		global $checks;
		$checks++;
		if ( !$condition ) { throw new RuntimeException( $message ); }
	}
	function expectedAnalytics( int $namespace = NS_MAIN ): array {
		$title = match ( $namespace ) {
			NS_HELP => 'Help:Synthetic article', NS_CATEGORY => 'Category:Synthetic article',
			default => 'Synthetic article'
		};
		return [ 'measurementId' => 'G-1234567890', 'origin' => 'https://eqlwiki.com',
			'consentPublisher' => 'pub-1234567890123456',
			'pageLocation' => 'https://eqlwiki.com/' . str_replace( ' ', '_', $title ),
			'pageTitle' => $title, 'pageNamespace' => $namespace ];
	}

	$preference = [ 'origin' => 'https://eqlwiki.com' ];
	foreach ( [ NS_MAIN, NS_HELP, NS_CATEGORY ] as $namespace ) {
		$out = analyticsFixture( [ 'namespace' => $namespace ] );
		analyticsCheck( EQLImmersive\Analytics::independentConfigurationForOutput( $out, true ) === expectedAnalytics( $namespace ),
			'Public content must work without advertising settings: ' . $namespace );
		analyticsCheck( EQLImmersive\Analytics::independentConfigurationForOutput( $out, false ) === null, 'Nonpublic read must be excluded' );
		analyticsCheck( EQLImmersive\Analytics::preferencesForOutput( $out ) === $preference, 'First-party preferences must expose only origin' );
	}
	$excluded = [
		[ 'missingTitle' => true ], [ 'exists' => false ], [ 'namespace' => NS_CATEGORY, 'exists' => false ],
		[ 'redirect' => true ], [ 'article' => false ], [ 'current' => false ],
		[ 'action' => 'edit' ], [ 'action' => 'history' ], [ 'action' => 'raw' ], [ 'action' => 'submit' ],
		[ 'query' => [ 'oldid' => '0' ] ], [ 'query' => [ 'diff' => '' ] ], [ 'query' => [ 'veaction' => 'edit' ] ],
		[ 'policy' => 'NOINDEX,nofollow' ], [ 'config' => [ 'DefaultRobotPolicy' => 'noindex,follow' ] ],
		[ 'config' => [ 'DefaultRobotPolicy' => [ 'index' => 'noindex', 'follow' => 'follow' ] ] ]
	];
	foreach ( [ -2, -1, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 13, 15 ] as $namespace ) { $excluded[] = [ 'namespace' => $namespace ]; }
	foreach ( $excluded as $options ) {
		$out = analyticsFixture( $options );
		analyticsCheck( EQLImmersive\Analytics::independentConfigurationForOutput( $out, true ) === null,
			'Excluded context must not receive Google configuration: ' . json_encode( $options ) );
		analyticsCheck( EQLImmersive\Analytics::preferencesForOutput( $out ) === $preference,
			'Excluded pages must retain first-party preference access' );
	}
	$invalidSettings = [
		[ 'EQLAnalyticsMeasurementId' => '' ], [ 'EQLAnalyticsMeasurementId' => null ],
		[ 'EQLAnalyticsMeasurementId' => true ], [ 'EQLAnalyticsMeasurementId' => "G-1234567890\n" ],
		[ 'EQLAnalyticsMeasurementId' => 'G-invalid' ], [ 'EQLAnalyticsMeasurementId' => 'UA-1234567890' ],
		[ 'EQLAnalyticsConsentPublisher' => '' ], [ 'EQLAnalyticsConsentPublisher' => null ],
		[ 'EQLAnalyticsConsentPublisher' => 1234567890123456 ], [ 'EQLAnalyticsConsentPublisher' => 'pub-123' ],
		[ 'EQLAnalyticsConsentPublisher' => "pub-1234567890123456\n" ],
		[ 'EQLAnalyticsConsentPublisher' => 'ca-pub-1234567890123456' ],
		[ 'Server' => 'https://eqlwiki.com:443' ], [ 'CanonicalServer' => 'https://test.eqlwiki.com' ],
		[ 'Server' => 'http://eqlwiki.com' ], [ 'Server' => 'http://127.0.0.1:8080' ],
		[ 'Server' => 'https://test.eqlwiki.com', 'CanonicalServer' => 'https://test.eqlwiki.com' ]
	];
	foreach ( $invalidSettings as $config ) {
		$out = analyticsFixture( [ 'config' => $config ] );
		analyticsCheck( EQLImmersive\Analytics::independentConfigurationForOutput( $out, true ) === null, 'Invalid configuration must exclude Google' );
		analyticsCheck( EQLImmersive\Analytics::preferencesForOutput( $out ) === null, 'Invalid configuration must not activate preference UI' );
	}
	foreach ( [ 'EQLAnalyticsMeasurementId', 'EQLAnalyticsConsentPublisher' ] as $key ) {
		analyticsCheck( EQLImmersive\Analytics::preferencesForOutput( analyticsFixture( [ 'unregistered' => [ $key ] ] ) ) === null,
			'Unregistered settings during a partial deployment must fail closed' );
	}
	foreach ( [
		'https://other.example/Synthetic_article', 'http://eqlwiki.com/Synthetic_article',
		'https://eqlwiki.com/Synthetic_article?token=private', 'https://eqlwiki.com/Synthetic_article#private',
		'https://eqlwiki.com:443/Synthetic_article', 'https://user:password@eqlwiki.com/Synthetic_article', 'https://eqlwiki.com'
	] as $canonical ) {
		analyticsCheck( EQLImmersive\Analytics::independentConfigurationForOutput( analyticsFixture( [ 'canonical' => $canonical ] ), true ) === null,
			'Unsafe canonical URL must be excluded' );
	}
	analyticsCheck( EQLImmersive\Analytics::independentConfigurationForOutput( analyticsFixture( [
		'query' => [ 'search' => 'private search', 'token' => 'private', 'pagefrom' => 'private' ]
	] ), true ) === expectedAnalytics(), 'Request queries must not enter public page metadata' );
	analyticsCheck( EQLImmersive\Analytics::configurationForOutput( analyticsFixture(), null, true ) === expectedAnalytics(),
		'Old hook invocation must remain compatible during deployment without an ad dependency' );

	// Exercise the real output hook with synthetic anonymous permissions and the
	// displayed revision. An administrator viewing suppressed text must not count.
	$hook = new ReflectionMethod( EQLImmersive\Hooks::class, 'addAnalytics' );
	$hookCases = array_merge( [ [], [ 'namespace' => NS_HELP ], [ 'namespace' => NS_CATEGORY ] ], $excluded, [
		[ 'publicRead' => false ], [ 'revisionPublic' => false ], [ 'revisionMissing' => true ],
		[ 'revisionId' => 0 ], [ 'revisionId' => null ]
	] );
	foreach ( $hookCases as $options ) {
		MediaWiki\MediaWikiServices::$fixture = new class( $options ) {
			public function __construct( private array $options ) {}
			public function getPermissionManager(): object { return $this; }
			public function getUserFactory(): object { return $this; }
			public function getRevisionLookup(): object { return $this; }
			public function newAnonymous(): object { return (object)[ 'anonymous' => true ]; }
			public function userCan( string $right, object $user, object $title ): bool {
				analyticsCheck( $right === 'read' && $user->anonymous, 'Eligibility must use anonymous permissions even for a signed-in visitor' );
				return $this->options['publicRead'] ?? true;
			}
			public function getRevisionById( int $id ): ?object {
				analyticsCheck( $id === ( $this->options['revisionId'] ?? 123 ), 'Revision guard must check the displayed revision' );
				if ( !$id || ( $this->options['revisionMissing'] ?? false ) ) { return null; }
				return new class( $this->options['revisionPublic'] ?? true ) {
					public function __construct( private bool $public ) {}
					public function audienceCan( int $field, int $audience ): bool {
						analyticsCheck( $field === MediaWiki\Revision\RevisionRecord::DELETED_TEXT && $audience === MediaWiki\Revision\RevisionRecord::FOR_PUBLIC,
							'Hidden text must never be included merely because the visitor can read it' );
						return $this->public;
					}
				};
			}
		};
		$out = analyticsFixture( $options );
		$hook->invoke( new EQLImmersive\Hooks(), $out );
		$eligible = !in_array( $options, $excluded, true ) && ( $options['publicRead'] ?? true ) &&
			( $options['revisionPublic'] ?? true ) && !( $options['revisionMissing'] ?? false ) &&
			( !array_key_exists( 'revisionId', $options ) || $options['revisionId'] > 0 );
		analyticsCheck( $out->jsConfig['wgEQLAnalyticsPreferences'] === $preference, 'Preference access must survive every excluded view' );
		analyticsCheck( isset( $out->jsConfig['wgEQLAnalytics'] ) === $eligible, 'Hook eligibility mismatch: ' . json_encode( $options ) );
		analyticsCheck( $out->modules === ( $eligible ? [ 'skins.EQLImmersive.analyticsPreferences', 'skins.EQLImmersive.analytics' ] :
			[ 'skins.EQLImmersive.analyticsPreferences' ] ), 'Only eligible public views may load the Google integration module' );
		analyticsCheck( !isset( $out->jsConfig['wgEQLAdSense'] ), 'Analytics must not load or configure advertising' );
	}
	foreach ( $invalidSettings as $config ) {
		$out = analyticsFixture( [ 'config' => $config ] );
		$hook->invoke( new EQLImmersive\Hooks(), $out );
		analyticsCheck( $out->jsConfig === [] && $out->modules === [], 'Invalid site settings must make the hook inert' );
	}
	$manifest = json_decode( file_get_contents( __DIR__ . '/../skins/EQLImmersive/skin.json' ), true, 512, JSON_THROW_ON_ERROR );
	analyticsCheck( $manifest['config']['EQLAnalyticsConsentPublisher']['value'] === '', 'Private publisher must default blank' );
	analyticsCheck( $manifest['ResourceModules']['skins.EQLImmersive.analytics']['dependencies'] === [ 'skins.EQLImmersive.analyticsPreferences' ],
		'Analytics must wait for the first-party preference module' );
	echo "Analytics configuration and hook checks passed: $checks\n";
}
