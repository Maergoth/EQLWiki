#!/usr/bin/env python3
"""Exercise hover headers over real local HTTP, without MediaWiki settings/data."""
import argparse
import json
import os
from pathlib import Path
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request


ROUTER = r'''<?php
namespace MediaWiki\Request {
    // WebResponse's diagnostic hook needs no logger in this isolated fixture.
    class HeaderCallback { public static function warnIfHeadersSent(): void {} }
}
namespace MediaWiki\Title {
    class Title {
        public function __construct(public string $text) {}
        public static function newFromText(string $text): ?self {
            return str_contains($text, '<') ? null : new self($text);
        }
        public function getDBkey(): string { return str_replace(' ', '_', $this->text); }
        public function getText(): string { return $this->text; }
        public function getNamespace(): int { return 0; }
        public function isExternal(): bool { return false; }
        public function getFragment(): string { return ''; }
    }
}
namespace MediaWiki\Parser {
    class ParserOptions { public static function newFromAnon(): self { return new self(); } }
}
namespace MediaWiki {
    class MediaWikiServices {
        public static function getInstance(): self { return new self(); }
        public function getWikiPageFactory(): self { return $this; }
        public function getParserFactory(): self { return $this; }
        public function create(): object { return new \FixtureParser(); }
        public function getDBLoadBalancer(): self { return $this; }
        public function getConnection($index): object { return new \FixtureDb(); }
        public function newFromTitle($title): object {
            $pages = [
                'Synthetic Blade' => '{{Itempage|itemname=Synthetic Blade|lucy_img_ID=1|statsblock=Slot: PRIMARY<br>DMG: 15}}',
                'Synthetic Spell' => '{{Spellpage|spellname=Synthetic Spell|mana=10}}',
                'Synthetic Mob' => '{{Namedmobpage|name=Synthetic Mob|level=10}}',
                'Synthetic Plain' => 'A synthetic page without a hover template.',
            ];
            return new class($pages[$title->text] ?? null) {
                public function __construct(private ?string $text) {}
                public function getContent(): ?object {
                    return $this->text === null ? null : new class($this->text) {
                        public function __construct(private string $text) {}
                        public function getText(): string { return $this->text; }
                    };
                }
            };
        }
    }
}
namespace {
    define('NS_MAIN', 0); define('NS_CATEGORY', 14); define('NS_SPECIAL', -1); define('DB_REPLICA', 1);
    $root = getenv('EQL_HOVER_TEST_ROOT');
    require $root . '/includes/Request/WebResponse.php';
    class FixtureDb {
        public static int $queries = 0;
        public function select(...$args): \ArrayIterator { self::$queries++; return new \ArrayIterator([]); }
        public function selectField(...$args): int { self::$queries++; return 0; }
        public function addQuotes($text): string { return "'" . str_replace("'", "''", $text) . "'"; }
    }
    class FixtureParser {
        public function transformMsg($text, $options): string { return $text; }
        public function parse($text, $title, $options): object {
            return new class($text) {
                public function __construct(private string $text) {}
                // A deterministic, visible parse result exercises emission even
                // when the synthetic category intersections return no rows.
                public function getContentHolderText(): string {
                    return '<div class="fixture-parser-output">' . $this->text . '</div>';
                }
            };
        }
    }
    class SpecialPage {
        private bool $included = false;
        public function __construct(string $name) {}
        public function including(?bool $value = null): bool { if ($value !== null) { $this->included = $value; } return $this->included; }
        protected function setHeaders(): void {}
        public function getOutput(): object { return $GLOBALS['wgOut']; }
        public function getRequest(): object {
            return new class {
                public function response(): object { return new \MediaWiki\Request\WebResponse(); }
                public function getText(string $name, string $default = ''): string {
                    return is_string($_GET[$name] ?? null) ? $_GET[$name] : $default;
                }
                public function getVal(string $name, $default = null) { return $_GET[$name] ?? $default; }
                public function getBool(string $name): bool { return !empty($_GET[$name]); }
                public function getInt(string $name, int $default): int { return (int)($_GET[$name] ?? $default); }
            };
        }
    }
    $wgOut = new class {
        public bool $disabled = false;
        public string $text = '';
        public function disable(): void { $this->disabled = true; }
        public function addHTML($text): void { $this->text .= $text; }
        public function addWikiTextAsContent($text): void { $this->text .= $text; }
        public function setPageTitle($text): void {}
        public function addModules($modules): void {}
        public function parseAsContent(string $text): string {
            if (str_contains($text, '{{Namedmobpage')) {
                return '<table class="mobStatsBox"><tr><td>Synthetic Mob</td></tr></table>';
            }
            return '<div class="fixture-card">' . htmlspecialchars($text, ENT_QUOTES) . '</div>';
        }
    };
    $wgTitle = \MediaWiki\Title\Title::newFromText('Synthetic Article');
    require $root . '/extensions/AjaxHoverHelper/AjaxHoverHelper_body.php';
    require $root . '/extensions/DynamicZoneList/DynamicZoneList_body.php';
    require $root . '/extensions/DynamicQuestItemList/DynamicQuestItemList_body.php';
    require $root . '/extensions/ClassSlotEquip/ClassSlotEquip_body.php';
    if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/article') {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><title>Synthetic article</title><p>Ordinary article control.</p>';
        return;
    }
    if (isset($_GET['staging'])) { header('X-Robots-Tag: noindex, nofollow, noarchive'); }
    $kind = $_GET['kind'] ?? 'hover';
    $class = ['hover' => 'AjaxHoverHelper', 'zone' => 'DynamicZoneList', 'quest' => 'DynamicQuestItemList', 'equipment' => 'ClassSlotEquip'][$kind];
    $handler = new $class();
    $handler->including(isset($_GET['included']));
    $handler->execute(is_string($_GET['page'] ?? null) ? $_GET['page'] : '');
    if ($kind === 'hover' && !$wgOut->disabled) { throw new \RuntimeException('Expected bare response with OutputPage disabled.'); }
    if ($kind === 'zone' && ($_GET['page'] ?? null) === 'Upper_Guk' && FixtureDb::$queries !== 3) {
        throw new \RuntimeException('The zone fixture must exercise all three category intersections.');
    }
    echo $wgOut->text;
}
'''

# Legacy handlers can enable displayed errors after headers have already sent
# HTTP 200. Such a body must never satisfy an otherwise permissive marker check.
PHP_DIAGNOSTICS = ('<b>Fatal error</b>', '<b>Parse error</b>', '<b>Warning</b>', '<b>Deprecated</b>')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--php', default=os.environ.get('PHP_BINARY', 'php'))
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    origin = f'http://127.0.0.1:{port}'
    checks = 0
    with tempfile.TemporaryDirectory(prefix='eql-hover-http-') as temporary:
        router = Path(temporary) / 'router.php'
        router.write_text(ROUTER, encoding='utf-8')
        env = dict(os.environ, EQL_HOVER_TEST_ROOT=str(root))
        with (Path(temporary) / 'server.log').open('w+', encoding='utf-8') as log:
            server = subprocess.Popen([args.php, '-d', 'display_errors=0', '-S', f'127.0.0.1:{port}', str(router)],
                                      env=env, stdout=log, stderr=log)
            try:
                deadline = time.monotonic() + 10
                while True:
                    try:
                        with urllib.request.urlopen(origin + '/article', timeout=1) as response:
                            assert response.status == 200
                        break
                    except (urllib.error.URLError, TimeoutError):
                        if server.poll() is not None or time.monotonic() >= deadline:
                            log.seek(0)
                            raise AssertionError('Synthetic PHP HTTP server did not start: ' + log.read())
                        time.sleep(0.05)
                cases = [
                    ('Synthetic Blade', 'item', 'Synthetic Blade'),
                    ('Synthetic Spell', 'spell', 'Synthetic Spell'),
                    ('Synthetic Mob', 'mob', 'mobStatsBox'),
                    ('', 'item', 'AjaxHoverHelper Error 1.'),
                    ('<invalid>', 'item', 'AjaxHoverHelper Error 1.'),
                    ('Missing Synthetic', 'item', 'AjaxHoverHelper Error 2.'),
                    ('Synthetic Plain', '', 'AjaxHoverHelper Error 2.'),
                ]
                for page, kind, expected in cases:
                    query = urllib.parse.urlencode({'page': page, 'type': kind})
                    with urllib.request.urlopen(origin + '/hover?' + query, timeout=3) as response:
                        body = response.read().decode()
                        assert response.status == 200 and expected in body and not any(marker in body for marker in PHP_DIAGNOSTICS), (page, body)
                        assert 'text/html' in response.headers.get('Content-Type', ''), page
                        assert response.headers.get_all('X-Robots-Tag') == ['noindex'], page
                        assert '<!DOCTYPE html>' not in body, page
                        checks += 4
                with urllib.request.urlopen(origin + '/hover?page=Synthetic+Blade&type=item&staging=1', timeout=3) as response:
                    policies = response.headers.get_all('X-Robots-Tag')
                    assert policies == ['noindex, nofollow, noarchive', 'noindex'], policies
                    checks += 1
                utilities = [
                    ({'kind': 'zone'}, 'Hello!', 'text/html'),
                    ({'kind': 'zone', 'page': 'Upper_Guk'}, 'fixture-parser-output', 'text/html'),
                    ({'kind': 'zone', 'page': 'Synthetic Missing'}, 'Invalid zone', 'text/html'),
                    ({'kind': 'quest'}, 'dqil-table', 'text/html'),
                    ({'kind': 'quest', 'dqil_action': 'chunk'}, '"done":true', 'application/json'),
                    ({'kind': 'equipment'}, "Error! Didn't recognize", 'text/html'),
                    ({'kind': 'equipment', 'page': 'Warrior/Primary'}, 'No items found.', 'text/html'),
                    ({'kind': 'equipment', 'eqlBuilder': '1'}, 'Please select', 'text/html'),
                    ({'kind': 'equipment', 'eqlBuilder': '1', 'classA': 'Warrior', 'slot': 'Primary'}, 'No items found.', 'text/html'),
                ]
                for params, expected, content_type in utilities:
                    with urllib.request.urlopen(origin + '/utility?' + urllib.parse.urlencode(params), timeout=3) as response:
                        body = response.read().decode()
                        assert response.status == 200 and expected in body and not any(marker in body for marker in PHP_DIAGNOSTICS), (params, body)
                        assert content_type in response.headers.get('Content-Type', ''), params
                        assert response.headers.get_all('X-Robots-Tag') == ['noindex'], params
                        checks += 3
                    if params['kind'] in ('zone', 'quest') and params.get('dqil_action') is None:
                        params = dict(params, included='1')
                        with urllib.request.urlopen(origin + '/article-inclusion?' + urllib.parse.urlencode(params), timeout=3) as response:
                            assert response.headers.get('X-Robots-Tag') is None, params
                            included_body = response.read().decode()
                            assert included_body == body, (params, {'standalone': body, 'included': included_body})
                            checks += 2
                # A subsequent ordinary response in the same worker must not inherit
                # fragment headers just because the extension has been loaded.
                with urllib.request.urlopen(origin + '/article', timeout=3) as response:
                    assert response.headers.get('X-Robots-Tag') is None
                    assert 'Ordinary article control.' in response.read().decode()
                    checks += 2
            finally:
                server.terminate()
                server.wait(timeout=5)
    print(json.dumps({'checks': checks, 'scope': 'synthetic local HTTP; actual hover handler and WebResponse; no wiki/database writes'}))


if __name__ == '__main__':
    main()
