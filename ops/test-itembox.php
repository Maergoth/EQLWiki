<?php
/**
 * Read-only integration checks for the reviewed Template:Itembox source.
 *
 * Run with a configured local MediaWiki installation:
 *   php maintenance/run.php ./ops/test-itembox.php
 *
 * The template revision is supplied in memory through the parser hook. This
 * script never saves wiki pages, purges pages, or runs maintenance write jobs.
 */
use MediaWiki\Content\WikitextContent;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Revision\MutableRevisionRecord;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;

class EQLTestItembox extends Maintenance {
    private int $checks = 0;
    /** @var int[] Generated-marker counts for the most recently parsed cards. */
    private array $generatedCounts = [];
    private string $renderDebug = '';

    public function __construct() {
        parent::__construct();
        $this->addDescription( 'Parse synthetic item cards against an in-memory Itembox revision; no page writes.' );
        $this->addOption( 'source', 'Reviewed Itembox wikitext; defaults to ops/itembox.wiki.', false, true );
    }

    private function check( bool $condition, string $description ): void {
        if ( !$condition ) {
            throw new RuntimeException( $description . $this->renderDebug );
        }
        $this->checks++;
    }

    private function item( string $stats, string $name = 'Synthetic regression weapon' ): string {
        return "{{Itembox\n|itemname=$name\n|lucy_img_ID=0\n|statsblock=\n$stats\n}}";
    }

    private function stats( string $skill = '1H Blunt', string $damage = '40', string $delay = '50', string $slot = 'PRIMARY' ): string {
        return "Slot: $slot<br>\nSkill: $skill  Atk Delay: $delay<br>\nDMG: $damage<br>\nClass: ALL<br>\nRace: ALL<br>";
    }

    /** @return string[] The rendered text of each item card, in document order. */
    private function render( string $wikitext ): array {
        $parser = $this->getServiceContainer()->getParserFactory()->create();
        $output = $parser->parse(
            $wikitext,
            Title::newFromText( 'EQL synthetic Itembox regression' ),
            ParserOptions::newFromAnon()
        );
        $html = $output->getRawText();
        $debug = [];
        foreach ( [ 'slots', 'skill', 'hand', 'damage_label', 'damage_prefix', 'damage_line', 'damage', 'delay', 'override', 'value' ] as $field ) {
            $debug[$field] = ExtVariables::get( $parser )->getVarValue( 'itembox_db_' . $field );
        }
        $this->renderDebug = "\nFinal synthetic-card parser values: " . json_encode( $debug );
        $this->check(
            !preg_match( '/class=["\'][^"\']*\b(?:error|scribunto-error)\b/i', $html ),
            'Synthetic item produced a parser error: ' . strip_tags( $html )
        );
        $document = new DOMDocument();
        $previousErrors = libxml_use_internal_errors( true );
        $document->loadHTML( '<?xml encoding="UTF-8">' . $html );
        libxml_clear_errors();
        libxml_use_internal_errors( $previousErrors );
        $xpath = new DOMXPath( $document );
        $cards = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " itemdata ")]' );
        $text = [];
        $this->generatedCounts = [];
        foreach ( $cards as $card ) {
            foreach ( $xpath->query( './/br', $card ) as $lineBreak ) {
                $lineBreak->parentNode->replaceChild( $document->createTextNode( ' ' ), $lineBreak );
            }
            $text[] = preg_replace( '/\s+/u', ' ', trim( $card->textContent ) );
            $this->generatedCounts[] = $xpath->query(
                './/*[contains(concat(" ", normalize-space(@class), " "), " eql-generated-damage-bonus ")]',
                $card
            )->length;
        }
        $this->check( count( $text ) > 0, 'Parser did not render an item stat block.' );
        return $text;
    }

    private function bonusCount( string $text ): int {
        return preg_match_all( '/\b(?:DMG|Damage)\s*Bon(?:us)?\s*:/i', $text );
    }

    private function checkBonus( string $stats, int $expected, string $description ): void {
        $card = $this->render( $this->item( $stats ) )[0];
        $this->check( $this->bonusCount( $card ) === 1, "$description must render one damage bonus." );
        $this->check( $this->generatedCounts[0] === 1, "$description must mark its generated value." );
        $this->check(
            preg_match( '/\bDMG Bonus:\s*' . $expected . '\s*@\s*lvl\s*50\b/i', $card ) === 1,
            "$description must display damage bonus $expected at character level 50. Got: $card"
        );
    }

    private function checkNoBonus( string $stats, string $description ): void {
        $card = $this->render( $this->item( $stats ) )[0];
        $this->check( $this->bonusCount( $card ) === 0, "$description must not generate a damage bonus. Got: $card" );
        $this->check( $this->generatedCounts[0] === 0, "$description must not render a generated marker." );
    }

    public function execute() {
        $sourcePath = $this->getOption( 'source', __DIR__ . '/itembox.wiki' );
        $source = file_get_contents( $sourcePath );
        if ( $source === false || !str_contains( $source, 'itemdata' ) ) {
            $this->fatalError( 'Provide a reviewed Template:Itembox source containing itemdata.' );
        }

        $this->getServiceContainer()->getHookContainer()->register(
            'BeforeParserFetchTemplateRevisionRecord',
            static function ( ?LinkTarget $contextTitle, LinkTarget $title, bool &$skip, ?RevisionRecord &$revision ) use ( $source ) {
                if ( $title->getPrefixedText() === 'Template:Itembox' ) {
                    $revision = new MutableRevisionRecord( Title::castFromLinkTarget( $title ) );
                    $revision->setContent( SlotRecord::MAIN, new WikitextContent( $source ) );
                }
            }
        );

        $this->checkBonus( $this->stats( '2H Blunt', '45', '50' ), 34, 'Two-handed default' );
        $this->checkBonus( $this->stats(), 25, 'One-handed default' );
        $this->checkBonus( $this->stats( '2H Slashing', '45', '52' ), 34, 'Delay contribution cap' );
        $this->checkBonus( $this->stats( '2H Piercing', '60', '40' ), 33, 'Damage greater than character level' );
        $this->checkBonus( $this->stats( '1H Slashing', '10', '24', 'Primary / Secondary' ), 12, 'Mixed-case primary slot' );
        $this->checkBonus( $this->stats( 'Hand to Hand', '10', '25' ), 12, 'Hand-to-hand weapon' );
        $this->checkBonus( $this->stats( '1H Blunt', '13', '26', 'RANGE PRIMARY' ), 13, 'Primary-capable ranged-slot melee weapon' );
        $this->checkBonus( $this->stats( '1H Blunt', '10', '27' ), 13, 'Fractional result floors to an integer' );
        $this->checkBonus( $this->stats( '1H Blunt', '10', '1' ), 0, 'Generated zero remains visible' );
        $this->checkBonus( str_replace( '<br>', '<BR />', $this->stats() ), 25, 'Uppercase self-closing line breaks' );
        $this->checkBonus( str_replace( 'DMG: 40', 'DAMAGE: 40', $this->stats() ), 25, 'Full damage field label' );
        $this->checkBonus( str_replace( ': ', ":\t  ", $this->stats() ), 25, 'Tabs and multiple spaces after field labels' );
        $inlineStats = str_replace( 'DMG: 40<br>', 'DMG: 40  AC: 3<br>', $this->stats() );
        $this->checkBonus( $inlineStats, 25, 'Base damage followed by an inline stat' );
        $this->check(
            str_contains( $this->render( $this->item( $inlineStats ) )[0], 'AC: 3' ),
            'Generated bonus must preserve inline stats after base damage.'
        );

        foreach ( [ 'DMG Bonus: 0', 'Dmg Bon: 99', 'Damage Bonus: 7', 'damage bon: 5' ] as $override ) {
            $stats = str_replace( 'DMG: 40<br>', "DMG: 40  $override  AC: 3<br>", $this->stats() );
            $card = $this->render( $this->item( $stats ) )[0];
            $this->check( str_contains( $card, $override ), "Explicit $override must remain unchanged." );
            $this->check( $this->bonusCount( $card ) === 1, "Explicit $override must suppress the generated bonus." );
            $this->check( $this->generatedCounts[0] === 0, "Explicit $override must not receive a generated marker." );
            $this->check( str_contains( $card, 'AC: 3' ), 'An inline override must preserve following stats.' );
        }
        foreach ( [
            $this->stats() . 'Dmg Bon: 0<br>',
            str_replace( 'Atk Delay: 50', 'Atk Delay: 50  DMG Bonus: 99', $this->stats() ),
        ] as $stats ) {
            $card = $this->render( $this->item( $stats ) )[0];
            $this->check( $this->bonusCount( $card ) === 1, 'Separate-line and delay-line overrides must not be duplicated.' );
            $this->check( $this->generatedCounts[0] === 0, 'Separate-line and delay-line overrides must suppress the generated marker.' );
            $this->check(
                preg_match( '/(?:Dmg Bon:\s*0|DMG Bonus:\s*99)\b/', $card ) === 1,
                'Separate-line and delay-line overrides must preserve their explicit values.'
            );
        }

        $rogueStats = str_replace( 'Class: ALL', 'Class: ROG', $this->stats( 'Piercing', '12', '24' ) );
        $card = $this->render( $this->item( $rogueStats ) )[0];
        $this->check( preg_match( '/BACKSTAB:\s*12\b/', $card ) === 1, 'Existing default Backstab must remain.' );
        $this->check( $this->bonusCount( $card ) === 1, 'Default Backstab and generated bonus must coexist.' );
        $card = $this->render( $this->item( $rogueStats . 'BACKSTAB: 7<br>' ) )[0];
        $this->check( preg_match_all( '/BACKSTAB:/', $card ) === 1, 'Backstab override must not be duplicated.' );
        $this->check( preg_match( '/BACKSTAB:\s*7\b/', $card ) === 1, 'Existing explicit Backstab must remain.' );

        foreach ( [ 'Archery', 'Throwing' ] as $skill ) {
            $this->checkNoBonus( $this->stats( $skill ), "$skill weapon" );
        }
        foreach ( [ 'RANGE', 'AMMO', 'SECONDARY' ] as $slot ) {
            $this->checkNoBonus( $this->stats( '1H Blunt', '40', '50', $slot ), "$slot-only item" );
        }
        foreach ( [ '', 'unknown', '-1', '0' ] as $damage ) {
            $this->checkNoBonus( $this->stats( '1H Blunt', $damage ), "Invalid weapon damage '$damage'" );
        }
        foreach ( [ '', 'unknown', '-1', '0' ] as $delay ) {
            $this->checkNoBonus( $this->stats( '1H Blunt', '40', $delay ), "Invalid weapon delay '$delay'" );
        }
        $this->checkNoBonus( str_replace( 'DMG: 40<br>', '', $this->stats() ), 'Missing damage field' );
        $this->checkNoBonus( str_replace( 'Atk Delay: 50', '', $this->stats() ), 'Missing delay field' );
        $this->checkNoBonus( str_replace( 'Skill: 1H Blunt', '', $this->stats() ), 'Missing melee skill' );
        $this->checkNoBonus( str_replace( 'DMG: 40', 'Cold DMG: 40', $this->stats() ), 'Elemental damage without base weapon damage' );

        $cards = $this->render(
            $this->item( $this->stats( '2H Blunt', '45' ), 'Synthetic two-hander' ) . "\n" .
            $this->item( 'Slot: AMMO<br>DMG: 1<br>Class: ALL<br>', 'Synthetic ammunition' ) . "\n" .
            $this->item( $this->stats( '1H Blunt', '40' ) . 'Dmg Bon: 0<br>', 'Synthetic explicit zero' ) . "\n" .
            $this->item( $this->stats(), 'Synthetic one-hander' )
        );
        $this->check( count( $cards ) === 4, 'Multiple item cards must render independently.' );
        $this->check( preg_match( '/DMG Bonus:\s*34\b/', $cards[0] ) === 1, 'First card must use its own two-handed stats.' );
        $this->check( $this->bonusCount( $cards[1] ) === 0, 'Ammunition must not inherit prior weapon values.' );
        $this->check( str_contains( $cards[2], 'Dmg Bon: 0' ) && $this->bonusCount( $cards[2] ) === 1, 'Explicit-zero card must remain authoritative.' );
        $this->check( preg_match( '/DMG Bonus:\s*25\b/', $cards[3] ) === 1, 'Last card must not inherit prior override or handedness.' );
        $this->check( $this->generatedCounts === [ 1, 0, 0, 1 ], 'Generated markers must remain isolated to eligible cards.' );

        $this->output( "Passed {$this->checks} real-parser Itembox checks using an in-memory template revision. No wiki pages saved.\n" );
    }
}

return EQLTestItembox::class;
