#!/usr/bin/env python3
"""Synthetic archive contracts; no host, credentials or full repository package."""
import importlib.util
import sys
import tarfile
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

sys.dont_write_bytecode = True
SPEC = importlib.util.spec_from_file_location('eql_build_release', Path(__file__).with_name('build-release.py'))
RELEASE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(RELEASE)
SHA_ONE = '1' * 40
SHA_TWO = '2' * 40


class ReleaseAssetTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='eql-release-fixture-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.files = {
            'index.php': (b'<?php // Synthetic fixture\n', 0o100644),
            'resources/site.css': (b'body { color: red; }\n', 0o100644),
            'application-command.sh': (b'#!/bin/sh\n', 0o100755),
            RELEASE.MARKER_PATH: (b'Accidental tracked marker must not be published.\n', 0o100644),
            'LocalSettings.php': (b'private synthetic settings', 0o100644),
            'BridgeSecrets.php': (b'private synthetic bridge', 0o100644),
            'bb/Settings.php': (b'private synthetic forum', 0o100644),
            'ops/host-helper.php': (b'private synthetic host tool', 0o100644),
            '.github/workflows/example.yml': (b'synthetic workflow', 0o100644),
            'static/eql-icon-index/cache.json': (b'{}', 0o100644),
            'LocalSettings.example.php': (b'synthetic example', 0o100644),
        }
        for name, (data, _) in self.files.items():
            target = self.root / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(data)

    def git(self, revision):
        def command(arguments, cwd):
            self.assertEqual(Path(cwd), self.root)
            if arguments == ['git', 'rev-parse', 'HEAD']:
                return (revision + '\n').encode('ascii')
            if arguments == ['git', 'ls-files', '-z']:
                return ('\0'.join(self.files) + '\0').encode()
            if arguments == ['git', 'ls-files', '--stage', '-z']:
                return ('\0'.join(f'{mode:o} {"0" * 40} 0\t{name}' for name, (_, mode) in self.files.items()) + '\0').encode()
            raise AssertionError(f'Unexpected Git command: {arguments}')
        return patch.object(RELEASE.subprocess, 'check_output', side_effect=command)

    def package(self, revision, filename='release.tar.gz'):
        target = self.root / filename
        with self.git(revision):
            count = RELEASE.build_release(self.root, target)
        self.assertFalse((self.root / RELEASE.MANIFEST_PATH).exists())
        with tarfile.open(target) as archive:
            names = archive.getnames()
            self.assertEqual(names[-2:], [RELEASE.MARKER_PATH, RELEASE.MANIFEST_PATH])
            self.assertEqual(names[:-2], sorted(names[:-2]))
            result = {member.name: (archive.extractfile(member).read(), member.mode) for member in archive}
        self.assertEqual(count, len(result) - 1)
        return result

    def test_managed_marker_modes_and_private_exclusions(self):
        original = (self.root / RELEASE.MARKER_PATH).read_bytes()
        result = self.package(SHA_ONE)
        self.assertEqual(set(result), {'index.php', 'resources/site.css', 'application-command.sh', RELEASE.MARKER_PATH, RELEASE.MANIFEST_PATH})
        self.assertEqual(result[RELEASE.MARKER_PATH], ((SHA_ONE + '\n').encode('ascii'), 0o644))
        self.assertEqual(result['application-command.sh'][1], 0o755)
        self.assertEqual(result['resources/site.css'][1], 0o644)
        manifest = result[RELEASE.MANIFEST_PATH][0].decode().splitlines()
        self.assertEqual(manifest[-1], RELEASE.MARKER_PATH)
        self.assertEqual(manifest[:-1], sorted(set(result) - {RELEASE.MANIFEST_PATH, RELEASE.MARKER_PATH}))
        self.assertEqual(manifest.count(RELEASE.MARKER_PATH), 1)
        self.assertEqual((self.root / RELEASE.MARKER_PATH).read_bytes(), original)

    def test_marker_requires_no_worktree_file(self):
        del self.files[RELEASE.MARKER_PATH]
        (self.root / RELEASE.MARKER_PATH).unlink()
        result = self.package(SHA_ONE)
        self.assertEqual(result[RELEASE.MARKER_PATH][0], (SHA_ONE + '\n').encode('ascii'))
        self.assertFalse((self.root / RELEASE.MARKER_PATH).exists())

    def test_revision_changes_redeploy_and_managed_rollback(self):
        first = self.package(SHA_ONE, 'old.tar.gz')
        same = self.package(SHA_ONE, 'same.tar.gz')
        second = self.package(SHA_TWO, 'new.tar.gz')
        self.assertEqual(first[RELEASE.MARKER_PATH], same[RELEASE.MARKER_PATH])
        self.assertNotEqual(first[RELEASE.MARKER_PATH], second[RELEASE.MARKER_PATH])
        # The existing receiver/rollback restores every manifest-managed file.
        installed = self.root / 'synthetic-installed-marker.txt'
        installed.write_bytes(second[RELEASE.MARKER_PATH][0])
        installed.write_bytes(first[RELEASE.MARKER_PATH][0])
        self.assertEqual(installed.read_bytes(), (SHA_ONE + '\n').encode('ascii'))
        self.assertIn(RELEASE.MARKER_PATH, first[RELEASE.MANIFEST_PATH][0].decode().splitlines())

    def test_malformed_sha_never_creates_manifest(self):
        for revision in ['', 'main', 'a' * 39, 'a' * 64, 'A' * 40, 'g' * 40, SHA_ONE + '\nextra']:
            with self.subTest(revision=revision), self.git(revision):
                with self.assertRaises(RuntimeError):
                    RELEASE.build_release(self.root, self.root / 'invalid.tar.gz')
                self.assertFalse((self.root / RELEASE.MANIFEST_PATH).exists())

    def test_existing_manifest_is_preserved(self):
        existing = self.root / RELEASE.MANIFEST_PATH
        existing.write_bytes(b'Keep this pre-existing file.\n')
        with self.git(SHA_ONE), self.assertRaises(FileExistsError):
            RELEASE.build_release(self.root, self.root / 'conflict.tar.gz')
        self.assertEqual(existing.read_bytes(), b'Keep this pre-existing file.\n')

    def test_marker_directory_conflict_is_rejected(self):
        self.files[RELEASE.MARKER_PATH + '/conflict.txt'] = (b'synthetic conflict', 0o100644)
        with self.git(SHA_ONE), self.assertRaisesRegex(RuntimeError, 'Reserved deployment marker path'):
            RELEASE.build_release(self.root, self.root / 'directory-conflict.tar.gz')
        self.assertFalse((self.root / RELEASE.MANIFEST_PATH).exists())

    def test_accidental_tracked_marker_symlink_mode_is_not_published(self):
        self.files[RELEASE.MARKER_PATH] = (b'synthetic accidental link target', 0o120000)
        result = self.package(SHA_ONE)
        self.assertEqual(result[RELEASE.MARKER_PATH], ((SHA_ONE + '\n').encode('ascii'), 0o644))

    def test_index_symlink_is_refused_and_manifest_cleaned(self):
        self.files['index.php'] = (b'not a regular indexed file', 0o120000)
        with self.git(SHA_ONE), self.assertRaisesRegex(RuntimeError, 'Symlink is not deployable'):
            RELEASE.build_release(self.root, self.root / 'symlink.tar.gz')
        self.assertFalse((self.root / RELEASE.MANIFEST_PATH).exists())

    def test_worktree_symlink_is_refused_and_manifest_cleaned(self):
        source = self.root / 'resources/site.css'
        source.unlink()
        try:
            source.symlink_to(self.root / 'index.php')
        except OSError as error:
            self.skipTest(f'This runtime does not permit creating fixture symlinks: {error}')
        with self.git(SHA_ONE), self.assertRaisesRegex(RuntimeError, 'Symlink is not deployable'):
            RELEASE.build_release(self.root, self.root / 'worktree-symlink.tar.gz')
        self.assertFalse((self.root / RELEASE.MANIFEST_PATH).exists())


if __name__ == '__main__':
    unittest.main()
