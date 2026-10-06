<?php
/**
 * One-off backfill: create an SMF forum account for every existing wiki user
 * who doesn't have one yet.
 *
 * Run from the wiki root via CLI:
 *   php maintenance/run.php backfill_smf_accounts.php
 *
 * Or from the maintenance directory:
 *   php run.php ../backfill_smf_accounts.php
 *
 * Safe to re-run — accounts that already exist are skipped.
 */

if ( PHP_SAPI !== 'cli' ) {
    die( "This script must be run from the command line.\n" );
}

require_once __DIR__ . '/maintenance/Maintenance.php';

class BackfillSmfAccounts extends Maintenance {

    public function __construct() {
        parent::__construct();
        $this->addDescription( 'Create SMF forum accounts for wiki users that lack one.' );
        $this->addOption( 'dry-run', 'List users that would be created without inserting.', false, false );
        $this->addOption( 'limit', 'Maximum number of users to process (0 = all).', false, true );
    }

    public function execute() {
        global $IP;

        $dryRun = $this->hasOption( 'dry-run' );
        $limit  = (int)$this->getOption( 'limit', 0 );

        require_once "$IP/bb/smf_create_member.php";

        $dbr = $this->getDB( DB_REPLICA );

        // Pull all wiki users with their email. user_email may be empty for
        // accounts that never confirmed; we still pass it through so SMF gets
        // whatever the wiki has.
        $res = $dbr->newSelectQueryBuilder()
            ->select( [ 'user_id', 'user_name', 'user_email', 'user_registration' ] )
            ->from( 'user' )
            ->orderBy( 'user_id' )
            ->caller( __METHOD__ )
            ->fetchResultSet();

        $created = 0;
        $existed = 0;
        $failed  = 0;
        $count   = 0;

        foreach ( $res as $row ) {
            $count++;
            if ( $limit > 0 && $count > $limit ) {
                break;
            }

            $username = $row->user_name;
            $email    = (string)$row->user_email;

            if ( $dryRun ) {
                $this->output( "[dry-run] Would process: $username <$email>\n" );
                continue;
            }

            $result = smf_create_shadow_member( $username, $email, '' );

            if ( $result === false ) {
                $this->output( "FAIL: $username (see bb/error_log)\n" );
                $failed++;
            } else {
                // The helper returns the existing id_member if the account already
                // existed. We don't have a clean way to distinguish, so we just
                // report success — re-runs are idempotent.
                $this->output( "OK:   $username -> SMF id $result\n" );
                $created++;
            }
        }

        $this->output( "\n--- Summary ---\n" );
        $this->output( "Wiki users scanned: $count\n" );
        if ( $dryRun ) {
            $this->output( "(dry run — no changes made)\n" );
        } else {
            $this->output( "Created or already-existed: $created\n" );
            $this->output( "Failed: $failed\n" );
        }
    }
}

$maintClass = BackfillSmfAccounts::class;
require_once RUN_MAINTENANCE_IF_MAIN;
