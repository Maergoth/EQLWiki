<?php

namespace EQLTalkContributions;

use MediaWiki\Auth\AuthManager;
use MediaWiki\Config\Config;
use MediaWiki\Permissions\Hook\GetUserPermissionsErrorsHook;
use MediaWiki\SpecialPage\Hook\AuthChangeFormFieldsHook;

/** Site-wide policy; core still checks protections, blocks and action rights. */
class Hooks implements GetUserPermissionsErrorsHook, AuthChangeFormFieldsHook {
    private Config $config;

    public function __construct( Config $config ) {
        $this->config = $config;
    }

    public function onGetUserPermissionsErrors( $title, $user, $action, &$result ): bool {
        if ( !in_array( $action, [ 'edit', 'create' ], true ) || $title->isTalkPage() ) {
            return true;
        }

        // Temporary accounts are registered, but are not authenticated named users.
        if ( !$user->isNamed() ) {
            $result = [ 'eqltalkcontributions-login-required' ];
            return false;
        }

        if ( $this->config->get( 'EQLTalkContributionsRequireConfirmedEmail' )
            && !$user->isEmailConfirmed()
        ) {
            $result = [ 'confirmedittext' ];
            return false;
        }

        // Never clear another hook's error or override MediaWiki's remaining checks.
        return true;
    }

    public function onAuthChangeFormFields( $requests, $fieldInfo, &$formDescriptor, $action ): void {
        if ( $this->config->get( 'EQLTalkContributionsRequireConfirmedEmail' )
            && in_array( $action, [ AuthManager::ACTION_CREATE, AuthManager::ACTION_CREATE_CONTINUE ], true )
            && isset( $formDescriptor['email'] )
        ) {
            // Disabling core EmailConfirmToEdit would otherwise make signup email optional.
            $formDescriptor['email']['required'] = true;
            $formDescriptor['email']['label-message'] = 'createacct-emailrequired';
        }
    }
}
