<?php
/**
 * Source-only regression for the EQL talk/subject contribution policy.
 *
 * Run from the repository root: php ops/test-talk-contributions.php
 * Loads core classes only; never loads LocalSettings.php, contacts a database,
 * creates accounts, saves revisions, or sends mail. Navigation dependencies are
 * synthetic, so these checks do not verify live permissions or IP attribution.
 *
 * @license GPL-2.0-or-later
 */

use EQLTalkContributions\Hooks;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Config\HashConfig;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\MediaWikiServices;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Permissions\PermissionStatus;
use MediaWiki\Skin\SkinTemplate;
use MediaWiki\Title\NamespaceInfo;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

if ( PHP_SAPI !== 'cli' ) {
    exit( "This source-only test must run from the command line.\n" );
}

$root = dirname( __DIR__ );
require_once $root . '/includes/AutoLoader.php';
require_once $root . '/includes/Defines.php';
require_once $root . '/extensions/EQLTalkContributions/includes/Hooks.php';

// NamespaceInfo::isTalk() performs namespace arithmetic without services/state.
$namespaceInfo = ( new ReflectionClass( NamespaceInfo::class ) )->newInstanceWithoutConstructor();

class EqlTalkFixtureTitle extends Title {
    public function __construct( private int $namespace, private NamespaceInfo $namespaceInfo ) {
    }

    public function getNamespace(): int {
        return $this->namespace;
    }

    public function isTalkPage(): bool {
        return $this->namespaceInfo->isTalk( $this->namespace );
    }

    public function isSpecialPage(): bool {
        return $this->namespace === NS_SPECIAL;
    }

    public function getLocalURL( $query = '' ): string {
        return '/wiki/Synthetic_' . $this->namespace . ( $query !== '' ? '?' . $query : '' );
    }
}

class EqlTalkFixtureUser extends User {
    public function __construct(
        private bool $named,
        private bool $registered,
        private bool $confirmed
    ) {
    }

    public function isNamed(): bool {
        return $this->named;
    }

    public function isRegistered(): bool {
        return $this->registered;
    }

    public function isEmailConfirmed(): bool {
        return $this->confirmed;
    }
}

$checks = 0;
function eqlTalkCheck( bool $condition, string $message ): void {
    global $checks;
    $checks++;
    if ( !$condition ) {
        throw new RuntimeException( $message );
    }
}

$policy = new Hooks( new HashConfig( [ 'EQLTalkContributionsRequireConfirmedEmail' => true ] ) );
$withoutEmail = new Hooks( new HashConfig( [ 'EQLTalkContributionsRequireConfirmedEmail' => false ] ) );
$users = [
    'anonymous' => new EqlTalkFixtureUser( false, false, false ),
    'temporary' => new EqlTalkFixtureUser( false, true, false ),
    'unconfirmed' => new EqlTalkFixtureUser( true, true, false ),
    'confirmed' => new EqlTalkFixtureUser( true, true, true ),
];

// Core namespace pairs, the two custom Magelo pairs, and Scribunto's Module pair.
$subjects = [ NS_MAIN, NS_USER, NS_PROJECT, NS_FILE, NS_MEDIAWIKI,
    NS_TEMPLATE, NS_HELP, NS_CATEGORY, 500, 502, 828 ];
$existingError = [ 'synthetic-existing-protection', 'preserve-me' ];
foreach ( $subjects as $namespace ) {
    foreach ( [ $namespace, $namespace + 1 ] as $currentNamespace ) {
        $title = new EqlTalkFixtureTitle( $currentNamespace, $namespaceInfo );
        $talk = $currentNamespace === $namespace + 1;
        foreach ( $users as $name => $user ) {
            foreach ( [ 'edit', 'create' ] as $action ) {
                $result = $existingError;
                $allowed = $policy->onGetUserPermissionsErrors( $title, $user, $action, $result );
                $expectedAllowed = $talk || $name === 'confirmed';
                $label = "$name $action namespace $currentNamespace";
                eqlTalkCheck( $allowed === $expectedAllowed, "$label: incorrect policy result" );
                $expectedError = $expectedAllowed ? $existingError : (
                    $user->isNamed() ? [ 'confirmedittext' ] : [ 'eqltalkcontributions-login-required' ]
                );
                eqlTalkCheck( $result === $expectedError, "$label: incorrect or discarded error" );

                $result = $existingError;
                $allowed = $withoutEmail->onGetUserPermissionsErrors( $title, $user, $action, $result );
                $expectedAllowed = $talk || $user->isNamed();
                eqlTalkCheck( $allowed === $expectedAllowed, "$label: email option bypassed named-account rule" );
                eqlTalkCheck(
                    $result === ( $expectedAllowed ? $existingError : [ 'eqltalkcontributions-login-required' ] ),
                    "$label: disabled email requirement changed another error"
                );
            }
        }
    }
}

foreach ( [ 'read', 'move', 'delete', 'upload', 'createpage', 'createtalk' ] as $action ) {
    foreach ( [ NS_MAIN, NS_TALK ] as $namespace ) {
        foreach ( $users as $name => $user ) {
            $result = $existingError;
            eqlTalkCheck(
                $policy->onGetUserPermissionsErrors(
                    new EqlTalkFixtureTitle( $namespace, $namespaceInfo ), $user, $action, $result
                ) === true && $result === $existingError,
                "$name $action namespace $namespace: unrelated action changed"
            );
        }
    }
}

$validation = static fn ( $value ) => $value === '' ? 'synthetic-invalid-email' : true;
$descriptor = [
    'email' => [ 'type' => 'email', 'required' => false, 'label-message' => 'email',
        'validation-callback' => $validation, 'default' => 'example@example.invalid' ],
    'username' => [ 'type' => 'text', 'required' => true ],
    'captcha' => [ 'type' => 'text', 'required' => true ],
];
foreach ( [ AuthManager::ACTION_CREATE, AuthManager::ACTION_CREATE_CONTINUE ] as $action ) {
    $form = $descriptor;
    $policy->onAuthChangeFormFields( [], [], $form, $action );
    $expected = $descriptor;
    $expected['email']['required'] = true;
    $expected['email']['label-message'] = 'createacct-emailrequired';
    eqlTalkCheck( $form === $expected, "$action: signup email requirement damaged fields or validation" );
    eqlTalkCheck( $form['email']['validation-callback']( '' ) === 'synthetic-invalid-email',
        "$action: signup email validation no longer runs" );
    $form = $descriptor;
    $withoutEmail->onAuthChangeFormFields( [], [], $form, $action );
    eqlTalkCheck( $form === $descriptor, "$action: disabled option still altered signup" );

    $form = [ 'username' => $descriptor['username'] ];
    $expected = $form;
    $policy->onAuthChangeFormFields( [], [], $form, $action );
    eqlTalkCheck( $form === $expected, "$action: inserted an email field when none was supplied" );
}
foreach ( [ AuthManager::ACTION_LOGIN, AuthManager::ACTION_LOGIN_CONTINUE,
    AuthManager::ACTION_CHANGE, AuthManager::ACTION_LINK, AuthManager::ACTION_REMOVE ] as $action
) {
    $form = $descriptor;
    $policy->onAuthChangeFormFields( [], [], $form, $action );
    eqlTalkCheck( $form === $descriptor, "$action: non-signup form changed" );
}

class EqlTalkFixturePermissionManager extends PermissionManager {
    public array $calls = [];

    public function __construct( private Hooks $policy, private ?string $otherError = null ) {
    }

    public function getPermissionStatus(
        $action, User $user, LinkTarget $page, $rigor = self::RIGOR_SECURE, $short = false
    ): PermissionStatus {
        $this->calls[] = [ $action, $rigor ];
        $status = PermissionStatus::newEmpty();
        $result = [];
        if ( !$this->policy->onGetUserPermissionsErrors( $page, $user, $action, $result ) ) {
            $status->fatal( ...$result );
        }
        if ( $this->otherError !== null ) {
            $status->fatal( $this->otherError );
        }
        return $status;
    }
}

class EqlTalkFixtureSkin extends SkinTemplate {
    public function __construct( private ?Title $fixtureTitle, private User $fixtureUser ) {
    }

    public function getSkinName(): string {
        return 'eqlimmersive';
    }

    public function getTitle(): ?Title {
        return $this->fixtureTitle;
    }

    public function getUser(): User {
        return $this->fixtureUser;
    }
}

// Isolate special-page URL generation; localization/routing is outside this test.
class EqlTalkFixtureSpecialPage {
    public static function getTitleFor( $name ): object {
        eqlTalkCheck( $name === 'Confirmemail', 'Verification action has incorrect special page' );
        return new class {
            public function getLocalURL(): string {
                return '/wiki/Special:Confirmemail';
            }
        };
    }
}
eqlTalkCheck( !class_exists( 'MediaWiki\\SpecialPage\\SpecialPage', false ),
    'This suite must run standalone before SpecialPage is loaded' );
class_alias( EqlTalkFixtureSpecialPage::class, 'MediaWiki\\SpecialPage\\SpecialPage' );
require_once $root . '/skins/EQLImmersive/includes/Hooks.php';

// An empty core container has no database services or private configuration.
MediaWikiServices::allowGlobalInstance();
$instance = new ReflectionProperty( MediaWikiServices::class, 'instance' );
$oldInstance = $instance->getValue();
eqlTalkCheck( $oldInstance === null, 'This suite must not run inside a bootstrapped wiki' );
$skinHooks = new EQLImmersive\Hooks();
$navigationCases = [
    [ 'anonymous talk', NS_TALK, 'anonymous', $policy, null, false, 0 ],
    [ 'temporary talk', NS_TALK, 'temporary', $policy, null, false, 0 ],
    [ 'unconfirmed talk', NS_TALK, 'unconfirmed', $policy, null, false, 1 ],
    [ 'unconfirmed subject', NS_MAIN, 'unconfirmed', $policy, null, true, 1 ],
    [ 'confirmed subject', NS_MAIN, 'confirmed', $policy, null, false, 0 ],
    [ 'email option disabled', NS_MAIN, 'unconfirmed', $withoutEmail, null, false, 1 ],
    [ 'protected talk', NS_TALK, 'unconfirmed', $policy, 'protectedpage', false, 1 ],
    [ 'special page', NS_SPECIAL, 'unconfirmed', $policy, null, false, 0 ],
];
try {
    foreach ( $navigationCases as [ $label, $namespace, $name, $selectedPolicy,
        $otherError, $expectedVerify, $expectedCalls ]
    ) {
        $manager = new EqlTalkFixturePermissionManager( $selectedPolicy, $otherError );
        $services = new MediaWikiServices( new HashConfig() );
        $services->defineService( 'PermissionManager', static fn () => $manager );
        $instance->setValue( null, $services );
        $actionKey = $otherError !== null ? 'viewsource' : 'edit';
        $links = [ 'views' => [
            'view' => [ 'id' => 'ca-view', 'text' => 'Read', 'href' => '/wiki/Synthetic' ],
            $actionKey => [ 'id' => 'ca-' . $actionKey, 'text' => 'Synthetic edit action',
                'href' => '/wiki/Synthetic?action=edit' ],
        ] ];
        $originalAction = $links['views'][$actionKey];
        $skinHooks->onSkinTemplateNavigation__Universal(
            new EqlTalkFixtureSkin( new EqlTalkFixtureTitle( $namespace, $namespaceInfo ), $users[$name] ),
            $links
        );
        eqlTalkCheck( isset( $links['views']['eql-verify-email'] ) === $expectedVerify,
            "$label: incorrect Verify2Edit visibility" );
        if ( $expectedVerify ) {
            eqlTalkCheck( !isset( $links['views'][$actionKey] ) &&
                $links['views']['eql-verify-email']['href'] === '/wiki/Special:Confirmemail',
                "$label: verification did not replace the edit action" );
        } else {
            eqlTalkCheck( ( $links['views'][$actionKey] ?? null ) === $originalAction,
                "$label: existing edit/view-source action changed" );
        }
        eqlTalkCheck( count( $manager->calls ) === $expectedCalls,
            "$label: unexpected permission checks" );
        if ( $expectedCalls ) {
            eqlTalkCheck( $manager->calls === [ [ 'edit', PermissionManager::RIGOR_QUICK ] ],
                "$label: navigation used incorrect action or permission rigor" );
        }
    }
} finally {
    $instance->setValue( null, $oldInstance );
}

echo "Passed $checks source-only talk policy, signup, and navigation checks.\n";
echo "Live blocks/protections, save/API/VisualEditor flows, CAPTCHA, and IP revision attribution require integration checks.\n";
