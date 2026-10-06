#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PACKAGE_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
WEBROOT="${EQL_WEBROOT:-$HOME/public_html}"
LOCALSETTINGS="$WEBROOT/LocalSettings.php"
SKINROOT="$WEBROOT/skins/EQLImmersive"
EXTROOT="$WEBROOT/extensions"
BACKUP_ROOT="${EQL_BACKUP_ROOT:-$HOME/eqlclientdata-stage-backups}"

fail() {
	printf '\nERROR: %s\n' "$1" >&2
	exit 1
}

note() {
	printf '\n%s\n' "$1"
}

require_command() {
	command -v "$1" >/dev/null 2>&1 || fail "Required command is unavailable: $1"
}

require_file() {
	[ -f "$1" ] || fail "Required file not found: $1"
}

require_dir() {
	[ -d "$1" ] || fail "Required directory not found: $1"
}

require_base_layout() {
	require_command bash
	require_command php
	require_command curl
	require_file "$LOCALSETTINGS"
	require_dir "$EXTROOT"
	require_dir "$SKINROOT"
}

start_backup() {
	local stage="$1"
	local stamp
	stamp="$(date +%Y%m%d-%H%M%S)"
	BACKUP_DIR="$BACKUP_ROOT/$stage-$stamp"
	mkdir -p "$BACKUP_DIR"
	chmod 700 "$BACKUP_ROOT" "$BACKUP_DIR" 2>/dev/null || true
	printf '%s\n' "$stage" > "$BACKUP_DIR/.stage"
	export BACKUP_DIR
	printf 'Rollback copy: %s\n' "$BACKUP_DIR"
}

backup_file() {
	local source="$1"
	local relative="$2"
	if [ ! -e "$source" ]; then
		return 0
	fi
	mkdir -p "$BACKUP_DIR/$(dirname "$relative")"
	cp -a "$source" "$BACKUP_DIR/$relative"
}

backup_dir() {
	local source="$1"
	local relative="$2"
	if [ ! -e "$source" ]; then
		return 0
	fi
	mkdir -p "$BACKUP_DIR/$(dirname "$relative")"
	cp -a "$source" "$BACKUP_DIR/$relative"
}

mark_absent() {
	local relative="$1"
	mkdir -p "$BACKUP_DIR/.absent/$(dirname "$relative")"
	touch "$BACKUP_DIR/.absent/$relative"
}

atomic_copy_file() {
	local source="$1"
	local destination="$2"
	local temp
	require_file "$source"
	mkdir -p "$(dirname "$destination")"
	temp="$(dirname "$destination")/.${destination##*/}.next.$$"
	cp -p "$source" "$temp"
	mv -f "$temp" "$destination"
}

atomic_replace_dir() {
	local source="$1"
	local destination="$2"
	local next old
	require_dir "$source"
	next="$(dirname "$destination")/.${destination##*/}.next.$$"
	old="$(dirname "$destination")/.${destination##*/}.old.$$"
	rm -rf "$next" "$old"
	cp -a "$source" "$next"
	if [ -e "$destination" ]; then
		mv "$destination" "$old"
	fi
	mv "$next" "$destination"
	rm -rf "$old"
}

validate_json() {
	php -r '
		json_decode(
			file_get_contents( $argv[1] ),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		echo $argv[1] . " OK\n";
	' "$1"
}

optional_node_check() {
	if command -v node >/dev/null 2>&1; then
		node --check "$1"
	else
		printf 'Node is unavailable; skipped optional JavaScript syntax check for %s\n' "$1"
	fi
}

curl_status() {
	local url="$1"
	curl -fsS -o /dev/null -w '%{http_code}' "$url" || printf 'ERROR'
}

latest_backup() {
	local stage="$1"
	[ -d "$BACKUP_ROOT" ] || return 0
	find "$BACKUP_ROOT" -maxdepth 1 -mindepth 1 -type d -name "$stage-*" -printf '%f\n' 2>/dev/null \
		| sort -r \
		| head -n 1 \
		| sed "s#^#$BACKUP_ROOT/#" || true
}

oldest_backup() {
	local stage="$1"
	[ -d "$BACKUP_ROOT" ] || return 0
	find "$BACKUP_ROOT" -maxdepth 1 -mindepth 1 -type d -name "$stage-*" -printf '%f\n' 2>/dev/null \
		| sort \
		| head -n 1 \
		| sed "s#^#$BACKUP_ROOT/#" || true
}

restore_file_from_backup() {
	local backup="$1"
	local relative="$2"
	local destination="$3"
	require_file "$backup/$relative"
	atomic_copy_file "$backup/$relative" "$destination"
}

stage01_active() {
	grep -Fq "wfLoadExtension( 'EQLClientData' );" "$LOCALSETTINGS"
}

stage02_active() {
	grep -Fq "\$wgEQLClientDataEnableVerification = true;" "$LOCALSETTINGS"
}

stage03_active() {
	grep -Fq "\$wgEQLClientDataEnableSpellOverrides = true;" "$LOCALSETTINGS"
}

stage04_active() {
	grep -Fq "\$wgEQLClientDataVerificationModuleName = 'skins.EQLImmersive.verification';" "$LOCALSETTINGS"
}


restart_litespeed_php() {
	note "Restarting this account's LiteSpeed PHP workers so configuration changes are live..."
	touch "$HOME/.lsphp_restart.txt"
	sleep 1
	curl -fsS -o /dev/null 'https://eqlwiki.com/Main_Page' || true
	sleep 1
}

purge_main_page() {
	curl -fsS -X POST 'https://eqlwiki.com/api.php' \
		--data-urlencode 'action=purge' \
		--data-urlencode 'titles=Main_Page' \
		--data-urlencode 'format=json' \
		>/dev/null || true
}

verify_live_basics() {
	printf 'Main Page HTTP status: %s\n' "$(curl_status 'https://eqlwiki.com/Main_Page')"
	printf 'Special:Version HTTP status: %s\n' "$(curl_status 'https://eqlwiki.com/Special:Version')"
}
