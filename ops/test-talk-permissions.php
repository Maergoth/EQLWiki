<?php
/**
 * Read-only core permission integration with synthetic users and titles.
 * Run only with an isolated local config enabling EQLTalkContributions:
 * php maintenance/run.php ./ops/test-talk-permissions.php --conf <local-settings>
 * No pages, users, protections or blocks are saved.
 */
use MediaWiki\Block\SystemBlock;
use MediaWiki\Block\AnonIpBlockTarget;
use MediaWiki\Cache\CacheKeyHelper;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Title\Title;
use MediaWiki\User\User;

class EQLTestTalkPermissions extends Maintenance {
    private int $checks = 0;

    public function __construct() {
        parent::__construct();
        $this->addDescription( 'Check synthetic talk/subject permissions through core; no database writes.' );
    }

    private function check( bool $condition, string $description ): void {
        if ( !$condition ) {
            throw new RuntimeException( $description );
        }
        $this->checks++;
    }

    private function namedUser( bool $confirmed ): User {
        // Fully loaded in memory. These IDs and accounts are never inserted.
        return User::newFromRow( (object)[
            'actor_id' => 0,
            'user_id' => $confirmed ? 2147480001 : 2147480002,
            'user_name' => $confirmed ? 'EQL confirmed fixture' : 'EQL unconfirmed fixture',
            'user_real_name' => '',
            'user_editcount' => 0,
            'user_touched' => '20200101000000',
            'user_token' => '',
            'user_email' => $confirmed ? 'fixture@example.invalid' : '',
            'user_email_authenticated' => $confirmed ? '20200101000000' : null,
            'user_email_token' => null,
            'user_email_token_expires' => null,
            'user_registration' => '20200101000000',
        ] );
    }

    private function title( int $namespace, bool $existing, bool $protected = false ): Title {
        $title = Title::makeTitle( $namespace, 'EQL talk permission fixture' );
        $title->resetArticleID( $existing ? 2147480100 + $namespace : 0 );

        // Core's restriction store consumes this synthetic protection fixture.
        // Populate its process-local cache; never create a protected database page.
        $store = $this->getServiceContainer()->getRestrictionStore();
        $property = new ReflectionProperty( $store, 'cache' );
        $cache = $property->getValue( $store );
        $cache[CacheKeyHelper::getKeyForPage( $title )] = [
            'restrictions' => [ 'edit' => $protected ? [ 'editprotected' ] : [] ],
            'expiry' => [],
            'create_protection' => null,
            'cascade' => false,
            'cascade_sources' => [ [], [], [], [] ],
        ];
        $property->setValue( $store, $cache );
        return $title;
    }

    public function execute() {
        $services = $this->getServiceContainer();
        $config = $services->getMainConfig();
        $this->check( $services->getExtensionRegistry()->isLoaded( 'EQLTalkContributions' ), 'Extension is not loaded.' );
        $this->check( !$config->get( 'EmailConfirmToEdit' ), 'Core EmailConfirmToEdit must be disabled.' );
        $this->check( $config->get( 'EQLTalkContributionsRequireConfirmedEmail' ), 'Test requires confirmed-email policy.' );
        $this->check( !$services->getTempUserConfig()->isEnabled(), 'Temporary accounts must be disabled for IP attribution.' );

        $manager = $services->getPermissionManager();
        $anon = $services->getUserFactory()->newAnonymous( '192.0.2.45' );
        $confirmed = $this->namedUser( true );
        $unconfirmed = $this->namedUser( false );
        $this->check( $confirmed->isNamed() && $confirmed->isEmailConfirmed(), 'Confirmed fixture failed.' );
        $this->check( $unconfirmed->isNamed() && !$unconfirmed->isEmailConfirmed(), 'Unconfirmed fixture failed.' );

        foreach ( [ false, true ] as $existing ) {
            foreach ( [ NS_MAIN, NS_TALK, NS_TEMPLATE, NS_TEMPLATE_TALK, 500, 501, 502, 503 ] as $namespace ) {
                $title = $this->title( $namespace, $existing );
                foreach ( [ 'edit', 'create' ] as $action ) {
                    $anonStatus = $manager->getPermissionStatus( $action, $anon, $title );
                    $unconfirmedStatus = $manager->getPermissionStatus( $action, $unconfirmed, $title );
                    if ( $title->isTalkPage() ) {
                        $this->check( $anonStatus->isGood(), "Anonymous $action denied on talk namespace $namespace." );
                        $this->check( $unconfirmedStatus->isGood(), "Unconfirmed $action denied on talk namespace $namespace." );
                    } else {
                        $this->check( $anonStatus->hasMessage( 'eqltalkcontributions-login-required' ), 'Subject login requirement missing.' );
                        $this->check( $unconfirmedStatus->hasMessage( 'confirmedittext' ), 'Subject email requirement missing.' );
                    }
                }
                if ( $namespace === NS_MAIN || $title->isTalkPage() ) {
                    $this->check( $manager->getPermissionStatus( 'edit', $confirmed, $title )->isGood(), 'Confirmed edit denied.' );
                }
            }
        }

        $protectedTalk = $this->title( NS_TALK, true, true );
        $status = $manager->getPermissionStatus( 'edit', $anon, $protectedTalk );
        $this->check( $status->hasMessage( 'protectedpagetext' ), 'Talk protection was bypassed.' );
        $template = $this->title( NS_TEMPLATE, true );
        $this->check( !$manager->getPermissionStatus( 'edit', $confirmed, $template )->isGood(), 'Template namespace protection was bypassed.' );

        // Supply an unsaved block through the normal BlockManager hook.
        $blocked = $services->getUserFactory()->newAnonymous( '192.0.2.46' );
        $services->getHookContainer()->register( 'GetUserBlock', static function ( $user, $ip, &$block ) {
            if ( $user->getName() === '192.0.2.46' ) {
                $block = new SystemBlock( [ 'target' => new AnonIpBlockTarget( '192.0.2.46' ), 'reason' => 'Synthetic permission test' ] );
            }
        } );
        $openTalk = $this->title( NS_TALK, false );
        $this->check( $manager->getPermissionStatus( 'edit', $blocked, $openTalk )->isBlocked(), 'Block was bypassed on talk.' );
        $this->check( $manager->getPermissionStatus( 'read', $anon, $openTalk )->isGood(), 'Talk reading was denied.' );

        $this->output( "Passed {$this->checks} read-only core permission checks with in-memory fixtures.\n" );
        $this->output( "No saved revision/IP history, browser, CAPTCHA or live-site verification was performed.\n" );
    }
}

$maintClass = EQLTestTalkPermissions::class;
require_once RUN_MAINTENANCE_IF_MAIN;
