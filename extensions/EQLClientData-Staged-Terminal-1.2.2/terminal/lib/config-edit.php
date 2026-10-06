<?php

declare( strict_types = 1 );

if ( $argc < 3 ) {
    fwrite( STDERR, "Usage: php config-edit.php <operation> <LocalSettings.php>\n" );
    exit( 2 );
}

$operation = $argv[1];
$path = $argv[2];

if ( !is_file( $path ) ) {
    fwrite( STDERR, "LocalSettings.php not found: $path\n" );
    exit( 2 );
}

$text = file_get_contents( $path );
if ( $text === false ) {
    fwrite( STDERR, "Could not read: $path\n" );
    exit( 2 );
}

function setBoolSetting( string $text, string $name, bool $value, bool $append = true ): string {
    $pattern = '/^\s*\$' . preg_quote( $name, '/' ) . '\s*=\s*(?:true|false)\s*;\s*$/m';
    $replacement = '$' . $name . ' = ' . ( $value ? 'true' : 'false' ) . ';';

    if ( preg_match( $pattern, $text ) ) {
        return preg_replace( $pattern, $replacement, $text ) ?? $text;
    }

    if ( !$append ) {
        throw new RuntimeException( "\$$name was not found." );
    }

    return rtrim( $text ) . "\n\n$replacement\n";
}

function setStringSetting( string $text, string $name, string $value, bool $append = false ): string {
    $pattern = '/^\s*\$' . preg_quote( $name, '/' ) . '\s*=.*?;\s*$/m';
    $escaped = str_replace( [ '\\', "'" ], [ '\\\\', "\\'" ], $value );
    $replacement = '$' . $name . " = '" . $escaped . "';";

    if ( preg_match( $pattern, $text ) ) {
        return preg_replace( $pattern, $replacement, $text ) ?? $text;
    }

    if ( !$append ) {
        throw new RuntimeException( "\$$name was not found." );
    }

    return rtrim( $text ) . "\n\n$replacement\n";
}

function removeManagedBlockAndLooseSettings( string $text ): string {
    $begin = '// BEGIN EQLCLIENTDATA STAGED ROLLOUT';
    $end = '// END EQLCLIENTDATA STAGED ROLLOUT';
    $pattern = '/' . preg_quote( $begin, '/' ) . '.*?' . preg_quote( $end, '/' ) . '\s*/s';
    $text = preg_replace( $pattern, '', $text ) ?? $text;

    $linePatterns = [
        '/^\s*wfLoadExtension\(\s*[\'\"]EQLClientData[\'\"]\s*\)\s*;\s*$/m',
        '/^\s*\$wgEQLClientDataEnableEra\s*=.*?;\s*$/m',
        '/^\s*\$wgEQLClientDataEnableVerification\s*=.*?;\s*$/m',
        '/^\s*\$wgEQLClientDataEnableSpellOverrides\s*=.*?;\s*$/m',
        '/^\s*\$wgEQLClientDataVerificationModuleName\s*=.*?;\s*$/m',
        '/^\s*\$wgEQLClientDataEraStatusClientTtlSeconds\s*=.*?;\s*$/m',
        '/^\s*\$wgEQLClientDataApiMaxTitles\s*=.*?;\s*$/m',
    ];

    foreach ( $linePatterns as $linePattern ) {
        $text = preg_replace( $linePattern, '', $text ) ?? $text;
    }

    return $text;
}

function insertBeforeSpellSliderOrEnd( string $text, string $block ): string {
    $lines = preg_split( '/(?<=\n)/', $text );
    if ( !is_array( $lines ) ) {
        $lines = [ $text ];
    }

    foreach ( $lines as $index => $line ) {
        if ( str_contains( $line, 'SpellLevelSlider.php' ) && str_contains( $line, 'require' ) ) {
            array_splice( $lines, $index, 0, [ "\n" . $block . "\n" ] );
            return implode( '', $lines );
        }
    }

    if ( preg_match( '/\?>\s*$/', $text ) ) {
        return preg_replace( '/\?>\s*$/', "\n" . $block . "\n?>\n", $text ) ?? $text;
    }

    return rtrim( $text ) . "\n\n" . $block . "\n";
}

try {
    switch ( $operation ) {
        case 'stage00':
        case 'rollback-safety':
            $text = setBoolSetting( $text, 'wgInvalidateCacheOnLocalSettingsChange', false );
            break;

        case 'stage01':
            $text = setBoolSetting( $text, 'wgInvalidateCacheOnLocalSettingsChange', false );
            $text = removeManagedBlockAndLooseSettings( $text );

            $block = <<<'BLOCK'
// BEGIN EQLCLIENTDATA STAGED ROLLOUT
// Stage 01: batched, revision-aware era metadata.
wfLoadExtension( 'EQLClientData' );

$wgEQLClientDataEnableEra = true;

// Later stages remain disabled until tested individually.
$wgEQLClientDataEnableVerification = false;
$wgEQLClientDataEnableSpellOverrides = false;
$wgEQLClientDataVerificationModuleName = '';

// Cross-tab browser results stay fresh for at most 30 seconds.
$wgEQLClientDataEraStatusClientTtlSeconds = 30;

// One request can cover a large link-heavy page.
$wgEQLClientDataApiMaxTitles = 500;
// END EQLCLIENTDATA STAGED ROLLOUT
BLOCK;

            $text = insertBeforeSpellSliderOrEnd( $text, $block );
            break;

        case 'stage02':
            if ( !str_contains( $text, '// BEGIN EQLCLIENTDATA STAGED ROLLOUT' ) ) {
                throw new RuntimeException( 'Managed EQLClientData block not found. Apply Stage 01 first.' );
            }
            $text = setBoolSetting( $text, 'wgEQLClientDataEnableVerification', true, false );
            $text = setStringSetting( $text, 'wgEQLClientDataVerificationModuleName', '', false );
            break;

        case 'stage03':
            if ( !str_contains( $text, '// BEGIN EQLCLIENTDATA STAGED ROLLOUT' ) ) {
                throw new RuntimeException( 'Managed EQLClientData block not found. Apply Stage 01 first.' );
            }
            $text = setBoolSetting( $text, 'wgEQLClientDataEnableSpellOverrides', true, false );
            break;

        case 'stage04':
            if ( !str_contains( $text, '// BEGIN EQLCLIENTDATA STAGED ROLLOUT' ) ) {
                throw new RuntimeException( 'Managed EQLClientData block not found. Apply Stage 01 first.' );
            }
            $text = setStringSetting(
                $text,
                'wgEQLClientDataVerificationModuleName',
                'skins.EQLImmersive.verification',
                false
            );
            break;

        default:
            throw new RuntimeException( "Unknown operation: $operation" );
    }
} catch ( Throwable $e ) {
    fwrite( STDERR, 'ERROR: ' . $e->getMessage() . "\n" );
    exit( 1 );
}

if ( file_put_contents( $path, $text ) === false ) {
    fwrite( STDERR, "Could not write: $path\n" );
    exit( 1 );
}
