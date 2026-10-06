<?php

/**
 * Licensed under the ISC License. See the LICENSE file for details.
 */

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

// Due to creation of Parser::$mExtVariables property
$cfg['suppress_issue_types'][] = 'PhanUndeclaredProperty';

return $cfg;
