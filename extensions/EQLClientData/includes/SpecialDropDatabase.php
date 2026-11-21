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
        $out->setPageTitle( $this->msg( 'dropdatabase' )->text() );
        $request = $this->getRequest();
        $filters = [];
        foreach ( [ 'q', 'zone', 'npc', 'item', 'server', 'instance' ] as $field ) {
            $filters[$field] = mb_substr( trim( $request->getText( $field ) ), 0, 230 );
        }
        $filters['offset'] = max( 0, min( 100000, $request->getInt( 'offset' ) ) );
        $filters['limit'] = 50;
        $out->addHTML( Html::openElement( 'div', [ 'class' => 'eql-drop-database' ] ) );
        $out->addHTML( Html::element( 'p', [], 'Browse item reports collected by Eye of Zomm. Each entry needs at least two reports. Percentages show the share of reported loot from the same source, not the chance of a drop per kill.' ) );
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
        $out->addHTML( Html::element( 'p', [ 'class' => 'eql-drop-legend' ],
            'Only loot explicitly reported from a corpse is included. Reports can overlap between players; quantities and contributors are counted separately. Normal, raid and unknown instances remain separate. Results refresh within five minutes.' ) );
        if ( $summary['windowed'] ) {
            $out->addHTML( Html::element( 'p', [ 'role' => 'status' ], 'Showing a recent window of ' . number_format( $summary['windowLimit'] ) . ' loot reports. Older reports are outside these totals.' ) );
        }
        $total = (int)$summary['totalRows'];
        $out->addHTML( Html::element( 'p', [ 'role' => 'status' ], number_format( $total ) . ' matching entries' ) );
        if ( !$summary['rows'] ) {
            $out->addHTML( Html::element( 'p', [], 'No repeated corpse-loot reports match these filters yet. Try clearing a filter.' ) );
        } else {
            $out->addHTML( $this->table( $summary['rows'] ) );
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
        foreach ( [ 'q' => 'Search', 'zone' => 'Zone', 'npc' => 'NPC', 'item' => 'Item', 'server' => 'Server' ] as $key => $label ) {
            $html .= Html::rawElement( 'label', [], Html::element( 'span', [], $label ) . Html::element( 'input', [ 'name' => $key, 'value' => $filters[$key], 'type' => 'search', 'maxlength' => 230 ] ) );
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

    private function table( array $rows ): string {
        $html = '<div class="eql-drop-table-scroll"><table class="wikitable eql-drop-table"><caption>Repeated observed loot</caption><thead><tr>';
        foreach ( [ 'Zone', 'NPC', 'Item', 'Server', 'Instance', 'Tier', 'Reports', 'Share of reported loot', 'Quantity', 'Contributors' ] as $label ) {
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
            foreach ( [ $row['server'] ?: 'Unknown', ucfirst( $row['instance'] ), $row['tier'] === null ? 'Unknown' : (string)$row['tier'],
                number_format( $row['reports'] ), number_format( $row['reportShare'], 1 ) . '% (' . number_format( $row['reports'] ) . '/' . number_format( $row['totalReports'] ) . ' reports)',
                number_format( $row['quantity'] ), number_format( $row['contributors'] ) ] as $value ) {
                $html .= Html::element( 'td', [], $value );
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table></div>';
    }

    private function pageLink( string $label, array $filters, int $offset ): string {
        $filters['offset'] = $offset;
        unset( $filters['limit'] );
        return Html::element( 'a', [ 'href' => $this->getPageTitle()->getLocalURL( array_filter( $filters, static fn ( $v ) => $v !== '' ) ) ], $label );
    }

    protected function getGroupName(): string { return 'lists'; }
}
