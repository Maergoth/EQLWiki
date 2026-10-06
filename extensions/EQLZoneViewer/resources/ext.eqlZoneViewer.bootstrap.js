( function () {
    'use strict';
    let modulePromise = null;

    function initialise() {
        const roots = document.querySelectorAll( '.eqlzv-root:not([data-eqlzv-bootstrap])' );
        if ( !roots.length ) {
            return;
        }
        roots.forEach( ( root ) => { root.dataset.eqlzvBootstrap = 'pending'; } );
        let firstConfig;
        try {
            firstConfig = JSON.parse( roots[ 0 ].dataset.eqlzvConfig || '{}' );
        } catch ( error ) {
            firstConfig = {};
        }
        if ( !firstConfig.moduleUrl ) {
            roots.forEach( ( root ) => { root.innerHTML = '<div class="eqlzv-load-error">Zone Viewer module URL is missing.</div>'; } );
            return;
        }
        modulePromise = modulePromise || import( firstConfig.moduleUrl );
        modulePromise.then( ( module ) => {
            roots.forEach( ( root ) => {
                try {
                    const config = JSON.parse( root.dataset.eqlzvConfig || '{}' );
                    module.mountZoneViewer( root, config );
                    root.dataset.eqlzvBootstrap = '1';
                } catch ( error ) {
                    console.error( '[EQLZoneViewer] Mount failed', error );
                    root.innerHTML = '<div class="eqlzv-load-error">Unable to start the Zone Viewer.</div>';
                    root.dataset.eqlzvBootstrap = 'error';
                }
            } );
        } ).catch( ( error ) => {
            console.error( '[EQLZoneViewer] Module load failed', error );
            roots.forEach( ( root ) => {
                root.innerHTML = '<div class="eqlzv-load-error">Unable to load the Zone Viewer application.</div>';
                root.dataset.eqlzvBootstrap = 'error';
            } );
        } );
    }

    if ( window.mw && mw.hook ) {
        mw.hook( 'wikipage.content' ).add( initialise );
    }
    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', initialise, { once: true } );
    } else {
        initialise();
    }
}() );
