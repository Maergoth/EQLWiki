#!/usr/bin/env python3
"""Compare a numbered PNG library to wiki uploads without changing the wiki.

Writes a complete manifest, missing-only import directory, and a conflict report.
Existing files are never overwritten; numeric IDs remain independent of deduplication.
"""
import argparse
import csv
import hashlib
import io
import json
import re
import shutil
import urllib.parse
import urllib.request
from collections import defaultdict
from pathlib import Path

from PIL import Image


def pixels(data):
    with Image.open(io.BytesIO(data)) as image:
        image.load()
        if image.format != 'PNG' or image.size != (40, 40):
            raise ValueError(f'Expected a 40x40 PNG, got {image.format} {image.size}')
        rgba = bytearray(image.convert('RGBA').tobytes())
        for i in range(0, len(rgba), 4):
            if rgba[i + 3] == 0:
                rgba[i:i + 3] = b'\0\0\0'
        return hashlib.sha256(rgba).hexdigest()


def request(url):
    req = urllib.request.Request(url, headers={'User-Agent': 'EQLWiki-icon-audit/1.0'})
    with urllib.request.urlopen(req, timeout=60) as response:
        return response.read()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('library', type=Path)
    parser.add_argument('--api', default='https://eqlwiki.com/api.php')
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    args.output.mkdir(parents=True, exist_ok=True)
    groups = defaultdict(list)
    manifest = []
    for path in sorted(args.library.glob('*.png'), key=lambda p: int(p.stem)):
        if not re.fullmatch(r'[1-9][0-9]*', path.stem):
            raise ValueError(f'Invalid icon ID filename: {path.name}')
        data = path.read_bytes()
        row = {'id': int(path.stem), 'filename': f'Item_{path.stem}.png',
               'sha1': hashlib.sha1(data).hexdigest(), 'pixels': pixels(data)}
        manifest.append(row)
        groups[row['pixels']].append(row['id'])
    wiki = {}
    params = dict(action='query', list='allimages', aiprefix='Item_',
                  aiprop='sha1|size|url', ailimit=500, format='json')
    while True:
        data = json.loads(request(args.api + '?' + urllib.parse.urlencode(params)))
        if 'error' in data:
            raise RuntimeError(data['error'])
        for image in data['query']['allimages']:
            match = re.fullmatch(r'Item_(\d+)\.png', image['name'])
            if match:
                wiki[int(match[1])] = image
        if 'continue' not in data:
            break
        params.update(data['continue'])
    missing = args.output / 'missing'
    missing.mkdir(exist_ok=True)
    existing_dir = args.output / 'existing'
    existing_dir.mkdir(exist_ok=True)
    counts = defaultdict(int)
    for row in manifest:
        image = wiki.get(row['id'])
        if image is not None:
            row['existing_sha1'] = image['sha1']
        if image is None:
            row['status'] = 'missing'
            shutil.copyfile(args.library / f"{row['id']}.png", missing / row['filename'])
        elif row['sha1'] == image['sha1']:
            row['status'] = 'identical-file'
        else:
            cached = existing_dir / (image['sha1'] + '.png')
            if not cached.exists():
                name_hash = hashlib.md5(image['name'].encode()).hexdigest()
                local_upload = (args.library.parent / 'images' / name_hash[0] /
                                name_hash[:2] / image['name'])
                if (local_upload.is_file() and
                        hashlib.sha1(local_upload.read_bytes()).hexdigest() == image['sha1']):
                    shutil.copyfile(local_upload, cached)
                else:
                    cached.write_bytes(request(image['url']))
            try:
                row['status'] = ('identical-pixels' if pixels(cached.read_bytes()) == row['pixels']
                                 else 'conflict')
            except ValueError:
                row['status'] = 'conflict'
            row['existing_url'] = image['url']
        counts[row['status']] += 1
    (args.output / 'manifest.json').write_text(json.dumps(manifest, indent=2), encoding='utf-8')
    with (args.output / 'conflicts.csv').open('w', newline='', encoding='utf-8') as stream:
        writer = csv.DictWriter(stream, fieldnames=['id', 'filename', 'existing_url'])
        writer.writeheader()
        writer.writerows({k: row.get(k, '') for k in writer.fieldnames}
                        for row in manifest if row['status'] == 'conflict')
    summary = dict(total=len(manifest), unique_pixels=len(groups),
                   duplicate_ids=len(manifest) - len(groups), **counts)
    (args.output / 'summary.json').write_text(json.dumps(summary, indent=2), encoding='utf-8')
    (args.output / 'duplicate-groups.json').write_text(
        json.dumps([ids for ids in groups.values() if len(ids) > 1]), encoding='utf-8')
    print(json.dumps(summary, indent=2), flush=True)


if __name__ == '__main__':
    main()
