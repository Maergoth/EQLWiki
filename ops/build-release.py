#!/usr/bin/env python3
"""Package tracked application files, excluding private settings and host tooling."""
import argparse
import subprocess
import tarfile
from pathlib import Path

parser = argparse.ArgumentParser()
parser.add_argument('--output', default='release.tar.gz')
args = parser.parse_args()
root = Path(__file__).resolve().parents[1]
paths = subprocess.check_output(['git', 'ls-files', '-z'], cwd=root).decode().split('\0')
excluded = {'.gitignore', '.gitattributes', 'PROJECT.md', 'CONTRIBUTING.md', 'LocalSettings.php', 'BridgeSecrets.php', 'EQLStaging.php', 'bb/Settings.php'}
files = sorted(p for p in paths if p and p not in excluded and not p.startswith(('ops/', '.github/', 'static/eql-icon-index/')) and '.example.' not in p)
staged = subprocess.check_output(['git', 'ls-files', '--stage', '-z'], cwd=root).decode().split('\0')
modes = {entry.split('\t', 1)[1]: int(entry.split(' ', 1)[0], 8) & 0o777 for entry in staged if entry}
manifest = root / '.eql-deployment-manifest'
manifest.write_text('\n'.join(files) + '\n', encoding='utf-8', newline='\n')
try:
    with tarfile.open(args.output, 'w:gz') as archive:
        for path in files:
            source = root / path
            if source.is_symlink():
                raise RuntimeError(f'Symlink is not deployable: {path}')
            info = archive.gettarinfo(str(source), arcname=path)
            info.mode = modes[path]
            with source.open('rb') as stream:
                archive.addfile(info, stream)
        archive.add(manifest, arcname='.eql-deployment-manifest')
finally:
    manifest.unlink()
print(f'Packaged {len(files)} application files into {args.output}')
