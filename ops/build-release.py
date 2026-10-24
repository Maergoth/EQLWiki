#!/usr/bin/env python3
"""Package tracked application files and a managed deployment asset marker."""
import argparse
import io
import re
import subprocess
import tarfile
from pathlib import Path

MARKER_PATH = 'skins/EQLImmersive/deployment-version.txt'
MANIFEST_PATH = '.eql-deployment-manifest'
EXCLUDED = {
    '.gitignore', '.gitattributes', 'PROJECT.md', 'CONTRIBUTING.md',
    'LocalSettings.php', 'BridgeSecrets.php', 'EQLStaging.php', 'bb/Settings.php',
    MARKER_PATH, MANIFEST_PATH,
}


def release_inputs(root):
    """Read tracked paths/modes and the precise commit being packaged."""
    revision = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=root).decode('ascii').strip()
    if re.fullmatch(r'[0-9a-f]{40}', revision) is None:
        raise RuntimeError('A release requires a full lowercase 40-character Git commit SHA.')
    paths = subprocess.check_output(['git', 'ls-files', '-z'], cwd=root).decode().split('\0')
    if any(path.startswith(MARKER_PATH + '/') for path in paths):
        raise RuntimeError(f'Reserved deployment marker path is a tracked directory: {MARKER_PATH}')
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
    return files, modes, revision


def build_release(root, output):
    """Build an archive without creating a deployment marker in the checkout."""
    root = Path(root)
    files, modes, revision = release_inputs(root)
    # The receiver copies in manifest order. Activate the new URL only after
    # every application file, including its stylesheet content, is installed.
    managed = files + [MARKER_PATH]
    manifest = root / MANIFEST_PATH
    manifest_created = False
    try:
        # An exclusive temporary manifest cannot overwrite an existing file or symlink.
        with manifest.open('x', encoding='utf-8', newline='\n') as stream:
            manifest_created = True
            stream.write('\n'.join(managed) + '\n')
        with tarfile.open(output, 'w:gz') as archive:
            for path in managed:
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
    finally:
        if manifest_created:
            manifest.unlink()
    return len(managed)


def main(argv=None):
    parser = argparse.ArgumentParser()
    parser.add_argument('--output', default='release.tar.gz')
    args = parser.parse_args(argv)
    count = build_release(Path(__file__).resolve().parents[1], args.output)
    print(f'Packaged {count} application files into {args.output}')


if __name__ == '__main__':
    main()
