<?php

use MediaWiki\Extension\EQLIconIndex\IndexBuilder;
use MediaWiki\Maintenance\Maintenance;

class RebuildIconIndex extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription(
			'Rebuild or inspect the EQL Icon Finder server-side fingerprint cache.'
		);
		$this->addOption(
			'status',
			'Only print current cache status; do not rebuild.'
		);
		$this->addOption(
			'file',
			'Update one listed File: title instead of scanning Icon List.',
			false,
			true
		);
	}

	public function execute() {
		$builder = new IndexBuilder();

		if ( $this->hasOption( 'status' ) ) {
			$this->output(
				json_encode(
					$builder->getStatus(),
					JSON_PRETTY_PRINT |
					JSON_UNESCAPED_SLASHES
				) . "\n"
			);
			return;
		}

		if ( $this->hasOption( 'file' ) ) {
			$this->output(
				"Updating one icon from server cache...\n"
			);
			$index = $builder->updateFile(
				(string)$this->getOption( 'file' )
			);
		} else {
			$this->output(
				"Building incremental server icon index...\n"
			);
			$index = $builder->rebuild();
		}

		$this->output(
			"Ready: yes\n" .
			"Algorithm: " . $index['algorithm'] . "\n" .
			"Icon List revision: " . $index['iconListRevision'] . "\n" .
			"Listed files: " . $index['listedCount'] . "\n" .
			"Indexed files: " . $index['count'] . "\n" .
			"Generation: " . $index['generation'] . "\n"
		);
	}
}

$maintClass = RebuildIconIndex::class;
require_once RUN_MAINTENANCE_IF_MAIN;
