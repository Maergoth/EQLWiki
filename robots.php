<?php
/** Lightweight robots.txt response; the web-server staging gate still applies. */
require_once __DIR__ . '/skins/EQLImmersive/includes/SeoConfig.php';

header( 'Content-Type: text/plain; charset=UTF-8' );
header( 'Cache-Control: public, max-age=3600' );
header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
echo EQLImmersive\SeoConfig::robotsText( $_SERVER['HTTP_HOST'] ?? '' );
