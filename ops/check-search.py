#!/usr/bin/env python3
"""Exercise autocomplete against a running wiki with its current page database."""
import argparse
import base64
import json
import netrc
import urllib.parse
import urllib.request

parser = argparse.ArgumentParser()
parser.add_argument('url', help='Wiki root, e.g. http://127.0.0.1:8080')
parser.add_argument('--netrc', help='Private automation credentials for staging')
args = parser.parse_args()
headers = {}
if args.netrc:
    credentials = netrc.netrc(args.netrc).authenticators(urllib.parse.urlparse(args.url).hostname)
    if not credentials:
        parser.error('No matching credentials in netrc')
    headers['Authorization'] = 'Basic ' + base64.b64encode(f'{credentials[0]}:{credentials[2]}'.encode()).decode()


def api(**params):
    query = urllib.parse.urlencode({'format': 'json', **params})
    request = urllib.request.Request(args.url.rstrip('/') + '/api.php?' + query, headers=headers)
    with urllib.request.urlopen(request, timeout=30) as response:
        data = json.load(response)
    assert not isinstance(data, dict) or 'error' not in data, 'API returned an error'
    return data


def suggestions(query):
    return api(action='opensearch', search=query, limit=10)[1]


guards = suggestions('guard A')
assert 'Guard Abbilash' in guards, 'Expected current wiki fixture Guard Abbilash'
for query in ['guard a', 'GUARD A', 'GuArD a']:
    assert suggestions(query) == guards, f'Case-dependent results: {query}'
print('PASS partial second word and mixed case return identical guard suggestions')
assert suggestions('cloa') == suggestions('Cloa') and suggestions('cloa'), 'Partial first word failed'
print('PASS partial first word')
assert suggestions('guard abbilash')[0] == 'Guard Abbilash', 'Full lowercase title failed'
assert suggestions('Guard Abbilash')[0] == 'Guard Abbilash', 'Exact title failed'
print('PASS full titles and exact match')
templates = suggestions('Template:ite')
assert templates and all(title.startswith('Template:') for title in templates), 'Namespace filtering failed'
assert suggestions('Template:ITE') == templates, 'Namespace title case mismatch'
assert suggestions('Special:UserL'), 'Special-page fallback failed'
print('PASS namespaces and native special-page completion')
assert suggestions('guard%') == [], 'Percent became a SQL wildcard'
assert suggestions("guard' OR 1=1 --") == [], 'Punctuation became SQL syntax'
assert suggestions('zzzzEQLSearchNonexistent') == [], 'Nonexistent prefix returned results'
print('PASS punctuation and nonexistent prefixes')
pages = api(action='query', list='prefixsearch', pssearch='guard a', pslimit=3)['query']['prefixsearch']
next_pages = api(action='query', list='prefixsearch', pssearch='guard a', pslimit=3, psoffset=3)['query']['prefixsearch']
assert len(pages) == 3 and len(next_pages) == 3, 'Prefix-search pagination failed'
assert not {page['pageid'] for page in pages} & {page['pageid'] for page in next_pages}, 'Repeated results across pages'
print('PASS API prefix-search pagination')
assert api(action='query', list='search', srsearch='cloak')['query']['search'], 'Full-text search failed'
print('PASS native full-text search')
