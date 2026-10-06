<?php
/**
 * Shared helper: create an SMF shadow account for a MediaWiki user.
 *
 * Used by:
 *   - smf_mediawiki_auto_login.php  (lazy creation on first forum visit)
 *   - LocalSettings.php hook        (eager creation at wiki registration)
 *
 * If the member already exists, returns the existing id_member.
 * Returns false only on a real failure (DB error, INSERT failure).
 *
 * @param string $username  The wiki username (becomes SMF member_name + real_name)
 * @param string $email     The wiki email address
 * @param string $ip        The registering user's IP (optional, defaults to '')
 * @return int|false        The SMF member ID, or false on failure
 */
function smf_create_shadow_member( $username, $email, $ip = '' ) {
    // Load SMF database settings.
    require __DIR__ . '/Settings.php';

    $mysqli = new mysqli( $db_server, $db_user, $db_passwd, $db_name );
    if ( $mysqli->connect_errno ) {
        error_log( 'smf_create_shadow_member: DB connect failed: ' . $mysqli->connect_error );
        return false;
    }
    $mysqli->set_charset( 'utf8mb4' );

    // Check if the member already exists. If so, return their id.
    $stmt = $mysqli->prepare(
        "SELECT id_member FROM {$db_prefix}members WHERE member_name = ? LIMIT 1"
    );
    if ( !$stmt ) {
        error_log( 'smf_create_shadow_member: prepare failed: ' . $mysqli->error );
        $mysqli->close();
        return false;
    }
    $stmt->bind_param( 's', $username );
    $stmt->execute();
    $result = $stmt->get_result();
    if ( $result && ( $existing = $result->fetch_assoc() ) ) {
        $existingId = (int) $existing['id_member'];
        $stmt->close();
        $mysqli->close();
        return $existingId;
    }
    $stmt->close();

    // Generate a random password (user will never log in with it directly).
    $randomPassword = bin2hex( random_bytes( 16 ) );
    $passwordSalt   = bin2hex( random_bytes( 16 ) );
    $passwordHash   = password_hash( $randomPassword, PASSWORD_BCRYPT );
    $now            = time();

    // Core columns we always insert.
    $coreColumns = [
        'member_name'     => $username,
        'real_name'       => $username,
        'email_address'   => $email,
        'passwd'          => $passwordHash,
        'password_salt'   => $passwordSalt,
        'date_registered' => $now,
        'member_ip'       => $ip,
        'member_ip2'      => $ip,
        'is_activated'    => 1,
        'id_post_group'   => 4,  // SMF "Regular Members" group
    ];

    // Discover NOT NULL columns with no default that we haven't already covered.
    $schemaResult = $mysqli->query(
        "SHOW COLUMNS FROM {$db_prefix}members WHERE `Null` = 'NO' AND `Default` IS NULL"
    );

    $extraColumns = [];
    if ( $schemaResult ) {
        while ( $col = $schemaResult->fetch_assoc() ) {
            $field = $col['Field'];
            if ( isset( $coreColumns[$field] ) || $field === 'id_member' ) {
                continue;
            }
            $type = strtolower( $col['Type'] );
            if ( strpos( $type, 'date' ) !== false ) {
                $extraColumns[$field] = '0001-01-01';
            } elseif ( preg_match( '/int|float|double|decimal|smallint|tinyint|mediumint|bigint/', $type ) ) {
                $extraColumns[$field] = 0;
            } else {
                $extraColumns[$field] = '';
            }
        }
        $schemaResult->free();
    }

    // Build dynamic INSERT.
    $allColumns   = array_merge( $coreColumns, $extraColumns );
    $colNames     = array_keys( $allColumns );
    $placeholders = implode( ', ', array_fill( 0, count( $colNames ), '?' ) );
    $colList      = implode( ', ', $colNames );

    $sql  = "INSERT INTO {$db_prefix}members ($colList) VALUES ($placeholders)";
    $stmt = $mysqli->prepare( $sql );

    if ( !$stmt ) {
        error_log( 'smf_create_shadow_member: INSERT prepare failed: ' . $mysqli->error );
        $mysqli->close();
        return false;
    }

    $types  = '';
    $values = [];
    foreach ( $allColumns as $v ) {
        $types   .= is_int( $v ) ? 'i' : 's';
        $values[] = $v;
    }

    $stmt->bind_param( $types, ...$values );

    if ( !$stmt->execute() ) {
        error_log( 'smf_create_shadow_member: INSERT failed: ' . $stmt->error );
        $stmt->close();
        $mysqli->close();
        return false;
    }

    $memberId = $stmt->insert_id;
    $stmt->close();
    $mysqli->close();

    return $memberId;
}
