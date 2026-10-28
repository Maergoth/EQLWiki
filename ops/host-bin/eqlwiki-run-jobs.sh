#!/bin/bash
# Owner-installed private cron wrapper. Native queued work only; never a wiki rebuild.
set -u -o pipefail
umask 077
PHP_BIN="/usr/local/bin/php"
FLOCK_BIN="/bin/flock"
TIMEOUT_BIN="/bin/timeout"
NICE_BIN="/bin/nice"
IONICE_BIN="/usr/bin/ionice"
WIKI="$HOME/public_html"
STATE="$HOME/private-cache/mediawiki"
LOCK="$STATE/eql-jobrunner.lock"
STATUS="$STATE/eql-jobrunner-status.json"
HISTORY="$STATE/eql-jobrunner-history.jsonl"
SCRIPT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)/$(basename -- "${BASH_SOURCE[0]}")"

for binary in "$PHP_BIN" "$FLOCK_BIN" "$TIMEOUT_BIN" "$NICE_BIN"; do
    [[ -x "$binary" ]] || { printf 'Job runner dependency unavailable.\n' >&2; exit 69; }
done
[[ -d "$STATE" && ! -L "$STATE" && -f "$WIKI/maintenance/run.php" ]] || exit 72
[[ ! -L "$LOCK" && ( ! -e "$LOCK" || -f "$LOCK" ) ]] || exit 73

if [[ $# -eq 0 ]]; then
    exec 9>"$LOCK" || exit 73
    "$FLOCK_BIN" -n 9
    lock_exit=$?
    [[ "$lock_exit" -ne 1 ]] || exit 0 # Another invocation still owns the lock/status.
    [[ "$lock_exit" -eq 0 ]] || exit "$lock_exit"
    # The inherited lock covers the complete child process tree, including cleanup.
    exec "$TIMEOUT_BIN" --signal=TERM --kill-after=2s 48s "$BASH" "$SCRIPT" --bounded-child
fi
[[ $# -eq 1 && "$1" == --bounded-child ]] || exit 64
{ : >&9; } 2>/dev/null || exit 73
"$FLOCK_BIN" -n 9 || exit 73
cd "$WIKI" || exit 72

# Consume native output without persisting job titles, parameters or error text.
# Native runJobs can exit zero despite failed jobs or a memory/exception stop.
PHP_HELPER=$(cat <<'PHP'
function atomicWrite(string $path, string $data): void {
    if (is_link($path) || (file_exists($path) && !is_file($path))) throw new RuntimeException('unsafe state');
    $tmp = $path . '.' . getmypid() . '.tmp';
    $h = fopen($tmp, 'xb');
    if (!$h) throw new RuntimeException('state open');
    try {
        $offset = 0;
        while ($offset < strlen($data)) {
            $n = fwrite($h, substr($data, $offset));
            if (!$n) throw new RuntimeException('state write');
            $offset += $n;
        }
        if (!fflush($h) || (function_exists('fsync') && !fsync($h))) throw new RuntimeException('state sync');
    } catch (Throwable $e) { fclose($h); @unlink($tmp); throw $e; }
    fclose($h);
    if (!rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('state rename'); }
}
try {
    $mode = $argv[1];
    if ($mode === 'filter') {
        $max = (int)$argv[2]; $raw = ''; $bytes = 0; $large = false;
        while (!feof(STDIN)) {
            $chunk = fread(STDIN, 8192);
            if ($chunk === false) throw new RuntimeException('read');
            $bytes += strlen($chunk);
            if ($bytes <= 262144) $raw .= $chunk; else $large = true;
        }
        $result = ['jobs'=>null, 'failed'=>null, 'elapsedMs'=>null, 'reached'=>'invalid-output', 'outputBytes'=>$bytes];
        $exit = 2;
        if (!$large) {
            try {
                $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
                $allowed = ['none-ready','job-limit','time-limit','memory-limit','exception','read-only','replica-lag-limit','none-possible'];
                if (!is_array($data) || !isset($data['jobs'], $data['reached']) || !is_array($data['jobs']) ||
                    !array_is_list($data['jobs']) || count($data['jobs']) > $max || !in_array($data['reached'], $allowed, true)) throw new RuntimeException('shape');
                $failed = 0; $elapsed = 0;
                foreach ($data['jobs'] as $job) {
                    if (!is_array($job) || !in_array($job['status'] ?? null, ['ok','failed'], true) ||
                        !is_int($job['time'] ?? null) || $job['time'] < 0) throw new RuntimeException('job');
                    $failed += $job['status'] === 'failed' ? 1 : 0; $elapsed += $job['time'];
                }
                $result = ['jobs'=>count($data['jobs']), 'failed'=>$failed, 'elapsedMs'=>$elapsed,
                    'reached'=>$data['reached'], 'outputBytes'=>$bytes];
                $exit = $failed || !in_array($data['reached'], ['none-ready','job-limit','time-limit'], true) ? 2 : 0;
            } catch (Throwable $e) { /* Never emit native diagnostic/job data. */ }
        }
        if ($large) $result['reached'] = 'output-limit';
        echo json_encode($result, JSON_THROW_ON_ERROR), "\n"; exit($exit);
    }
    if ($mode === 'phase') {
        [$unused, $unused2, $file, $filtered, $name, $nativeExit, $parseExit, $started, $ended, $maxJobs, $soft, $hard] = $argv;
        $data = json_decode((string)file_get_contents($filtered), true);
        if (!is_array($data)) $data = ['jobs'=>null,'failed'=>null,'elapsedMs'=>null,'reached'=>'summary-unavailable'];
        $data += ['outputBytes'=>null];
        $data['phase'] = $name; $data['nativeExit'] = (int)$nativeExit; $data['summaryExit'] = (int)$parseExit;
        $data['wallSeconds'] = max(0, (int)$ended - (int)$started);
        $data['maxJobs'] = (int)$maxJobs; $data['softSeconds'] = (int)$soft; $data['hardSeconds'] = (int)$hard;
        atomicWrite($file, json_encode($data, JSON_THROW_ON_ERROR) . "\n"); exit;
    }
    if ($mode === 'interrupted') {
        [$unused, $unused2, $file, $name, $code, $started, $maxJobs, $soft, $hard] = $argv;
        $data = ['phase'=>$name,'jobs'=>null,'failed'=>null,'elapsedMs'=>null,'reached'=>'wrapper-interrupted',
            'nativeExit'=>null,'summaryExit'=>null,'wrapperExit'=>(int)$code,'outputBytes'=>null,
            'wallSeconds'=>max(0,time()-(int)$started),'maxJobs'=>(int)$maxJobs,'softSeconds'=>(int)$soft,'hardSeconds'=>(int)$hard];
        atomicWrite($file, json_encode($data, JSON_THROW_ON_ERROR) . "\n"); exit;
    }
    if ($mode === 'status') {
        [$unused, $unused2, $status, $history, $run, $started, $state, $code, $active] = $argv;
        $phases = [];
        foreach (glob($run . '/phase-*.json') as $file) {
            $phase = json_decode((string)file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($phase)) throw new RuntimeException('phase state');
            $phases[] = $phase;
        }
        $result = ['schemaVersion'=>1,'state'=>$state,'startedAt'=>(int)$started,'updatedAt'=>time(),
            'exitCode'=>$state === 'running' ? null : (int)$code,'activePhase'=>$active,'phases'=>$phases];
        $line = json_encode($result, JSON_THROW_ON_ERROR) . "\n";
        if ($state !== 'running') {
            $tail = '';
            if (is_link($history) || (file_exists($history) && !is_file($history))) throw new RuntimeException('unsafe history');
            if (is_file($history)) {
                $h = fopen($history, 'rb'); if (!$h) throw new RuntimeException('history read');
                $size = filesize($history); $offset = max(0, $size - 65536 + strlen($line));
                if (fseek($h, $offset) !== 0) throw new RuntimeException('history seek');
                $tail = stream_get_contents($h, 65536); fclose($h);
                if ($offset > 0) { $newline = strpos($tail, "\n"); $tail = $newline === false ? '' : substr($tail, $newline + 1); }
            }
            atomicWrite($history, $tail . $line);
        }
        // Never advertise success if the bounded history could not be saved.
        atomicWrite($status, $line);
        exit;
    }
    throw new RuntimeException('mode');
} catch (Throwable $e) { fwrite(STDERR, "Job runner summary/state operation failed.\n"); exit(74); }
PHP
)

RUN_DIR=$(mktemp -d "$STATE/.eql-jobrunner.XXXXXXXX") || exit 73
STARTED=$(date +%s)
ACTIVE="starting"
ACTIVE_NUMBER=""
PRIORITY=("$NICE_BIN" -n 15)
[[ ! -x "$IONICE_BIN" ]] || PRIORITY+=("$IONICE_BIN" -c 3)

helper() {
    "$TIMEOUT_BIN" --foreground --signal=TERM --kill-after=1s 3s "${PRIORITY[@]}" "$PHP_BIN" -d memory_limit=32M -r "$PHP_HELPER" -- "$@"
}
snapshot() { helper status "$STATUS" "$HISTORY" "$RUN_DIR" "$STARTED" "$1" "$2" "$ACTIVE"; }
finish() {
    result=$?
    trap - EXIT INT TERM HUP
    state=failed; [[ "$result" -ne 0 ]] || state=passed
    if [[ -n "$ACTIVE_NUMBER" && ! -f "$RUN_DIR/phase-$ACTIVE_NUMBER.json" ]]; then
        helper interrupted "$RUN_DIR/phase-$ACTIVE_NUMBER.json" "$ACTIVE" "$result" "$ACTIVE_STARTED" "$ACTIVE_MAX" "$ACTIVE_SOFT" "$ACTIVE_HARD" || result=74
    fi
    snapshot "$state" "$result" || result=74
    case "$RUN_DIR" in "$STATE"/.eql-jobrunner.*) rm -rf -- "$RUN_DIR" ;; *) result=73 ;; esac
    exit "$result"
}
trap finish EXIT
trap 'exit 124' TERM
trap 'exit 130' INT
trap 'exit 129' HUP
snapshot running 0 || exit 74

phase() {
    local number="$1" name="$2" type="$3" max="$4" soft="$5" hard="$6"
    local start native_exit parse_exit filtered="$RUN_DIR/filtered.json"
    local -a result
    local -a args=(maintenance/run.php runJobs "--maxjobs=$max" "--maxtime=$soft" --memory-limit=192M --result=json)
    [[ -z "$type" ]] || args+=("--type=$type")
    ACTIVE="$name"; snapshot running 0 || return 74
    start=$(date +%s)
    ACTIVE_NUMBER="$number"; ACTIVE_STARTED="$start"; ACTIVE_MAX="$max"; ACTIVE_SOFT="$soft"; ACTIVE_HARD="$hard"
    # Inner timeouts stay in the outer timeout's process group, so its final
    # deadline also terminates the native job and output consumer descendants.
    "${PRIORITY[@]}" "$TIMEOUT_BIN" --foreground --signal=TERM --kill-after=2s "${hard}s" "$PHP_BIN" "${args[@]}" 2>&1 |
        "${PRIORITY[@]}" "$PHP_BIN" -d memory_limit=32M -r "$PHP_HELPER" -- filter "$max" > "$filtered"
    result=("${PIPESTATUS[@]}")
    native_exit=${result[0]}; parse_exit=${result[1]}
    helper phase "$RUN_DIR/phase-$number.json" "$filtered" "$name" "$native_exit" "$parse_exit" "$start" "$(date +%s)" "$max" "$soft" "$hard" || return 74
    [[ "$native_exit" -eq 0 ]] || return "$native_exit"
    return "$parse_exit"
}

# 25s native soft budgets / 38s phase hard budgets, with a 48s whole-run bound.
# Recursive jobs can fan out; these are jobs, not pages or a throughput promise.
phase 1 htmlCacheUpdate htmlCacheUpdate 100 10 14 || exit $?
phase 2 refreshLinks refreshLinks 10 5 8 || exit $?
phase 3 refreshLinksDynamic refreshLinksDynamic 10 5 8 || exit $?
phase 4 general "" 5 5 8 || exit $?
ACTIVE=complete
exit 0
