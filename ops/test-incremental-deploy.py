#!/usr/bin/env python3
"""Real temporary Git/filesystem deployment fixtures; never contacts a wiki host.

PHP_BINARY may select a local CLI. Receiver/lock/HTTP tests require POSIX bash,
flock and curl; PHP transaction tests also run on Windows. Fixture Git commits
exist only in disposable synthetic repositories, never in the application tree.
"""
import http.server
import importlib.util
import io
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import sys
import tarfile
import tempfile
import threading
import unittest

sys.dont_write_bytecode = True
HERE = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location('incremental_release_builder', HERE / 'build-release.py')
BUILDER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(BUILDER)
MARKER = 'skins/EQLImmersive/deployment-version.txt'
MANIFEST = '.eql-deployment-manifest'
PAYLOAD = '.eql-deployment-payload'
METADATA = '.eql-deployment.json'
PHP = os.environ.get('PHP_BINARY') or shutil.which('php')
BASH = shutil.which('bash') if os.name != 'nt' else None
SHELL_READY = bool(BASH and shutil.which('flock') and shutil.which('curl') and shutil.which('tar'))


def execute(arguments, *, cwd=None, env=None, input=None, success=True):
    result = subprocess.run([str(argument) for argument in arguments], cwd=cwd, env=env,
                            input=input, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=35)
    if success and result.returncode:
        raise AssertionError(f'{arguments!r} exited {result.returncode}:\n'
                             f'{result.stdout.decode(errors="replace")}\n{result.stderr.decode(errors="replace")}')
    return result


def write(root, name, value):
    target = root / name
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_bytes(value if isinstance(value, bytes) else value.encode())
    return target


def signature(path):
    info = path.stat()
    return (path.read_bytes(), info.st_mtime_ns, info.st_ino, stat.S_IMODE(info.st_mode))


def tree(root):
    return {str(path.relative_to(root)).replace('\\', '/'): signature(path)
            for path in root.rglob('*') if path.is_file() and not path.is_symlink()}


class SyntheticRepository:
    def __init__(self, directory):
        self.root = directory
        self.git('init', '--quiet')
        self.git('config', 'user.name', 'Synthetic deployment fixture')
        self.git('config', 'user.email', 'fixture@example.invalid')
        self.git('config', 'core.autocrlf', 'false')
        self.git('config', 'core.filemode', 'false' if os.name == 'nt' else 'true')
        self.initial = {
            'index.php': '<?php // Synthetic public entry point\n',
            '.htaccess': 'RewriteEngine On\n# synthetic production routing\n',
            'resources/site.css': 'body { color: gold; }\n',
            'core/unchanged.php': '<?php // Unchanged synthetic core\n',
            'command.sh': '#!/bin/sh\nexit 0\n',
            'obsolete.txt': 'An intentionally deleted application file.\n',
            'rename-old.txt': 'An intentionally renamed application file.\n',
            'bb/Themes/PurpleHaze/index.template.php': '<?php // Synthetic forum template\n',
            'LocalSettings.php': '<?php // Private synthetic settings\n',
            'BridgeSecrets.php': '<?php // Private synthetic bridge\n',
            'bb/Settings.php': '<?php // Private synthetic forum settings\n',
            'static/eql-icon-index/cache.json': '{"generated":true}\n',
            'ops/private-helper.php': '<?php // Excluded synthetic host helper\n',
            '.github/workflows/fixture.yml': 'name: excluded synthetic workflow\n',
            'LocalSettings.example.php': '<?php // Excluded example\n',
        }
        for name, content in self.initial.items():
            write(self.root, name, content)
        self.base = self.commit('Synthetic baseline')

    def git(self, *arguments):
        return execute(['git', '-c', f'safe.directory={self.root.as_posix()}', *arguments], cwd=self.root).stdout

    def commit(self, message):
        self.git('add', '--all')
        self.git('commit', '--quiet', '-m', message)
        return self.git('rev-parse', 'HEAD').decode().strip()

    def build(self, output, base=None):
        count = BUILDER.build_release(self.root, output, base_revision=base)
        with tarfile.open(output) as archive:
            members = archive.getmembers()
            records = {member.name: (archive.extractfile(member).read(), member.mode) for member in members}
        final = records[MANIFEST][0].decode().splitlines()
        payload = records[PAYLOAD][0].decode().splitlines()
        metadata = json.loads(records[METADATA][0])
        assert count == len(final)
        assert final[-1] == payload[-1] == MARKER
        assert len(final) == len(set(final)) and len(payload) == len(set(payload))
        assert set(records) == set(payload) | {MANIFEST, PAYLOAD, METADATA}
        assert records[MARKER][0] == (metadata['revision'] + '\n').encode()
        assert not (self.root / MANIFEST).exists()
        return {'records': records, 'final': final, 'payload': payload, 'metadata': metadata}


class GitDeltaTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='eql-delta-git-')
        self.addCleanup(self.temp.cleanup)
        self.directory = Path(self.temp.name)
        (self.directory / 'repo').mkdir()
        self.repo = SyntheticRepository(self.directory / 'repo')

    def test_real_css_delta_complete_manifest_and_marker_only_same_revision(self):
        full = self.repo.build(self.directory / 'full.tar.gz')
        write(self.repo.root, 'resources/site.css', 'body { color: navy; }\n')
        head = self.repo.commit('Synthetic CSS only')
        delta = self.repo.build(self.directory / 'delta.tar.gz', self.repo.base)
        self.assertEqual(delta['final'], full['final'])
        self.assertEqual(delta['payload'], ['resources/site.css', MARKER])
        self.assertEqual(delta['metadata'], {'protocol': 2, 'revision': head, 'base_revision': self.repo.base})
        same = self.repo.build(self.directory / 'same.tar.gz', head)
        self.assertEqual(same['payload'], [MARKER])
        self.assertEqual(same['records'][MARKER][0], (head + '\n').encode())
        for excluded in ['LocalSettings.php', 'BridgeSecrets.php', 'bb/Settings.php', 'ops/private-helper.php',
                         '.github/workflows/fixture.yml', 'static/eql-icon-index/cache.json', 'LocalSettings.example.php']:
            self.assertNotIn(excluded, delta['final'])

    def test_real_add_delete_rename_and_executable_mode(self):
        write(self.repo.root, 'resources/new.js', '/* synthetic addition */\n')
        (self.repo.root / 'obsolete.txt').unlink()
        self.repo.git('mv', 'rename-old.txt', 'rename-new.txt')
        self.repo.git('add', '--all')
        self.repo.git('update-index', '--chmod=+x', 'command.sh')
        if os.name != 'nt':
            (self.repo.root / 'command.sh').chmod(0o755)
        self.repo.git('commit', '--quiet', '-m', 'Synthetic additions deletions rename and mode')
        delta = self.repo.build(self.directory / 'delta.tar.gz', self.repo.base)
        self.assertEqual(delta['payload'], ['command.sh', 'rename-new.txt', 'resources/new.js', MARKER])
        self.assertNotIn('obsolete.txt', delta['final'])
        self.assertNotIn('rename-old.txt', delta['final'])
        self.assertEqual(delta['records']['command.sh'][1], 0o755)
        self.assertEqual(delta['records']['rename-new.txt'][0], self.repo.initial['rename-old.txt'].encode())

    def test_dirty_revision_and_invalid_base_fail_without_control_residue(self):
        write(self.repo.root, 'resources/site.css', 'Uncommitted bytes must not claim HEAD.\n')
        with self.assertRaises(subprocess.CalledProcessError):
            self.repo.build(self.directory / 'dirty.tar.gz', self.repo.base)
        with self.assertRaises(subprocess.CalledProcessError):
            self.repo.build(self.directory / 'dirty-full.tar.gz')
        self.assertFalse((self.repo.root / MANIFEST).exists())
        self.repo.git('restore', '--', 'resources/site.css')
        for base in ['main', 'A' * 40, 'f' * 39]:
            with self.subTest(base=base), self.assertRaises(RuntimeError):
                self.repo.build(self.directory / 'invalid.tar.gz', base)

    def test_cli_status_exact_base_fallback_and_explicit_full_repair(self):
        script = write(self.repo.root, 'ops/build-release.py', (HERE / 'build-release.py').read_bytes())
        self.repo.commit('Synthetic packager CLI copy')
        status = self.directory / 'status.json'
        output = self.directory / 'cli.tar.gz'
        status.write_text(json.dumps({'protocol': 2, 'revision': self.repo.base}))
        execute([sys.executable, script, '--status-file', status, '--output', output])
        with tarfile.open(output) as archive:
            self.assertEqual(json.loads(archive.extractfile(METADATA).read())['base_revision'], self.repo.base)
        for value in [{'protocol': 2, 'revision': 'f' * 40}, {'protocol': 2, 'revision': None}]:
            status.write_text(json.dumps(value))
            execute([sys.executable, script, '--status-file', status, '--output', output])
            with tarfile.open(output) as archive:
                self.assertIsNone(json.loads(archive.extractfile(METADATA).read())['base_revision'])
                self.assertEqual(archive.extractfile(MANIFEST).read(), archive.extractfile(PAYLOAD).read())
        status.write_text(json.dumps({'protocol': 2, 'revision': self.repo.base}))
        execute([sys.executable, script, '--status-file', status, '--full', '--output', output])
        with tarfile.open(output) as archive:
            self.assertIsNone(json.loads(archive.extractfile(METADATA).read())['base_revision'])
        for value in [{'protocol': 1, 'revision': self.repo.base}, {'protocol': 2, 'revision': 'main'}, []]:
            status.write_text(json.dumps(value))
            self.assertNotEqual(execute([sys.executable, script, '--status-file', status, '--output', output], success=False).returncode, 0)


class DeployFixture(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if not PHP:
            raise RuntimeError('PHP CLI is required for real deploy transaction fixtures; set PHP_BINARY.')
        if not (HERE / 'deploy-files.php').is_file():
            raise RuntimeError('The reviewed deploy-files.php helper must exist before these fixtures run.')

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='eql-delta-files-')
        self.addCleanup(self.temp.cleanup)
        self.directory = Path(self.temp.name)
        self.repo_root = self.directory / 'repo'
        self.repo_root.mkdir()
        self.repo = SyntheticRepository(self.repo_root)
        self.live, self.state = self.directory / 'site', self.directory / 'state'
        self.live.mkdir()
        self.state.mkdir()
        for name in ['LocalSettings.php', 'BridgeSecrets.php', 'bb/Settings.php']:
            write(self.live, name, '<?php // Existing private synthetic host data\n')
        self.env = os.environ.copy()
        self.env.pop('SSH_ORIGINAL_COMMAND', None)
        self.env['PATH'] = str(Path(PHP).parent) + os.pathsep + self.env.get('PATH', '')
        self.sequence = 0

    def helper(self, command, *arguments, success=True):
        return execute([PHP, HERE / 'deploy-files.php', command, *arguments], env=self.env, success=success)

    def staged(self, base=None):
        self.sequence += 1
        archive = self.directory / f'release-{self.sequence}.tar.gz'
        bundle = self.repo.build(archive, base)
        stage = self.directory / f'stage-{self.sequence}'
        stage.mkdir()
        # Only this fixture's trusted regular builder archive is unpacked here.
        # Malicious archives are exercised through the real shell preflight.
        with tarfile.open(archive) as source:
            for member in source:
                self.assertTrue(member.isfile())
                self.assertNotIn('..', Path(member.name).parts)
                target = write(stage, member.name, source.extractfile(member).read())
                target.chmod(member.mode)
        self.helper('validate', stage, bundle['metadata']['revision'])
        return stage, bundle, archive

    def plan(self, stage, revision):
        backup = self.directory / f'backup-{self.sequence}'
        backup.mkdir()
        self.helper('plan', stage, self.live, self.state, revision, backup)
        return backup, json.loads((backup / 'plan.json').read_text())

    def install(self, base=None):
        stage, bundle, _ = self.staged(base)
        backup, plan = self.plan(stage, bundle['metadata']['revision'])
        self.helper('begin', self.state, backup)
        self.helper('apply', stage, self.live, backup)
        self.helper('activate', stage, self.state, backup, bundle['metadata']['revision'])
        return stage, bundle, backup, plan


class TransactionTests(DeployFixture):
    def test_css_delta_preserves_unrelated_inode_mtime_bytes_and_backs_up_only_touched(self):
        self.install()
        unchanged = self.live / 'core/unchanged.php'
        os.utime(unchanged, ns=(1600000000000000000, 1600000000000000000))
        before = signature(unchanged)
        private = signature(self.live / 'LocalSettings.php')
        write(self.repo.root, 'resources/site.css', 'body { color: navy; }\n')
        revision = self.repo.commit('Synthetic CSS update')
        _, bundle, backup, plan = self.install(self.repo.base)
        self.assertEqual(bundle['payload'], ['resources/site.css', MARKER])
        self.assertEqual(plan['writePaths'], ['resources/site.css', MARKER])
        self.assertEqual(set(plan['existingPaths']), {'resources/site.css', MARKER})
        self.assertFalse((backup / 'files/core/unchanged.php').exists())
        self.assertEqual(signature(unchanged), before)
        self.assertEqual(signature(self.live / 'LocalSettings.php'), private)
        self.assertEqual((self.live / MARKER).read_text(), revision + '\n')
        self.assertEqual((self.state / 'current-sha').read_text().strip(), revision)
        self.assertEqual((self.state / 'manifest').read_text().splitlines(), bundle['final'])

    @unittest.skipIf(os.name == 'nt', 'Windows chmod cannot represent the host POSIX mode/no-op contract')
    def test_full_repair_skips_equal_files_and_repairs_missing_or_corrupt_content(self):
        self.install()
        stable = signature(self.live / 'index.php')
        write(self.live, 'resources/site.css', 'Synthetic live drift\n')
        (self.live / 'obsolete.txt').unlink()
        _, _, _, plan = self.install()
        self.assertEqual(set(plan['writePaths']), {'resources/site.css', 'obsolete.txt'})
        self.assertEqual(signature(self.live / 'index.php'), stable)
        self.assertEqual((self.live / 'resources/site.css').read_text(), self.repo.initial['resources/site.css'])
        self.assertEqual((self.live / 'obsolete.txt').read_text(), self.repo.initial['obsolete.txt'])

    def test_delta_add_delete_rename_mode_and_explicit_guarded_rollback(self):
        self.install()
        prior = tree(self.live)
        old_manifest = (self.state / 'manifest').read_bytes()
        write(self.repo.root, 'new/deep/added.txt', 'Synthetic addition\n')
        (self.repo.root / 'obsolete.txt').unlink()
        self.repo.git('mv', 'rename-old.txt', 'rename-new.txt')
        self.repo.git('add', '--all')
        self.repo.git('update-index', '--chmod=+x', 'command.sh')
        if os.name != 'nt':
            (self.repo.root / 'command.sh').chmod(0o755)
        self.repo.git('commit', '--quiet', '-m', 'Synthetic changed transaction')
        _, _, backup, plan = self.install(self.repo.base)
        self.assertEqual(set(plan['deletePaths']), {'obsolete.txt', 'rename-old.txt'})
        self.assertTrue((self.live / 'new/deep/added.txt').is_file())
        self.assertFalse((self.live / 'obsolete.txt').exists())
        if os.name != 'nt':
            self.assertEqual(stat.S_IMODE((self.live / 'command.sh').stat().st_mode), 0o755)
        current = tree(self.live)
        write(self.state, 'current-sha', 'f' * 40 + '\n')
        self.assertNotEqual(self.helper('restore', self.live, self.state, backup, success=False).returncode, 0)
        self.assertEqual(tree(self.live), current, 'Stale rollback guard touches no application files')
        write(self.state, 'current-sha', plan['revision'] + '\n')
        self.helper('restore', self.live, self.state, backup)
        self.assertEqual({name: value[0] for name, value in tree(self.live).items()}, {name: value[0] for name, value in prior.items()})
        self.assertFalse((self.live / 'new/deep/added.txt').exists())
        self.assertEqual((self.state / 'manifest').read_bytes(), old_manifest)
        self.assertEqual((self.state / 'current-sha').read_text().strip(), self.repo.base)
        self.assertEqual(signature(self.live / 'index.php'), prior['index.php'], 'Rollback leaves unrelated inode/mtime intact')
        if os.name != 'nt':
            self.assertEqual(stat.S_IMODE((self.live / 'command.sh').stat().st_mode), 0o644)

    def test_failed_health_automatic_restore_keeps_state_and_removes_new_files(self):
        self.install()
        old = tree(self.live)
        old_state = tree(self.state)
        write(self.repo.root, 'new/failure-only.txt', 'Remove this on failed health.\n')
        (self.repo.root / 'obsolete.txt').unlink()
        write(self.repo.root, 'resources/site.css', 'Changed before a failed health check\n')
        revision = self.repo.commit('Synthetic failed health candidate')
        stage, _, _ = self.staged(self.repo.base)
        backup, _ = self.plan(stage, revision)
        self.helper('begin', self.state, backup)
        self.helper('apply', stage, self.live, backup)
        # Receiver's failed health path calls the actual automatic restoration
        # entry point before activation. The shell test also exercises its trap.
        self.helper('restore', self.live, self.state, backup, 'automatic')
        self.assertEqual({name: value[0] for name, value in tree(self.live).items()}, {name: value[0] for name, value in old.items()})
        self.assertEqual({name: value[0] for name, value in tree(self.state).items()}, {name: value[0] for name, value in old_state.items()})
        self.assertEqual(signature(self.live / 'core/unchanged.php'), old['core/unchanged.php'])

    def test_stale_base_and_missing_prior_manifest_reject_before_live_mutation(self):
        self.install()
        write(self.repo.root, 'resources/site.css', 'New CSS\n')
        revision = self.repo.commit('Synthetic stale lease')
        stage, _, _ = self.staged(self.repo.base)
        before = tree(self.live)
        write(self.state, 'current-sha', 'e' * 40 + '\n')
        backup = self.directory / 'stale-backup'
        backup.mkdir()
        self.assertNotEqual(self.helper('plan', stage, self.live, self.state, revision, backup, success=False).returncode, 0)
        self.assertEqual(tree(self.live), before)
        write(self.state, 'current-sha', self.repo.base + '\n')
        (self.state / 'manifest').unlink()
        self.assertNotEqual(self.helper('plan', stage, self.live, self.state, revision, backup, success=False).returncode, 0)
        self.assertEqual(tree(self.live), before)

    def test_historical_generated_cache_is_never_deleted_or_backed_up(self):
        self.install()
        cache = write(self.live, 'static/eql-icon-index/host-cache.json', 'Synthetic host-owned cache\n')
        paths = (self.state / 'manifest').read_text().splitlines()
        write(self.state, 'manifest', '\n'.join([*paths[:-1], 'static/eql-icon-index/host-cache.json', MARKER]) + '\n')
        before = signature(cache)
        _, _, backup, plan = self.install()
        self.assertNotIn('static/eql-icon-index/host-cache.json', plan['deletePaths'])
        self.assertNotIn('static/eql-icon-index/host-cache.json', plan['existingPaths'])
        self.assertFalse((backup / 'files/static/eql-icon-index/host-cache.json').exists())
        self.assertEqual(signature(cache), before)

    def test_unlisted_missing_protected_and_out_of_order_staged_paths_reject(self):
        self.install()
        before = tree(self.live)
        for attack in ['missing-payload', 'extra-file', 'marker-first', 'private-path']:
            with self.subTest(attack=attack):
                stage, bundle, _ = self.staged()
                if attack == 'missing-payload':
                    (stage / 'index.php').unlink()
                elif attack == 'extra-file':
                    write(stage, 'extra-unlisted.txt', 'Not declared in the payload\n')
                elif attack == 'marker-first':
                    write(stage, PAYLOAD, '\n'.join([MARKER, *bundle['payload'][:-1]]) + '\n')
                else:
                    write(stage, 'LocalSettings.php', 'Must never be copied\n')
                    write(stage, MANIFEST, '\n'.join([*bundle['final'][:-1], 'LocalSettings.php', MARKER]) + '\n')
                    write(stage, PAYLOAD, '\n'.join([*bundle['payload'][:-1], 'LocalSettings.php', MARKER]) + '\n')
                self.assertNotEqual(self.helper('validate', stage, bundle['metadata']['revision'], success=False).returncode, 0)
                self.assertEqual(tree(self.live), before)

    def test_interrupted_apply_blocks_status_and_requires_exact_pending_recovery(self):
        self.install()
        old = {name: value[0] for name, value in tree(self.live).items()}
        write(self.repo.root, 'new/interrupted.txt', 'Synthetic interrupted transaction\n')
        revision = self.repo.commit('Synthetic interrupted deploy')
        stage, _, _ = self.staged(self.repo.base)
        backup, _ = self.plan(stage, revision)
        self.helper('begin', self.state, backup)
        self.helper('apply', stage, self.live, backup)
        self.assertNotEqual(self.helper('status', self.state, success=False).returncode, 0)
        self.assertNotEqual(self.helper('ready', self.state, success=False).returncode, 0)
        self.assertNotEqual(self.helper('begin', self.state, backup, success=False).returncode, 0)
        wrong = self.directory / 'wrong-pending-backup'
        wrong.mkdir()
        shutil.copyfile(backup / 'plan.json', wrong / 'plan.json')
        self.assertNotEqual(self.helper('restore', self.live, self.state, wrong, success=False).returncode, 0)
        self.assertTrue((self.live / 'new/interrupted.txt').is_file())
        self.helper('restore', self.live, self.state, backup)
        self.assertFalse((self.state / 'pending-deployment').exists())
        self.assertEqual({name: value[0] for name, value in tree(self.live).items()}, old)
        self.assertEqual(json.loads(self.helper('status', self.state).stdout), {'protocol': 2, 'revision': self.repo.base})

    def test_failed_mid_apply_leaves_old_marker_and_recovers_added_files(self):
        self.install()
        old = {name: value[0] for name, value in tree(self.live).items()}
        write(self.repo.root, 'aaa-added.txt', 'Written before the induced copy failure\n')
        write(self.repo.root, 'resources/site.css', 'Synthetic new stylesheet\n')
        revision = self.repo.commit('Synthetic partial application')
        stage, _, _ = self.staged(self.repo.base)
        backup, _ = self.plan(stage, revision)
        self.helper('begin', self.state, backup)
        (stage / 'resources/site.css').unlink()
        self.assertNotEqual(self.helper('apply', stage, self.live, backup, success=False).returncode, 0)
        self.assertTrue((self.live / 'aaa-added.txt').is_file())
        self.assertEqual((self.live / MARKER).read_text(), self.repo.base + '\n', 'Marker cannot activate before all application writes succeed')
        self.helper('restore', self.live, self.state, backup, 'automatic')
        self.assertEqual({name: value[0] for name, value in tree(self.live).items()}, old)

    @unittest.skipIf(os.name == 'nt', 'Delta retry mode validation requires the host POSIX permission model')
    def test_completed_delta_retry_is_read_only_and_full_is_not_a_retry_shortcut(self):
        self.install()
        write(self.repo.root, 'resources/site.css', 'Synthetic retry target\n')
        revision = self.repo.commit('Synthetic retry target')
        stage, _, _, _ = self.install(self.repo.base)
        before = tree(self.live)
        self.helper('already-applied', stage, self.live, self.state, revision)
        self.assertEqual(tree(self.live), before)
        write(self.live, 'resources/site.css', 'Changed live payload cannot count as an already applied retry\n')
        drift = tree(self.live)
        self.assertNotEqual(self.helper('already-applied', stage, self.live, self.state, revision, success=False).returncode, 0)
        self.assertEqual(tree(self.live), drift)
        full, _, _ = self.staged()
        self.assertEqual(self.helper('already-applied', full, self.live, self.state, revision, success=False).returncode, 10)

    def test_interrupted_manual_restore_can_resume_only_its_exact_journal(self):
        self.install()
        original_css = (self.live / 'resources/site.css').read_bytes()
        write(self.repo.root, 'resources/site.css', 'Synthetic manual rollback candidate\n')
        write(self.repo.root, 'new/rollback-added.txt', 'Already removed by partial rollback\n')
        self.repo.commit('Synthetic manual rollback journal')
        _, _, backup, _ = self.install(self.repo.base)
        # Model a hard stop after manual restore journal creation and its first
        # mutations. This uses actual state/files, not a mocked filesystem.
        write(self.state, 'pending-deployment', backup.name + '\n')
        (self.live / 'new/rollback-added.txt').unlink()
        write(self.live, 'resources/site.css', original_css)
        self.assertNotEqual(self.helper('status', self.state, success=False).returncode, 0)
        self.helper('restore', self.live, self.state, backup)
        self.assertFalse((self.state / 'pending-deployment').exists())
        self.assertEqual((self.live / 'resources/site.css').read_bytes(), original_css)
        self.assertEqual((self.state / 'current-sha').read_text().strip(), self.repo.base)

    def test_legacy_recovery_cannot_bypass_v2_token_and_keeps_host_cache(self):
        self.install()
        prior_manifest = (self.state / 'manifest').read_bytes()
        prior_css, prior_marker = (self.live / 'resources/site.css').read_bytes(), (self.live / MARKER).read_bytes()
        unrelated = signature(self.live / 'core/unchanged.php')
        revision = 'e' * 40
        backup = self.directory / ('20260101T000000Z-' + revision)
        backup.mkdir()
        legacy = self.directory / 'legacy-extracted'
        legacy.mkdir()
        for path, value in [('resources/site.css', prior_css), (MARKER, prior_marker), ('static/eql-icon-index/cache.json', b'Obsolete archived cache')]:
            write(legacy, path, value)
        write(backup, 'existing-paths', 'resources/site.css\nstatic/eql-icon-index/cache.json\n' + MARKER + '\n')
        write(backup, 'new-paths', 'new/legacy-added.txt\n')
        write(backup, 'previous-manifest', prior_manifest)
        write(backup, 'previous-sha', self.repo.base + '\n')
        write(self.live, 'resources/site.css', 'Synthetic historical changed CSS\n')
        write(self.live, MARKER, revision + '\n')
        write(self.live, 'new/legacy-added.txt', 'Synthetic legacy new file\n')
        cache = write(self.live, 'static/eql-icon-index/cache.json', 'Current generated cache\n')
        cache_before = signature(cache)
        write(self.state, 'current-sha', revision + '\n')
        before = tree(self.live)
        self.assertNotEqual(self.helper('restore-legacy', self.live, self.state, backup, legacy, success=False).returncode, 0)
        self.assertEqual(tree(self.live), before, 'A same-SHA legacy backup cannot bypass a version-2 deployment token')
        (self.state / 'current-deployment').unlink()
        self.helper('restore-legacy', self.live, self.state, backup, legacy)
        self.assertEqual((self.live / 'resources/site.css').read_bytes(), prior_css)
        self.assertEqual((self.live / MARKER).read_bytes(), prior_marker)
        self.assertFalse((self.live / 'new/legacy-added.txt').exists())
        self.assertEqual(signature(cache), cache_before)
        self.assertEqual(signature(self.live / 'core/unchanged.php'), unrelated)
        self.assertEqual((self.state / 'manifest').read_bytes(), prior_manifest)
        self.assertEqual((self.state / 'current-sha').read_text().strip(), self.repo.base)

    def test_staging_prepare_transforms_only_present_payload_and_calls_access_boundary(self):
        write(self.state, 'staging-access-lib.php', '<?php function eql_staging_access_rules(string $base): string { file_put_contents(__DIR__ . "/synthetic-access-called", "yes"); return "# Synthetic administrator gate\\n" . $base; }\n')
        write(self.repo.root, 'wiki_auth_bridge.php', '<?php\n$url="https://eqlwiki.com/api.php";\n')
        write(self.repo.root, 'skins/EQLImmersive/resources/main.js', 'const wiki="https://www.eqlwiki.com";\n')
        baseline = self.repo.commit('Synthetic staging sources')
        full, _, _ = self.staged()
        index_before = signature(full / 'index.php')
        execute([PHP, HERE / 'staging-prepare.php', full, self.state], env=self.env)
        bridge = (full / 'wiki_auth_bridge.php').read_text()
        self.assertIn('https://test.eqlwiki.com/api.php', bridge)
        self.assertEqual(bridge.count("'/EQLStaging.php'"), 1)
        self.assertIn('https://test.eqlwiki.com', (full / 'skins/EQLImmersive/resources/main.js').read_text())
        self.assertIn('Synthetic administrator gate', (full / '.htaccess').read_text())
        self.assertEqual(signature(full / 'index.php'), index_before)
        access_call = signature(self.state / 'synthetic-access-called')
        write(self.repo.root, 'resources/site.css', 'body { color: violet; }\n')
        self.repo.commit('Synthetic staging CSS delta')
        delta, bundle, _ = self.staged(baseline)
        self.assertEqual(bundle['payload'], ['resources/site.css', MARKER])
        before = tree(delta)
        execute([PHP, HERE / 'staging-prepare.php', delta, self.state], env=self.env)
        self.assertEqual(tree(delta), before, 'CSS-only delta does not require or transform absent bridge/access files')
        self.assertEqual(signature(self.state / 'synthetic-access-called'), access_call)

    def test_legacy_full_bootstrap_and_metadata_revision_validation(self):
        stage, bundle, _ = self.staged()
        (stage / PAYLOAD).unlink()
        (stage / METADATA).unlink()
        result = json.loads(self.helper('validate', stage, self.repo.base).stdout)
        self.assertEqual(result, {'protocol': 1, 'revision': self.repo.base, 'base_revision': None})
        backup, _ = self.plan(stage, self.repo.base)
        self.helper('begin', self.state, backup)
        self.helper('apply', stage, self.live, backup)
        self.helper('activate', stage, self.state, backup, self.repo.base)
        self.assertEqual((self.state / 'manifest').read_text().splitlines(), bundle['final'])
        for metadata in [{'protocol': 3, 'revision': self.repo.base, 'base_revision': None},
                         {'protocol': 2, 'revision': 'e' * 40, 'base_revision': None},
                         {'protocol': 2, 'revision': self.repo.base, 'base_revision': 'main'}]:
            fresh, _, _ = self.staged()
            write(fresh, METADATA, json.dumps(metadata) + '\n')
            self.assertNotEqual(self.helper('validate', fresh, self.repo.base, success=False).returncode, 0)

    def test_archive_preflight_denies_protected_duplicate_links_and_escaped_paths(self):
        listing, types = self.directory / 'paths', self.directory / 'types'
        def check(paths, kind='-', success=False):
            write(self.directory, 'paths', '\n'.join(paths) + '\n')
            write(self.directory, 'types', '\n'.join(kind + 'rw-r--r-- 0/0 1 2026-01-01 00:00 ' + path for path in paths) + '\n')
            result = self.helper('validate-archive', listing, types, success=success)
            if not success:
                self.assertNotEqual(result.returncode, 0)
        check(['index.php', MANIFEST, PAYLOAD, METADATA, MARKER], success=True)
        for path in ['/escape', '../escape', './index.php', '.git/config', '.local/private', 'LocalSettings.php',
                     'BridgeSecrets.php', 'EQLStaging.php', 'bb/Settings.php', 'images/file', 'cache/file',
                     'static/eql-icon-index/cache.json', 'bb/attachments/file', r'resources/escaped\nname']:
            with self.subTest(path=path):
                check([path])
        check(['index.php', 'index.php'])
        for kind in ['l', 'h', 'd', 'p', 'b', 'c']:
            check(['resources/link-or-special'], kind=kind)

    def test_live_symlink_cannot_redirect_application_writes(self):
        self.install()
        outside = self.directory / 'outside'
        outside.mkdir()
        sentinel = write(outside, 'site.css', 'Do not touch an unowned target\n')
        css = self.live / 'resources/site.css'
        css.unlink()
        try:
            css.symlink_to(sentinel)
        except OSError as error:
            self.skipTest(f'This filesystem cannot create synthetic symlinks: {error}')
        before = signature(sentinel)
        write(self.repo.root, 'resources/site.css', 'Attempted new CSS\n')
        revision = self.repo.commit('Synthetic linked target')
        stage, _, _ = self.staged(self.repo.base)
        backup = self.directory / 'unsafe-link-backup'
        backup.mkdir()
        self.assertNotEqual(self.helper('plan', stage, self.live, self.state, revision, backup, success=False).returncode, 0)
        self.assertEqual(signature(sentinel), before)


class ReceiverTests(DeployFixture):
    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        if not SHELL_READY:
            if os.name != 'nt':
                raise RuntimeError('Linux CI requires real bash/flock/curl/GNU tar; install missing deployment prerequisites.')
            raise unittest.SkipTest('Actual receiver/lock/HTTP tests require POSIX bash+flock; run in Linux CI')

    def setUp(self):
        super().setUp()
        for name in ['deploy-files.php', 'staging-prepare.php']:
            shutil.copyfile(HERE / name, self.state / name)
        self.healthy, self.health_observations = True, []
        fixture = self
        class Health(http.server.BaseHTTPRequestHandler):
            def do_GET(self):
                fixture.health_observations.append({'path': self.path,
                    'marker': (fixture.live / MARKER).read_text() if (fixture.live / MARKER).exists() else None,
                    'state': (fixture.state / 'current-sha').read_text() if (fixture.state / 'current-sha').exists() else None})
                body = json.dumps({'query': {'general': {'sitename': 'Synthetic deployment wiki'}}} if fixture.healthy else {'error': 'Synthetic failed health'}).encode()
                self.send_response(200)
                self.send_header('Content-Type', 'application/json')
                self.send_header('Content-Length', str(len(body)))
                self.end_headers()
                self.wfile.write(body)
            def log_message(self, *_args):
                pass
        self.server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Health)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()
        self.addCleanup(self.server.server_close)
        self.addCleanup(self.thread.join, 3)
        self.addCleanup(self.server.shutdown)
        self.env.update({'EQL_DEPLOY_TESTING': '1', 'EQL_DEPLOY_TEST_ROOT': str(self.live),
                         'EQL_DEPLOY_TEST_STATE': str(self.state),
                         'EQL_DEPLOY_TEST_HEALTH_URL': 'http://127.0.0.1:' + str(self.server.server_port)})

    def receive(self, revision=None, archive=None, success=True):
        command = 'status' if revision is None else 'deploy ' + revision
        return execute([BASH, HERE / 'deploy-receive.sh', command], env=self.env,
                       input=archive.read_bytes() if archive else None, success=success)

    def deploy(self, base=None, success=True):
        self.sequence += 1
        archive = self.directory / f'shell-{self.sequence}.tar.gz'
        bundle = self.repo.build(archive, base)
        result = self.receive(bundle['metadata']['revision'], archive, success=success)
        return bundle, result

    def test_actual_receiver_lease_health_failure_rollback_and_lock(self):
        self.assertEqual(json.loads(self.receive().stdout), {'protocol': 2, 'revision': None})
        self.deploy()
        old_files = {name: value[0] for name, value in tree(self.live).items()}
        old_state = {name: (self.state / name).read_bytes() for name in ['manifest', 'current-sha', 'current-deployment']}
        unchanged = signature(self.live / 'core/unchanged.php')
        write(self.repo.root, 'resources/site.css', 'Synthetic failed CSS candidate\n')
        write(self.repo.root, 'new/from-failed-health.txt', 'Must be removed\n')
        revision = self.repo.commit('Synthetic failed actual receiver health')
        self.healthy = False
        _, result = self.deploy(self.repo.base, success=False)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('restoring previous touched files', result.stderr.decode())
        self.assertEqual({name: value[0] for name, value in tree(self.live).items()}, old_files)
        for name, value in old_state.items():
            self.assertEqual((self.state / name).read_bytes(), value)
        self.assertEqual(signature(self.live / 'core/unchanged.php'), unchanged)
        self.assertFalse((self.state / 'pending-deployment').exists())
        self.assertEqual(self.health_observations[-1]['marker'], revision + '\n')
        self.assertEqual(self.health_observations[-1]['state'], self.repo.base + '\n', 'State activation follows successful health')
        import fcntl
        with (self.state / 'deploy.lock').open('a') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            self.assertEqual(self.receive(success=False).returncode, 3)

    def test_actual_archive_preflight_rejects_traversal_links_and_duplicate_members(self):
        self.deploy()
        before = tree(self.live)
        for kind in ['traversal', 'symlink', 'hardlink', 'duplicate', 'protected']:
            with self.subTest(kind=kind):
                archive = self.directory / (kind + '.tar.gz')
                with tarfile.open(archive, 'w:gz') as output:
                    name = '../escape' if kind == 'traversal' else 'LocalSettings.php' if kind == 'protected' else 'resources/unsafe'
                    member = tarfile.TarInfo(name)
                    if kind in ['symlink', 'hardlink']:
                        member.type = tarfile.SYMTYPE if kind == 'symlink' else tarfile.LNKTYPE
                        member.linkname = '../outside'
                        output.addfile(member)
                    else:
                        member.mode = 0o644
                        member.size = 1
                        output.addfile(member, io.BytesIO(b'x'))
                        if kind == 'duplicate':
                            output.addfile(member, io.BytesIO(b'x'))
                result = self.receive(self.repo.base, archive, success=False)
                self.assertNotEqual(result.returncode, 0)
                self.assertEqual(tree(self.live), before)
                self.assertFalse((self.directory / 'escape').exists())

    def test_actual_delta_retry_and_explicit_reverse_order_rollback(self):
        self.deploy()
        first_backup = (self.state / 'current-deployment').read_text().strip()
        unchanged = signature(self.live / 'core/unchanged.php')
        write(self.repo.root, 'resources/site.css', 'Synthetic second deployment\n')
        revision = self.repo.commit('Synthetic delta retry and rollback')
        self.deploy(self.repo.base)
        second_backup = (self.state / 'current-deployment').read_text().strip()
        before = tree(self.live)
        backups = sorted(path.name for path in (self.state / 'backups').iterdir())
        self.receive(revision, self.directory / 'shell-2.tar.gz')
        self.assertEqual(tree(self.live), before)
        self.assertEqual(sorted(path.name for path in (self.state / 'backups').iterdir()), backups)
        stale = execute([BASH, HERE / 'rollback.sh', first_backup], env=self.env, success=False)
        self.assertNotEqual(stale.returncode, 0)
        self.assertEqual(tree(self.live), before)
        execute([BASH, HERE / 'rollback.sh', second_backup], env=self.env)
        self.assertEqual((self.state / 'current-sha').read_text().strip(), self.repo.base)
        self.assertEqual((self.live / 'resources/site.css').read_text(), self.repo.initial['resources/site.css'])
        self.assertEqual(signature(self.live / 'core/unchanged.php'), unchanged)

    def test_staging_delta_transforms_only_payload_and_preserves_access_gate(self):
        self.env['EQL_DEPLOY_ENV'] = 'staging'
        # Real staging-prepare selects/re-writes payload files. The host's private
        # access library is replaced only at its explicit STATE boundary, using
        # synthetic rules; no administrator tokens or host paths are accessed.
        write(self.state, 'staging-access-lib.php', '<?php function eql_staging_access_rules(string $base): string { return "# Synthetic administrator access gate\\n" . $base; }\n')
        write(self.repo.root, 'wiki_auth_bridge.php', '<?php\n$url="https://eqlwiki.com/api.php";\n')
        write(self.repo.root, 'skins/EQLImmersive/resources/main.js', 'const wiki="https://www.eqlwiki.com";\n')
        baseline = self.repo.commit('Synthetic staging bridge sources')
        self.deploy()
        bridge = self.live / 'wiki_auth_bridge.php'
        self.assertIn('https://test.eqlwiki.com/api.php', bridge.read_text())
        self.assertEqual(bridge.read_text().count("'/EQLStaging.php'"), 1)
        access = self.live / '.htaccess'
        self.assertIn('Synthetic administrator access gate', access.read_text())
        self.assertEqual((self.state / 'htaccess-base').read_bytes(), (self.repo.root / '.htaccess').read_bytes())
        bridge_before, gate_before = signature(bridge), signature(access)
        write(self.repo.root, 'resources/site.css', 'body { color: purple; }\n')
        self.repo.commit('Synthetic staging CSS only')
        self.deploy(baseline)
        self.assertEqual(signature(bridge), bridge_before)
        self.assertEqual(signature(access), gate_before)
        css_revision = self.repo.git('rev-parse', 'HEAD').decode().strip()
        write(self.repo.root, 'wiki_auth_bridge.php', '<?php\n$url="https://eqlwiki.com/api.php?synthetic=2";\n')
        bridge_revision = self.repo.commit('Synthetic staging bridge only')
        self.deploy(css_revision)
        self.assertEqual(bridge.read_text().count("'/EQLStaging.php'"), 1)
        self.assertIn('https://test.eqlwiki.com/api.php?synthetic=2', bridge.read_text())
        self.assertEqual(signature(access), gate_before)
        bridge_before = signature(bridge)
        backups = sorted(path.name for path in (self.state / 'backups').iterdir())
        self.receive(bridge_revision, self.directory / 'shell-3.tar.gz')
        self.assertEqual(signature(bridge), bridge_before, 'Retry compares the prepared staging bytes, not production source URLs')
        self.assertEqual(sorted(path.name for path in (self.state / 'backups').iterdir()), backups)


if __name__ == '__main__':
    if os.name != 'nt' and not SHELL_READY:
        raise RuntimeError('Linux CI must provide bash, flock, curl and GNU tar for actual receiver integration.')
    unittest.main(verbosity=2)
