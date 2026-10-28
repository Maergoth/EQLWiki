#!/usr/bin/env python3
"""Synthetic cron runner checks. No MediaWiki, DB, network, jobs or host changes.

PHP_BINARY selects a local CLI. Linux additionally tests real flock/timeouts;
Windows uses Git Bash plus fake scheduling commands and the real JSON helper.
"""
import json
import os
from pathlib import Path
import shutil
import signal
import subprocess
import sys
import tempfile
import time
import unittest

SOURCE = (Path(__file__).parent / 'host-bin/eqlwiki-run-jobs.sh').read_text()
PHP = os.environ.get('PHP_BINARY') or shutil.which('php')
BASH = os.environ.get('BASH_BINARY') or (str(Path(os.environ.get('ProgramFiles', 'C:/Program Files')) / 'Git/bin/bash.exe') if os.name == 'nt' else shutil.which('bash'))
REAL_LOCKS = os.name != 'nt' and shutil.which('flock') and shutil.which('timeout')

JOB = r'''import json, os, pathlib, sys, time
root = pathlib.Path(os.environ['JOB_TEST_ROOT'])
args = sys.argv[1:]
kind = next((a.split('=', 1)[1] for a in args if a.startswith('--type=')), 'general')
maximum = int(next(a.split('=', 1)[1] for a in args if a.startswith('--maxjobs=')))
with (root / 'calls.jsonl').open('a') as stream:
    stream.write(json.dumps({'type':kind,'args':args}) + '\n')
mode = os.environ.get('JOB_TEST_MODE', 'success')
if mode == 'sleep' and kind == 'htmlCacheUpdate':
    (root / 'sleeping').write_text('ready')
    time.sleep(5)
if mode == 'malformed':
    print('PRIVATE_TITLE not-json'); sys.exit(0)
if mode == 'large':
    print('PRIVATE_TITLE' * 30000); sys.exit(0)
count = 0 if mode == 'empty' else maximum
jobs = [{'type':kind,'status':'ok','time':2,'error':'PRIVATE_TITLE PRIVATE_PARAM'} for _ in range(count)]
reached = 'none-ready' if not count else 'job-limit'
if mode in ('exception', 'memory-limit', 'read-only', 'replica-lag-limit', 'none-possible', 'time-limit'):
    reached = mode
if mode == 'failed': jobs[0]['status'] = 'failed'
if mode == 'bad-job': jobs[0]['time'] = 'not-number'
if mode == 'too-many': jobs.append(jobs[0])
print(json.dumps({'jobs':jobs,'elapsed':count*2,'reached':reached,'backoffs':{}}))
sys.exit(42 if mode == 'process-failure' else 0)
'''


def put(path, text):
    path.write_text(text, newline='\n')
    path.chmod(0o700)
    return path.as_posix()


class Fixture:
    def __init__(self, root, real=False):
        self.root = Path(root)
        self.state = self.root / 'private-cache/mediawiki'
        self.state.mkdir(parents=True)
        (self.root / 'public_html/maintenance').mkdir(parents=True)
        (self.root / 'public_html/maintenance/run.php').write_text('<?php // Never loaded.\n')
        put(self.root / 'job.py', JOB)
        php = put(self.root / 'php', '''#!/bin/bash
if [[ "$1" == maintenance/run.php ]]; then
  exec "$JOB_TEST_PYTHON" "$JOB_TEST_ROOT/job.py" "$@"
fi
args=()
for argument in "$@"; do
  # Git Bash normalizes HOME to /c/...; only fixture path arguments need
  # conversion for the Windows PHP executable, never the inline PHP program.
  if [[ "$OSTYPE" == msys* && "$argument" == /* ]]; then
    argument="$(cygpath -m "$argument")"
  fi
  args+=("$argument")
done
exec "$JOB_TEST_PHP" -n "${args[@]}"
''')
        lock = put(self.root / 'flock', '#!/bin/bash\n[[ "${JOB_TEST_BUSY:-0}" != 1 ]]\n')
        timeout = put(self.root / 'timeout', '''#!/bin/bash
while [[ "$1" == --* ]]; do shift; done
duration="$1"; shift
printf '%s\n' "$duration" >> "$JOB_TEST_ROOT/timeouts"
if [[ "${JOB_TEST_MODE:-}" == timeout && "$duration" == 14s ]]; then exit 124; fi
exec "$@"
''')
        nice = put(self.root / 'nice', '#!/bin/bash\n[[ "$1" == -n && "$2" == 15 ]] || exit 91\nshift 2\nexec "$@"\n')
        self.ionice = put(self.root / 'ionice', '#!/bin/bash\n[[ "$1" == -c && "$2" == 3 ]] || exit 92\nshift 2\nprintf x >> "$JOB_TEST_ROOT/ionice-called"\nexec "$@"\n')
        source = SOURCE
        for key, value in [('PHP_BIN', php), ('FLOCK_BIN', shutil.which('flock') if real else lock),
                           ('TIMEOUT_BIN', shutil.which('timeout') if real else timeout), ('NICE_BIN', nice),
                           ('IONICE_BIN', self.ionice)]:
            prefix = key + '="'
            lines = [line for line in source.splitlines() if line.startswith(prefix)]
            assert len(lines) == 1
            source = source.replace(lines[0], key + '="' + value + '"')
        self.script = self.root / 'runner.sh'
        put(self.script, source)
        self.env = {**os.environ, 'HOME': self.root.as_posix(), 'JOB_TEST_ROOT': self.root.as_posix(),
                    'JOB_TEST_PHP': str(PHP), 'JOB_TEST_PYTHON': sys.executable.replace('\\', '/'),
                    'JOB_TEST_MODE': 'success'}
        if os.name == 'nt':
            self.env['MSYS2_ARG_CONV_EXCL'] = '*'
        self.real = real

    def run(self, mode='success', extra=None, arguments=()):
        env = {**self.env, 'JOB_TEST_MODE': mode, **(extra or {})}
        return subprocess.run([BASH, self.script.as_posix(), *arguments], env=env,
                              stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=12)

    def calls(self):
        file = self.root / 'calls.jsonl'
        return [json.loads(line) for line in file.read_text().splitlines()] if file.exists() else []

    def status(self):
        return json.loads((self.state / 'eql-jobrunner-status.json').read_text())


@unittest.skipUnless(PHP and BASH and Path(BASH).exists(), 'A local PHP CLI and Bash are required.')
class RunnerTests(unittest.TestCase):
    def fixture(self, real=False):
        root = tempfile.TemporaryDirectory(prefix='eql-jobrunner-synthetic-')
        self.addCleanup(root.cleanup)
        return Fixture(root.name, real=real)

    def test_success_native_arguments_counts_priority_and_bounded_status(self):
        f = self.fixture()
        old_log = f.state / 'eql-jobrunner.log'
        old_log.write_text('Original private cron log must stay exact.\n')
        result = f.run()
        self.assertEqual(result.returncode, 0, result.stderr.decode())
        calls = f.calls()
        self.assertEqual([c['type'] for c in calls], ['htmlCacheUpdate', 'refreshLinks', 'refreshLinksDynamic', 'general'])
        for call, maximum, soft in zip(calls, [100, 10, 10, 5], [10, 5, 5, 5]):
            self.assertIn('--maxjobs=' + str(maximum), call['args'])
            self.assertIn('--maxtime=' + str(soft), call['args'])
            self.assertIn('--result=json', call['args'])
            self.assertIn('--memory-limit=192M', call['args'])
            self.assertFalse(any(a in ('--quiet', '--wait', '--nothrottle') or a.startswith('--procs') for a in call['args']))
        status = f.status()
        self.assertEqual(status['state'], 'passed')
        self.assertEqual(status['exitCode'], 0)
        self.assertEqual([p['jobs'] for p in status['phases']], [100, 10, 10, 5])
        self.assertEqual([p['hardSeconds'] for p in status['phases']], [14, 8, 8, 8])
        self.assertTrue(all(p['nativeExit'] == p['summaryExit'] == p['failed'] == 0 for p in status['phases']))
        self.assertTrue((f.root / 'ionice-called').exists())
        self.assertIn('48s', (f.root / 'timeouts').read_text())
        for file in [f.state / 'eql-jobrunner-status.json', f.state / 'eql-jobrunner-history.jsonl']:
            self.assertNotIn('PRIVATE_TITLE', file.read_text())
            self.assertNotIn('PRIVATE_PARAM', file.read_text())
            if os.name != 'nt': self.assertEqual(file.stat().st_mode & 0o777, 0o600)
        self.assertEqual(old_log.read_text(), 'Original private cron log must stay exact.\n')
        self.assertEqual(list(f.state.glob('.eql-jobrunner.*')), [])

    def test_failed_native_semantics_and_process_exit_propagate(self):
        modes = {'failed': 2, 'exception': 2, 'memory-limit': 2, 'read-only': 2,
                 'replica-lag-limit': 2, 'none-possible': 2, 'malformed': 2, 'large': 2,
                 'bad-job': 2, 'too-many': 2, 'process-failure': 42, 'timeout': 124}
        for mode, expected in modes.items():
            with self.subTest(mode=mode):
                f = self.fixture()
                result = f.run(mode)
                self.assertEqual(result.returncode, expected, result.stderr.decode())
                status = f.status()
                self.assertEqual(status['state'], 'failed')
                self.assertEqual(status['exitCode'], expected)
                self.assertEqual(len(status['phases']), 1, 'Do not launch more work after a failing phase.')
                if mode == 'failed': self.assertEqual(status['phases'][0]['failed'], 1)
                if mode in ('large', 'malformed', 'timeout'):
                    self.assertIsNone(status['phases'][0]['jobs'], 'Unknown counts must not become a fake zero.')
                if mode == 'large':
                    self.assertEqual(status['phases'][0]['reached'], 'output-limit')
                    self.assertGreater(status['phases'][0]['outputBytes'], 262144)
                    self.assertLess((f.state / 'eql-jobrunner-status.json').stat().st_size, 2048)
                self.assertNotIn('PRIVATE_TITLE', (f.state / 'eql-jobrunner-history.jsonl').read_text())

    def test_empty_and_native_soft_time_limit_are_valid_bounded_results(self):
        for mode in ['empty', 'time-limit']:
            f = self.fixture()
            result = f.run(mode)
            self.assertEqual(result.returncode, 0, result.stderr.decode())
            self.assertEqual(len(f.calls()), 4)
            self.assertEqual(f.status()['state'], 'passed')

    def test_busy_is_noop_no_status_overwrite_and_direct_child_rejected(self):
        f = self.fixture()
        target = f.state / 'eql-jobrunner-status.json'
        target.write_text('{"existingActiveRun":true}\n')
        signature = (target.read_bytes(), target.stat().st_mtime_ns)
        self.assertEqual(f.run(extra={'JOB_TEST_BUSY': '1'}).returncode, 0)
        self.assertEqual(f.calls(), [])
        self.assertEqual((target.read_bytes(), target.stat().st_mtime_ns), signature)
        self.assertEqual(f.run(arguments=['--bounded-child']).returncode, 73)
        self.assertEqual(f.calls(), [])

    def test_missing_optional_io_priority_and_bounded_history(self):
        f = self.fixture()
        Path(f.ionice).unlink()
        history = f.state / 'eql-jobrunner-history.jsonl'
        history.write_text((json.dumps({'synthetic': 'x' * 90}) + '\n') * 1500)
        self.assertEqual(f.run().returncode, 0)
        self.assertLessEqual(history.stat().st_size, 65536)
        for line in history.read_text().splitlines(): json.loads(line)
        self.assertEqual(json.loads(history.read_text().splitlines()[-1])['state'], 'passed')

    def test_status_write_failure_never_advertises_pass(self):
        f = self.fixture()
        (f.state / 'eql-jobrunner-history.jsonl').mkdir()
        self.assertEqual(f.run().returncode, 74)
        self.assertNotEqual(f.status()['state'], 'passed')
        self.assertEqual(list(f.state.glob('*.tmp')), [])

    @unittest.skipUnless(REAL_LOCKS, 'Real Linux flock/process-group timeout coverage runs in CI.')
    def test_real_flock_nonoverlap_and_timeout_process_tree(self):
        f = self.fixture(real=True)
        env = {**f.env, 'JOB_TEST_MODE': 'sleep'}
        first = subprocess.Popen([BASH, f.script.as_posix()], env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, start_new_session=True)
        def stop_owned_tree():
            if first.poll() is None:
                os.killpg(first.pid, signal.SIGTERM)
                first.communicate(timeout=4)
        self.addCleanup(stop_owned_tree)
        deadline = time.monotonic() + 5
        while not (f.root / 'sleeping').exists() and time.monotonic() < deadline: time.sleep(.02)
        self.assertTrue((f.root / 'sleeping').exists())
        active = f.status()
        self.assertEqual(f.run().returncode, 0)
        self.assertEqual(f.status(), active)
        self.assertEqual(len(f.calls()), 1)
        out, err = first.communicate(timeout=10)
        self.assertEqual(first.returncode, 0, err.decode())
        self.assertEqual(len(f.calls()), 4)
        short = self.fixture(real=True)
        short.script.write_text(short.script.read_text().replace(' 48s ', ' 1s '), newline='\n')
        begin = time.monotonic()
        result = short.run('sleep')
        self.assertEqual(result.returncode, 124)
        self.assertLess(time.monotonic() - begin, 4)
        self.assertNotEqual(short.status()['state'], 'passed')
        interrupted = short.status()['phases'][0]
        self.assertEqual(interrupted['reached'], 'wrapper-interrupted')
        self.assertIsNone(interrupted['nativeExit'])
        self.assertIsNone(interrupted['jobs'])
        self.assertEqual(short.run().returncode, 0, 'Killed descendants must release the inherited lock.')
        self.assertEqual(len(short.calls()), 5)
        phase = self.fixture(real=True)
        phase.script.write_text(phase.script.read_text().replace('100 10 14 ||', '100 10 1 ||'), newline='\n')
        self.assertEqual(phase.run('sleep').returncode, 124)
        self.assertEqual(phase.status()['phases'][0]['nativeExit'], 124)
        self.assertEqual(phase.status()['phases'][0]['reached'], 'invalid-output')


if __name__ == '__main__':
    unittest.main(verbosity=2)
