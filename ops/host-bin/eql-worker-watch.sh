#!/bin/bash
OUTDIR="$HOME/private-cache/mediawiki/worker-incidents"
ACCESS="$HOME/access-logs/eqlwiki.com-ssl_log"
mkdir -p "$OUTDIR"
COUNT="$(pgrep -u "$USER" -x lsphp 2>/dev/null | wc -l)"
echo "$(date -u -Is) count=$COUNT" >> "$OUTDIR/worker-count.log"
if [ "$COUNT" -lt 15 ]; then rm -f "$OUTDIR/.incident-open"; exit 0; fi
[ -e "$OUTDIR/.incident-open" ] && exit 0
touch "$OUTDIR/.incident-open"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
OUT="$OUTDIR/incident-$STAMP.txt"
{
echo "===== EQL WORKER INCIDENT ====="
echo "UTC: $(date -u -Is)"
echo "LSPHP count: $COUNT"
echo
echo "=== PROCESSES ==="
ps -u "$USER" -o pid,ppid,lstart,etime,stat,pcpu,pmem,args
echo
echo "=== WAIT STATES ==="
for p in $(pgrep -u "$USER" -x lsphp); do printf "%-8s " "$p"; cat "/proc/$p/wchan" 2>/dev/null || echo "?"; done
echo
echo "=== RECENT ACCESS REQUESTS ==="
tail -n 1000 "$ACCESS" 2>/dev/null
echo
echo "=== CLI JOB/CACHE PROCESSES ==="
ps -u "$USER" -o pid,ppid,etime,stat,pcpu,pmem,args | grep -E "[e]ql-icon|[r]unJobs|maintenance/run.php"
echo
echo "=== ICON CACHE LOG ==="
tail -n 30 "$HOME/private-cache/mediawiki/eql-icon-static.log" 2>/dev/null
} > "$OUT" 2>&1
chmod 600 "$OUT"
