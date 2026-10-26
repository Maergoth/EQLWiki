<?php
/** Unsaved template/parser integration. No page, account, purge or upload writes. */
use MediaWiki\Content\WikitextContent;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Revision\MutableRevisionRecord;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;

class EQLTestCharacterSheet extends Maintenance {
    private int $checks = 0;
    public function __construct() {
        parent::__construct();
        $this->addDescription( 'Parse both reviewed Character sheet templates in memory; never save pages.' );
        $this->addOption( 'output', 'Optional private parsed synthetic HTML file for visual/DOM checks.', false, true );
        $this->addOption( 'input', 'Optional private synthetic serializer JSON with source and two expected sheets.', false, true );
    }
    private function check( bool $condition, string $message ): void {
        if ( !$condition ) {
            throw new RuntimeException( $message );
        }
        $this->checks++;
    }
    private function checkRoundtrip( array $input, DOMXPath $xpath, DOMNodeList $sheets, string $html ): void {
        $class = static fn ( string $name ): string => 'contains(concat(" ",normalize-space(@class)," ")," ' . $name . ' ")';
        $linkTitle = static function ( DOMElement $link ): string {
            $href = $link->getAttribute( 'href' );$query = [];
            parse_str( parse_url( $href, PHP_URL_QUERY ) ?? '', $query );
            return str_replace( '_', ' ', $query['title'] ?? rawurldecode( preg_replace( '~^/wiki/~', '', parse_url( $href, PHP_URL_PATH ) ?? '' ) ) );
        };
        $this->check( count( $input['sheets'] ) === 2, 'Roundtrip fixture must supply two expected sheets.' );
        foreach ( $input['sheets'] as $index => $expected ) {
            $sheet = $sheets[$index];$meta = $expected['metadata'];
            $heading = $xpath->query( './*[' . $class( 'eql-character-heading' ) . ']/strong', $sheet )[0];
            $this->check( $heading->textContent === $meta['name'] . ' (' . $meta['server'] . ')', 'Escaped apostrophe/ampersand/Unicode identity must roundtrip exactly within its server.' );
            $cards = $xpath->query( './/*[' . $class( 'eql-character-item' ) . ']', $sheet );
            $this->check( $cards->length === count( $expected['equipment'] ), 'Every supplied known equipment slot must produce one scoped item card.' );
            foreach ( $expected['equipment'] as $item ) {
                $matches = [];
                foreach ( $cards as $card ) {
                    $anchor = $xpath->query( './*[' . $class( 'eql-character-item-name' ) . ']/a', $card )[0];
                    if ( $anchor && $linkTitle( $anchor ) === $item['title'] ) { $matches[] = $card; }
                }
                $this->check( count( $matches ) === 1, 'Canonical item target must decode exactly once: ' . $item['slot'] );
                $card = $matches[0];$inspect = $xpath->query( './*[' . $class( 'eql-character-inspect-v1' ) . ']', $card )[0];
                $this->check( trim( $xpath->query( './*[' . $class( 'eql-character-tier' ) . ']', $card )[0]->textContent ) === '+' . $item['tier'], 'Tier metadata must stay with its physical equipment card.' );
                $stats = $xpath->query( './*[' . $class( 'eql-character-item-stats' ) . ']', $inspect )[0]->textContent;
                $this->check( str_contains( $stats, 'HP: ' . $item['stats']['HP'] ) && str_contains( $stats, 'STR: ' . $item['stats']['STR'] ), 'Actual serialized stats must stay within the correct item/server card.' );
                foreach ( $item['details'] ?? [] as $detail ) {
                    $detailNode=$xpath->query( './*[' . $class( 'eql-character-item-details' ) . ']', $inspect )[0];
                    $this->check( $detailNode && str_contains( $detailNode->textContent, $detail['label'] . ': ' . $detail['value'] ), 'Actual rich metadata stays in the scoped item inspect.' );
                    if ( !empty( $detail['linkTitle'] ) ) { $targets=[];foreach ( $xpath->query( './/a', $detailNode ) as $link ) { $targets[]=$linkTitle( $link ); }$this->check( in_array( str_replace( '_', ' ', $detail['linkTitle'] ), $targets, true ), 'Effect links decode to the canonical page title.' ); }
                }
                foreach ( $item['augments'] as $augment ) {
                    $links = $xpath->query( './/*[' . $class( 'eql-character-augments' ) . ']//a', $inspect );
                    $targets = [];foreach ( $links as $link ) { $targets[] = $linkTitle( $link ); }
                    $this->check( in_array( $augment['title'], $targets, true ), 'A canonical weapon augment in boots must preserve its exact apostrophe/ampersand/Unicode destination.' );
                    foreach ( $augment['details'] ?? [] as $detail ) { $this->check( str_contains( $inspect->textContent, $detail['label'] . ': ' . $detail['value'] ), 'Indexed Exaltation metadata must survive actual serialization and parsing.' ); }
                }
                for ( $socket = 0; $socket < 8; $socket++ ) {
                    $this->check( !str_contains( $inspect->textContent, 'Socket ' . ( $socket + 1 ) . ': Empty' ), 'Unoccupied socket indexes must not clutter the public inspect.' );
                }
                $this->check( $xpath->query( './/*[' . $class( 'eql-character-augments' ) . ']//*[' . $class( 'eql-character-tier' ) . ' or ' . $class( 'eql-character-augment-stats' ) . ' or ' . $class( 'eql-character-augment-icon' ) . ']', $inspect )->length === 0, 'Socket presentation omits tier, numeric stats and duplicate icons while retaining parent gear.' );
            }
            $this->check( $xpath->query( './/*[' . $class( 'eql-character-slot-label' ) . ' and text()="Any 1"]', $sheet )->length === 1 && $xpath->query( './/*[' . $class( 'eql-character-slot-label' ) . ' and text()="Any 2"]', $sheet )->length === 1, 'Both Any slots must render once.' );
            $this->check( $xpath->query( './/*[' . $class( 'eql-character-slot-label' ) . ' and text()="Power"]', $sheet )->length === 0, 'Public sheet must omit Power.' );
            foreach ( [ 'HP' => 'HP', 'MP' => 'Mana', 'END' => 'Endurance' ] as $key => $label ) {
                $value = $expected['totals'][$key]['value'];
                $this->check( $xpath->query( './/*[' . $class( 'eql-character-totals' ) . ']//dt[text()="' . $label . '"]/following-sibling::dd', $sheet )[0]->textContent === (string)$value, 'Actual exporter must show one selected maximum resource, excluding Current duplicates.' );
            }
            $valuesByLabel = [];
            foreach ( $xpath->query( './/*[' . $class( 'eql-character-totals' ) . ']//dt', $sheet ) as $label ) {
                $key = str_replace( "\u{00a0}", ' ', $label->textContent );
                $valuesByLabel[$key][] = $xpath->query( './dd', $label->parentNode )[0]->textContent;
            }
            foreach ( [ 'ATK_ACCURACY' => 'Accuracy', 'ACCURACY' => 'Accuracy bonus', 'AVOIDANCE' => 'Avoidance', 'COMBAT_EFFECTS' => 'Combat effects', 'STRIKETHROUGH' => 'Strikethrough', 'STUN_RESIST' => 'Stun resist', 'DAMAGE_SHIELD' => 'Damage shield', 'DAMAGE_SHIELD_MITIGATION' => 'Damage shield mitigation', 'DOT_SHIELDING' => 'DoT shielding', 'MELEE_SHIELDING' => 'Melee shielding', 'SPELL_SHIELDING' => 'Spell shielding', 'ATTACK_SPEED' => 'Attack speed %' ] as $key => $label ) {
                if ( !array_key_exists( $key, $expected['totals'] ) ) { continue; }
                $record = $expected['totals'][$key];
                $value = $record['value'] === null ? '?' : (string)$record['value'] . ( $record['complete'] ? '' : ' *' );
                $this->check( ( $valuesByLabel[$label] ?? [] ) === [ $value ], 'Actual serializer modifier must retain its observed value within the correct server: ' . $key );
            }
            $caption = $xpath->query( './/*[' . $class( 'eql-character-caption' ) . ']', $sheet )[0];
            foreach ( explode( "\n", $expected['caption'] ) as $line ) { $this->check( str_contains( $caption->textContent, $line ), 'Caption text must roundtrip literally without template, signature or HTML interpretation.' ); }
            $this->check( $xpath->query( './/a|.//script|.//img', $caption )->length === 0 && $xpath->query( './br', $caption )->length === 1, 'Caption permits only the serializer-generated line break, not user HTML or wiki links.' );
        }
        $this->check( !preg_match( '/<script\b|data:image|123456|654321|Augments unknown|partial item stats/i', $html ), 'Public parser result must omit current-resource sentinels, pixels, scripts and coverage warnings.' );
    }
    public function execute() {
        $sources = [
            'Template:Character sheet' => file_get_contents( __DIR__ . '/character-sheet.wiki' ),
            'Template:Character sheet item' => file_get_contents( __DIR__ . '/character-sheet-item.wiki' )
        ];
        $this->getServiceContainer()->getHookContainer()->register(
            'BeforeParserFetchTemplateRevisionRecord',
            static function ( ?LinkTarget $context, LinkTarget $title, bool &$skip, ?RevisionRecord &$revision ) use ( $sources ) {
                $key = str_replace( '_', ' ', $title->getPrefixedText() );
                if ( isset( $sources[$key] ) ) {
                    $revision = new MutableRevisionRecord( Title::castFromLinkTarget( $title ) );
                    $revision->setContent( SlotRecord::MAIN, new WikitextContent( $sources[$key] ) );
                }
            }
        );
        $source = '{{Character_sheet|name=Synthetic &amp; Reader|server=Fixture One|level=60|classes=WAR / CLR|aa=123'
            . '|total_hp=1234|total_mp=?|total_end=456 *|total_ac_mitigation=71|total_ac_mitigation_softcap=43'
            . '|total_ac_evasion=127|total_atk_offense=46|total_atk_accuracy=107|total_str=99|total_haste=12'
            . '|total_attack_speed=23|total_accuracy=37|total_avoidance=24|total_combat_effects=0|total_strikethrough=7'
            . '|total_stun_resist=8|total_damage_shield=19|total_damage_shield_mitigation=?|total_dot_shielding=4.5|total_melee_shielding=11|total_spell_shielding=12'
            . '|feet_item=Synthetic Boots|feet_tier=3|feet_stats=HP: 133<br>STR: 7'
            . '|feet_augment_slots=2|feet_augment_1_item=Synthetic Sword|feet_augment_1_tier=2|feet_augment_1_stats=HP: 5'
            . '|feet_augment_1_details=Flags: LORE<br>Effect: [[See Invisible]] (Worn)<br>Class: ALL<br>Size: SMALL'
            . '}}'
            . "\n{{Character_sheet|name=Synthetic &amp; Reader|server=Fixture Two|level=42|total_hp=800|total_ac=55|total_haste=0|head_item=Synthetic Cap|head_tier=0|head_stats=HP: ?}}";
        $input = null;
        if ( $this->hasOption( 'input' ) ) {
            $json = file_get_contents( $this->getOption( 'input' ) );
            $this->check( $json !== false && strlen( $json ) <= 300000, 'Synthetic roundtrip JSON must be readable and bounded.' );
            $input = json_decode( $json, true, 32, JSON_THROW_ON_ERROR );
            $source = $input['source'];
            $transformed = $this->getServiceContainer()->getParserFactory()->create()->preSaveTransform( $source, Title::newFromText( 'User_talk:EQL synthetic character fixture' ), $this->getServiceContainer()->getUserFactory()->newAnonymous( '127.0.0.1' ), ParserOptions::newFromAnon() );
            $this->check( $transformed === $source, 'Real pre-save transform must not interpret escaped caption templates/signatures.' );
        }
        $ordinary = $this->getServiceContainer()->getParserFactory()->create()->parse(
            '<nowiki><div class="eql-character-sheet-v1">quoted documentation</div></nowiki>',
            Title::newFromText( 'EQL synthetic ordinary page' ), ParserOptions::newFromAnon()
        );
        $this->check( !in_array( 'skins.EQLImmersive.characterSheet', $ordinary->getModules(), true ), 'Quoted markup must not load the page-specific component.' );
        $parser = $this->getServiceContainer()->getParserFactory()->create();
        $output = $parser->parse( $source, Title::newFromText( 'User_talk:EQL synthetic character fixture' ), ParserOptions::newFromAnon() );
        $html = $output->getRawText();
        $this->check( !preg_match( '/class="[^"]*(?:error|scribunto-error)/i', $html ), 'Template parsing must not produce an error.' );
        $this->check( !str_contains( $html, '{{{' ) && !str_contains( $html, '{{Character' ), 'All sheet/helper parameters must expand.' );
        $this->check( in_array( 'skins.EQLImmersive.characterSheet', $output->getModules(), true ), 'Unsaved parse must advertise the registered JS module.' );
        $this->check( in_array( 'skins.EQLImmersive.characterSheet', $output->getModuleStyles(), true ), 'Unsaved parse must advertise the style module.' );
        $this->check( $this->getServiceContainer()->getResourceLoader()->isModuleRegistered( 'skins.EQLImmersive.characterSheet' ), 'Component module must actually be registered.' );
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors( true );
        $document->loadHTML( '<?xml encoding="UTF-8">' . $html );
        libxml_clear_errors();libxml_use_internal_errors( $previous );
        $xpath = new DOMXPath( $document );
        $class = static fn ( string $name ): string => 'contains(concat(" ",normalize-space(@class)," ")," ' . $name . ' ")';
        $sheets = $xpath->query( '//*[' . $class( 'eql-character-sheet-v1' ) . ']' );
        $this->check( $sheets->length === 2, 'Two same-name characters on different servers must remain independent sheets.' );
        $this->check( $xpath->query( '//*[' . $class( 'eql-character-slot' ) . ']' )->length === 48, 'Each character must contain exactly 24 slots.' );
        $this->check( $xpath->query( '//*[' . $class( 'eql-character-equipment-row' ) . ']/p|//*[' . $class( 'eql-character-equipment-row' ) . ']/br' )->length === 0, 'Transclusion whitespace must not create paragraph/line-break flex children between slots.' );
        if ( $input !== null ) {
            $this->checkRoundtrip( $input, $xpath, $sheets, $html );
            if ( $this->hasOption( 'output' ) ) { $this->check( file_put_contents( $this->getOption( 'output' ), $html ) === strlen( $html ), 'Private roundtrip output could not be saved.' ); }
            $this->output( 'Character sheet actual serializer roundtrip: ' . $this->checks . " checks passed; no pages saved.\n" );return;
        }
        $this->check( $xpath->query( '//*[' . $class( 'eql-character-inspect-v1' ) . ']' )->length === 2, 'Only populated gear has a captured-stat inspect.' );
        $this->check( str_contains( $sheets[0]->textContent, 'Fixture One' ) && str_contains( $sheets[1]->textContent, 'Fixture Two' ), 'Identity text must stay within the correct sheet.' );
        $this->check( $xpath->query( './/h2|.//h3|.//h4', $sheets[0] )->length === 0, 'Inspect groups must not add duplicate article TOC headings.' );
        $this->check( $xpath->query( './/dt[text()="AC" or text()="Attack"]', $sheets[0] )->length === 0, 'Observed AC/ATK components must suppress their generic duplicates.' );
        $this->check( str_contains( $sheets[1]->textContent, '+0' ), 'An explicitly known tier zero remains visible.' );
        $totals = $xpath->query( './/*[' . $class( 'eql-character-totals' ) . ']', $sheets[0] )[0]->textContent;
        foreach ( [ '1234', '456 *', 'Mitigation AC', 'AC softcap', 'Evasion AC', 'Offense', 'Accuracy' ] as $expected ) {
            $this->check( str_contains( $totals, $expected ), 'Totals must preserve distinct values and uncertainty: ' . $expected );
        }
        $valuesByLabel = [];
        foreach ( $xpath->query( './/*[' . $class( 'eql-character-totals' ) . ']//dt', $sheets[0] ) as $label ) {
            $key = str_replace( "\u{00a0}", ' ', $label->textContent );
            $valuesByLabel[$key][] = $xpath->query( './dd', $label->parentNode )[0]->textContent;
        }
        foreach ( [ 'Accuracy' => '107', 'Accuracy bonus' => '37', 'Avoidance' => '24', 'Combat effects' => '0', 'Strikethrough' => '7', 'Stun resist' => '8', 'Damage shield' => '19', 'Damage shield mitigation' => '?', 'DoT shielding' => '4.5', 'Melee shielding' => '11', 'Spell shielding' => '12', 'Attack speed %' => '23' ] as $label => $expected ) {
            $this->check( ( $valuesByLabel[$label] ?? [] ) === [ $expected ], 'Observed modifier must retain its own value, zero or unknown without calculation: ' . $label );
        }
        $this->check( !isset( $valuesByLabel['Haste %'] ), 'Observed Attack speed takes precedence over legacy Haste without showing a duplicate.' );
        $legacyHaste = $xpath->query( './/dt[starts-with(text(),"Haste")]/following-sibling::dd', $sheets[1] );
        $this->check( $legacyHaste->length === 1 && $legacyHaste[0]->textContent === '0', 'Legacy Haste remains available and preserves a known zero when Attack speed is omitted.' );
        $this->check( $xpath->query( './/*[' . $class( 'eql-character-group-heading' ) . ' and text()="Additional modifiers"]', $sheets[0] )->length === 1 && $xpath->query( './/*[' . $class( 'eql-character-group-heading' ) . ' and text()="Additional modifiers"]', $sheets[1] )->length === 0, 'Additional modifiers appear only when supplied; old sheets keep their existing layout.' );
        $this->check( !str_contains( $totals, 'Current' ) && !str_contains( $totals, 'Maximum' ) && !str_contains( $totals, 'Fraction' ), 'Public resources display only their selected maximum values.' );
        $this->check( !str_contains( $html, 'Socket 2: Empty' ) && !str_contains( $html, 'Socket 3: Empty' ), 'Empty socket details stay out of the public inspect.' );
        $this->check( $xpath->query( '//*[' . $class( 'eql-character-augments' ) . ']//*[' . $class( 'eql-character-tier' ) . ' or ' . $class( 'eql-character-augment-stats' ) . ' or ' . $class( 'eql-character-augment-icon' ) . ']' )->length === 0, 'Occupied Exaltations do not display their tier, numeric stats or duplicate icon.' );
        $this->check( $xpath->query( '//a[starts-with(@title,"Synthetic Sword")]' )->length > 0, 'A socket can link any known item, including a weapon in boots.' );
        $this->check( !preg_match( '/<script\b|data:image|Augments unknown|partial item stats/i', $html ), 'Sheet output must not include scripts, pixels or noisy coverage warnings.' );
        if ( $this->hasOption( 'output' ) ) {
            $this->check( file_put_contents( $this->getOption( 'output' ), $html ) === strlen( $html ), 'Private synthetic HTML output could not be saved.' );
        }
        $this->output( 'Character sheet unsaved MediaWiki parse: ' . $this->checks . " checks passed; no pages saved.\n" );
    }
}
return EQLTestCharacterSheet::class;
