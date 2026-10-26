#!/usr/bin/env python3
"""Package a complete or base-leased incremental application deployment."""
import argparse
import io
import json
import re
import subprocess
import tarfile
from pathlib import Path

MARKER_PATH = 'skins/EQLImmersive/deployment-version.txt'
MANIFEST_PATH = '.eql-deployment-manifest'
PAYLOAD_PATH = '.eql-deployment-payload'
METADATA_PATH = '.eql-deployment.json'
CONTROL_PATHS = {MANIFEST_PATH, PAYLOAD_PATH, METADATA_PATH}
EXCLUDED = {
    '.gitignore', '.gitattributes', 'PROJECT.md', 'CONTRIBUTING.md',
    'LocalSettings.php', 'BridgeSecrets.php', 'EQLStaging.php', 'bb/Settings.php',
    MARKER_PATH, *CONTROL_PATHS,
}


def release_inputs(root):
    """Read tracked paths/modes and the precise commit being packaged."""
    revision = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=root).decode('ascii').strip()
    if re.fullmatch(r'[0-9a-f]{40}', revision) is None:
        raise RuntimeError('A release requires a full lowercase 40-character Git commit SHA.')
    paths = subprocess.check_output(['git', 'ls-files', '-z'], cwd=root).decode().split('\0')
    for reserved in {MARKER_PATH, *CONTROL_PATHS}:
        if any(path.startswith(reserved + '/') for path in paths):
            raise RuntimeError(f'Reserved deployment marker path is a tracked directory: {reserved}')
    files = sorted(
        path for path in paths
        if path and path not in EXCLUDED
        and not path.startswith(('ops/', '.github/', 'static/eql-icon-index/'))
        and '.example.' not in path
    )
    staged = subprocess.check_output(['git', 'ls-files', '--stage', '-z'], cwd=root).decode().split('\0')
    modes = {
        entry.split('\t', 1)[1]: int(entry.split(' ', 1)[0], 8)
        for entry in staged if entry
    }
    for path in files:
        if (path.startswith(('/', '-')) or '\\' in path
                or any(part in ('', '.', '..') for part in path.split('/'))
                or any(ord(char) < 32 or ord(char) == 127 for char in path)):
            raise RuntimeError(f'Unsafe deployment path: {path!r}')
        if (modes[path] & 0o170000) == 0o120000:
            raise RuntimeError(f'Symlink is not deployable: {path}')
        if (modes[path] & 0o170000) != 0o100000:
            raise RuntimeError(f'Not a regular deployable file: {path}')
    return files, modes, revision


def build_release(root, output, base_revision=None):
    """Build an archive without creating a deployment marker in the checkout."""
    root = Path(root)
    files, modes, revision = release_inputs(root)
    # Never label uncommitted application bytes as HEAD, including full repairs
    # and automatic full fallback when the installed base is unavailable.
    subprocess.check_call(['git', 'diff', '--quiet', 'HEAD', '--'], cwd=root)
    if base_revision is not None:
        if re.fullmatch(r'[0-9a-f]{40}', base_revision) is None:
            raise RuntimeError('The deployment base must be a full lowercase Git commit SHA.')
        verified = subprocess.check_output(
            ['git', 'rev-parse', '--verify', base_revision + '^{commit}'], cwd=root
        ).decode('ascii').strip()
        if verified != base_revision:
            raise RuntimeError('The deployment base must identify an exact available commit.')
        # Compare the installed revision, not github.event.before: missed pushes,
        # retries and explicit rollback can leave the host on a different commit.
        changed = set(subprocess.check_output(
            ['git', 'diff', '--no-renames', '--name-only', '-z', base_revision, revision], cwd=root
        ).decode('utf-8').split('\0'))
        payload = [path for path in files if path in changed]
    else:
        payload = list(files)
    # The receiver copies in manifest order. Activate the new URL only after
    # every application file, including its stylesheet content, is installed.
    managed = files + [MARKER_PATH]
    payload.append(MARKER_PATH)
    metadata = {'protocol': 2, 'revision': revision, 'base_revision': base_revision}
    manifest = root / MANIFEST_PATH
    manifest_created = False
    try:
        # An exclusive temporary manifest cannot overwrite an existing file or symlink.
        with manifest.open('x', encoding='utf-8', newline='\n') as stream:
            manifest_created = True
            stream.write('\n'.join(managed) + '\n')
        with tarfile.open(output, 'w:gz') as archive:
            for path in payload:
                if path == MARKER_PATH:
                    marker = (revision + '\n').encode('ascii')
                    info = tarfile.TarInfo(MARKER_PATH)
                    info.mode = 0o644
                    info.size = len(marker)
                    archive.addfile(info, io.BytesIO(marker))
                    continue
                source = root / path
                mode = modes[path]
                if source.is_symlink() or (mode & 0o170000) == 0o120000:
                    raise RuntimeError(f'Symlink is not deployable: {path}')
                if not source.is_file() or (mode & 0o170000) != 0o100000:
                    raise RuntimeError(f'Not a regular deployable file: {path}')
                info = archive.gettarinfo(str(source), arcname=path)
                info.mode = mode & 0o777
                with source.open('rb') as stream:
                    archive.addfile(info, stream)
            archive.add(manifest, arcname=MANIFEST_PATH)
            for name, data in [
                (PAYLOAD_PATH, ('\n'.join(payload) + '\n').encode('utf-8')),
                (METADATA_PATH, (json.dumps(metadata, sort_keys=True) + '\n').encode('ascii')),
            ]:
                info = tarfile.TarInfo(name)
                info.mode = 0o600
                info.size = len(data)
                archive.addfile(info, io.BytesIO(data))
    finally:
        if manifest_created:
            manifest.unlink()
    return len(managed)


def main(argv=None):
    parser = argparse.ArgumentParser()
    parser.add_argument('--output', default='release.tar.gz')
    source = parser.add_mutually_exclusive_group()
    source.add_argument('--base', dest='base_revision', help='Exact revision reported by receiver status; omit for a full repair bundle')
    source.add_argument('--status-file', help='JSON returned by the installed receiver status command')
    parser.add_argument('--full', action='store_true', help='Use a full repair bundle even when receiver status provides a base')
    args = parser.parse_args(argv)
    root = Path(__file__).resolve().parents[1]
    base = args.base_revision
    if args.full and args.base_revision:
        parser.error('--full cannot be combined with --base')
    if args.status_file:
        status = json.loads(Path(args.status_file).read_text(encoding='utf-8'))
        if not isinstance(status, dict) or status.get('protocol') != 2 or 'revision' not in status:
            raise RuntimeError('Install the reviewed protocol-2 receiver before enabling incremental deployment.')
        installed = status['revision']
        if installed is not None and (not isinstance(installed, str) or re.fullmatch(r'[0-9a-f]{40}', installed) is None):
            raise RuntimeError('Receiver reported an invalid installed revision.')
        if installed is not None and not args.full:
            available = subprocess.run(
                ['git', 'cat-file', '-e', installed + '^{commit}'], cwd=root,
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL
            ).returncode == 0
            if available:
                base = installed
            else:
                print('Installed revision is unavailable in this checkout; packaging a full repair bundle.')
    count = build_release(root, args.output, base)
    with tarfile.open(args.output) as archive:
        payload_count = len(archive.extractfile(PAYLOAD_PATH).read().decode('utf-8').splitlines())
    print(f'Packaged {payload_count} payload files / {count} managed files into {args.output}')


if __name__ == '__main__':
    main()
