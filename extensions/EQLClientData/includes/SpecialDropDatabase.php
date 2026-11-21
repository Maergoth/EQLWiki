<?php
declare( strict_types = 1 );

namespace EQLClientData;

use MediaWiki\Html\Html;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;

/** Public aggregate browser. Raw observations and contributor identities stay private. */
final class SpecialDropDatabase extends SpecialPage {
    public function __construct( private readonly DropDatabaseRepository $repository ) {
        parent::__construct( 'DropDatabase' );
    }

    public function execute( $subPage ): void {
        $this->setHeaders();
        $out = $this->getOutput();
        $out->addModuleStyles( 'ext.eqlDropDatabase.styles' );
        $out->addModules( 'ext.eqlDropDatabase.browser' );
        $out->setPageTitle( $this->msg( 'dropdatabase' )->text() );
        $request = $this->getRequest();
        if ( $request->getText( 'entry' ) !== '' ) {
            $this->submissionPage();
            return;
        }
        $filters = [];
        foreach ( [ 'q', 'zone', 'npc', 'item', 'instance' ] as $field ) {
            $filters[$field] = mb_substr( trim( $request->getText( $field ) ), 0, DropDatabaseRepository::FILTER_MAX_CHARS );
        }
        $filters['offset'] = max( 0, $request->getInt( 'offset' ) );
        $filters['limit'] = 50;
        $out->addHTML( Html::openElement( 'div', [ 'class' => 'eql-drop-database' ] ) );
        $out->addHTML( Html::element( 'p', [], 'Browse observed corpse loot. Expand an entry to see its individual submissions.' ) );
        $out->addHTML( Html::rawElement( 'details', [ 'class' => 'eql-drop-about' ],
            Html::element( 'summary', [], 'About these observations' ) .
            Html::element( 'p', [], 'Observed % is the item’s share of reported loot for the same zone, NPC and instance, across servers and tiers. It is not a per-kill drop chance: unlooted items, zero-drop kills and overlapping reports are not fully known.' ) .
            Html::element( 'p', [], 'Times reported counts loot events; a single event may contain several copies. Quantity appears inside the submission history. Only entries with at least two reports appear. Results refresh within five minutes.' ) ) );
        $out->addHTML( $this->form( $filters ) );
        try {
            $summary = $this->repository->getSummary( $filters );
        } catch ( \Throwable $error ) {
            $out->addHTML( Html::element( 'p', [ 'role' => 'status' ], 'The drop database is temporarily unavailable. Please try again later.' ) . '</div>' );
            return;
        }
        if ( !$summary['available'] ) {
            $out->addHTML( Html::element( 'p', [ 'role' => 'status' ], 'Observation storage has not been activated on this wiki.' ) . '</div>' );
            return;
        }
        if ( $summary['windowed'] ) {
            $out->addHTML( Html::element( 'p', [ 'role' => 'status' ], 'Showing a recent window of ' . number_format( $summary['windowLimit'] ) . ' loot reports. Older reports are outside these totals.' ) );
        }
        if ( ( $summary['cumulativeStatus'] ?? '' ) === 'backfilling' ) {
            $out->addHTML( Html::element( 'p', [ 'role' => 'status' ], 'All-history totals are being prepared. These results still use the recent received-report view.' ) );
        }
        $total = (int)$summary['totalRows'];
        $out->addHTML( Html::element( 'p', [ 'role' => 'status' ], number_format( $total ) . ' matching entries' ) );
        if ( !$summary['rows'] ) {
            $out->addHTML( Html::element( 'p', [], 'No repeated corpse-loot reports match these filters yet. Try clearing a filter.' ) );
        } else {
            $out->addHTML( $this->table( $summary['rows'], $summary['snapshot'], $summary['detailsAvailable'] ?? true ) );
        }
        $nav = '';
        if ( $filters['offset'] > 0 ) {
            $nav .= $this->pageLink( 'Previous', $filters, max( 0, $filters['offset'] - 50 ) ) . ' ';
        }
        if ( $filters['offset'] + count( $summary['rows'] ) < $total ) {
            $nav .= $this->pageLink( 'Next', $filters, $filters['offset'] + 50 );
        }
        $out->addHTML( Html::rawElement( 'nav', [ 'aria-label' => 'Drop database pages' ], $nav ) );
        $out->addHTML( '</div>' );
    }

    private function form( array $filters ): string {
        $html = Html::openElement( 'form', [ 'method' => 'get', 'action' => $this->getPageTitle()->getLocalURL(), 'class' => 'eql-drop-filters' ] );
        $html .= Html::element( 'input', [ 'type' => 'hidden', 'name' => 'title', 'value' => $this->getPageTitle()->getPrefixedText() ] );
        foreach ( [ 'q' => 'Search', 'zone' => 'Zone', 'npc' => 'NPC', 'item' => 'Item' ] as $key => $label ) {
            $html .= Html::rawElement( 'label', [], Html::element( 'span', [], $label ) . Html::element( 'input', [ 'name' => $key, 'value' => $filters[$key], 'type' => 'search', 'maxlength' => DropDatabaseRepository::FILTER_MAX_CHARS ] ) );
        }
        $options = '';
        foreach ( [ '' => 'All instances', 'normal' => 'Normal', 'raid' => 'Raid', 'unknown' => 'Unknown' ] as $value => $label ) {
            $options .= Html::element( 'option', [ 'value' => $value, 'selected' => $filters['instance'] === $value ], $label );
        }
        $html .= Html::rawElement( 'label', [], Html::element( 'span', [], 'Instance' ) . Html::rawElement( 'select', [ 'name' => 'instance' ], $options ) );
        $html .= Html::element( 'button', [ 'type' => 'submit' ], 'Find drops' );
        $html .= Html::element( 'a', [ 'href' => $this->getPageTitle()->getLocalURL() ], 'Clear filters' );
        return $html . '</form>';
    }

    private function table( array $rows, string $snapshot, bool $detailsAvailable = true ): string {
        $html = '<div class="eql-drop-table-scroll"><table class="wikitable eql-drop-table"><caption>Repeated observed loot</caption><thead><tr>';
        foreach ( [ 'Zone', 'NPC', 'Item', 'Instance', 'Times reported', 'Observed %', 'Submissions' ] as $label ) {
            $html .= Html::element( 'th', [ 'scope' => 'col' ], $label );
        }
        $html .= '</tr></thead><tbody>';
        foreach ( $rows as $row ) {
            $html .= '<tr>';
            foreach ( [ 'zone', 'npc', 'item' ] as $field ) {
                $label = $row[$field . 'Title'] ?: $row[$field];
                $title = Title::newFromText( $label );
                $link = $title && $title->getNamespace() === NS_MAIN
                    ? $this->getLinkRenderer()->makeLink( $title, $label ) : Html::element( 'span', [], $label );
                $html .= Html::rawElement( 'td', [], $link );
            }
            foreach ( [ ucfirst( $row['instance'] ), number_format( $row['reports'] ), number_format( $row['reportShare'], 1 ) . '%' ] as $value ) {
                $html .= Html::element( 'td', [], $value );
            }
            if ( !$detailsAvailable ) {
                $html .= Html::element( 'td', [], 'Submission history is temporarily unavailable.' ) . '</tr>';
                continue;
            }
            $fallback = Html::element( 'a', [ 'href' => $this->getPageTitle()->getLocalURL( [
                'entry' => $row['entryId'], 'snapshot' => $snapshot
            ] ) ], 'Open submission history' );
            $html .= Html::rawElement( 'td', [], Html::rawElement( 'details', [
                'class' => 'eql-drop-entry', 'data-entry' => $row['entryId'], 'data-snapshot' => $snapshot
            ], Html::element( 'summary', [], 'View submissions' ) .
                Html::rawElement( 'div', [ 'class' => 'eql-drop-detail-content', 'aria-live' => 'polite' ],
                    Html::rawElement( 'p', [], $fallback ) ) ) );
            $html .= '</tr>';
        }
        return $html . '</tbody></table></div>';
    }

    private function pageLink( string $label, array $filters, int $offset ): string {
        $filters['offset'] = $offset;
        unset( $filters['limit'] );
        return Html::element( 'a', [ 'href' => $this->getPageTitle()->getLocalURL( array_filter( $filters, static fn ( $v ) => $v !== '' ) ) ], $label );
    }

    /** Real history fallback for browsers without JavaScript; never a sample page. */
    private function submissionPage(): void {
        $out = $this->getOutput();
        $request = $this->getRequest();
        $entry = $request->getText( 'entry' );
        $snapshot = $request->getText( 'snapshot' );
        $offset = max( 0, $request->getInt( 'offset' ) );
        $out->addHTML( Html::element( 'a', [ 'href' => $this->getPageTitle()->getLocalURL() ], 'Back to drop database' ) );
        try {
            $detail = $this->repository->getDetail( $entry, $snapshot, $offset, 25 );
        } catch ( \Throwable $error ) {
            $out->addHTML( Html::element( 'p', [ 'role' => 'status' ], 'This submission history is unavailable or has changed. Return to the database and expand the current entry.' ) );
            return;
        }
        $out->addHTML( Html::element( 'h2', [], 'Submission history' ) );
        $out->addHTML( Html::element( 'p', [], 'Times are UTC. Character names are self-reported and optional; older submissions did not collect them. Each row is one loot event; quantity counts item copies.' ) );
        $html = '<div class="eql-drop-table-scroll"><table class="wikitable eql-drop-submissions"><thead><tr>';
        foreach ( [ 'Character', 'Observed at (UTC)', 'Submitted at (UTC)', 'Quantity' ] as $label ) {
            $html .= Html::element( 'th', [ 'scope' => 'col' ], $label );
        }
        $html .= '</tr></thead><tbody>';
        foreach ( $detail['rows'] as $row ) {
            $html .= '<tr>';
            foreach ( [ $row['characterName'] ?? 'Not provided', $row['observedAt'] ?? 'Not available',
                $row['receivedAt'] ?? 'Not available', (string)$row['quantity'] ] as $value ) {
                $html .= Html::element( 'td', [], $value );
            }
            $html .= '</tr>';
        }
        $out->addHTML( $html . '</tbody></table></div>' );
        $links = '';
        foreach ( [ 'Previous' => $offset > 0 ? max( 0, $offset - 25 ) : null,
            'Next' => $detail['hasMore'] ? $offset + 25 : null ] as $label => $next ) {
            if ( $next === null ) { continue; }
            $links .= Html::element( 'a', [ 'href' => $this->getPageTitle()->getLocalURL( [
                'entry' => $entry, 'snapshot' => $snapshot, 'offset' => $next
            ] ) ], $label ) . ' ';
        }
        $out->addHTML( Html::rawElement( 'nav', [ 'aria-label' => 'Submission history pages' ], $links ) );
    }

    protected function getGroupName(): string { return 'lists'; }
}
