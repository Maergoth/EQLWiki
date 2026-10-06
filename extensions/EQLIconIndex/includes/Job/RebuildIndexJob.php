<?php

namespace MediaWiki\Extension\EQLIconIndex\Job;

use MediaWiki\Extension\EQLIconIndex\IndexBuilder;
use MediaWiki\JobQueue\Job;

class RebuildIndexJob extends Job {

	public function __construct( array $params = [] ) {
		parent::__construct( 'eqlIconIndexRebuild', $params );
	}

	public function run(): bool {
		try {
			$builder = new IndexBuilder();
			$mode = $this->params['mode'] ?? 'full';

			if ( $mode === 'file' && !empty( $this->params['fileTitle'] ) ) {
				$builder->updateFile( (string)$this->params['fileTitle'] );
			} else {
				$builder->rebuild();
			}

			return true;
		} catch ( \Throwable $e ) {
			wfDebugLog(
				'EQLIconIndex',
				'Index job failed: ' . $e->getMessage()
			);
			return false;
		}
	}

	public function ignoreDuplicates() {
		return true;
	}

	public function getDeduplicationInfo() {
		return [
			'mode' => $this->params['mode'] ?? 'full',
			'fileTitle' => $this->params['fileTitle'] ?? '',
		];
	}
}
