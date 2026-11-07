#!/usr/bin/env python3
"""Exercise the checked-in SEO rewrite rules with synthetic request fixtures.

This deliberately supports only the small RewriteCond/RewriteRule subset used
by the SEO block. It reads the actual rules and fails on unsupported syntax.
It is a source regression check, not an Apache/LiteSpeed or live-login test.
"""

from pathlib import Path
import re
import unittest
from urllib.parse import unquote


ROOT = Path(__file__).resolve().parent.parent
BASE = (ROOT / ".htaccess").read_text(encoding="utf-8")
SEO = BASE.split("# EQL_SEO_ROUTING_BEGIN\n", 1)[1].split(
    "# EQL_SEO_ROUTING_END", 1
)[0]
STAGING = (ROOT / "ops/staging.htaccess").read_text(encoding="utf-8")
STAGING_REDIRECT = STAGING.split("AuthType Basic", 1)[0]


def rules(source):
    """Parse the narrow, explicit rule subset; do not silently ignore changes."""
    pending = []
    result = []
    for line in source.splitlines():
        line = line.strip()
        if not line or line.startswith("#") or line == "RewriteEngine On":
            continue
        condition = re.fullmatch(
            r"RewriteCond %\{([A-Z_]+)\} (\S+)(?: \[(NC)\])?", line
        )
        if condition:
            pending.append(condition.groups())
            continue
        rule = re.fullmatch(r"RewriteRule (\S+) (\S+) \[([^]]+)\]", line)
        if not rule:
            raise AssertionError(f"Unsupported SEO rewrite syntax: {line}")
        pattern, target, raw_flags = rule.groups()
        flags = raw_flags.split(",")
        for flag in flags:
            if flag not in {"L", "NE"} and not re.fullmatch(r"R=30[128]", flag):
                raise AssertionError(f"Unsupported SEO rewrite flag: {flag}")
        if "L" not in flags:
            raise AssertionError("SEO routes must stop before the wiki catchall")
        if "%{REQUEST_URI}" in target and "NE" not in flags:
            raise AssertionError("Escaped article paths must not be double-encoded")
        result.append((pending, pattern, target, flags))
        pending = []
    if pending:
        raise AssertionError("RewriteCond without a following RewriteRule")
    return result


def route(source, host, uri="/Bone_Bladed_Claymore", https=False):
    """Return (HTTP status or 0 for internal rewrite, target), or no SEO rule."""
    path, separator, query = uri.partition("?")
    variables = {"HTTP_HOST": host, "REQUEST_URI": path, "HTTPS": "on" if https else "off"}
    for conditions, pattern, target, flags in rules(source):
        matches = True
        for variable, expected, nocase in conditions:
            value = variables[variable]
            if expected.startswith("!="):
                passed = value != expected[2:]
            elif expected.startswith("="):
                passed = value == expected[1:]
            else:
                negate = expected.startswith("!")
                regex = expected[1:] if negate else expected
                passed = bool(re.search(regex, value, re.I if nocase else 0)) != negate
            matches = matches and passed
        if not matches or not re.search(pattern, unquote(path).lstrip("/")):
            continue
        target = re.sub(r"%\{([A-Z_]+)\}", lambda match: variables[match[1]], target)
        status = next((int(flag[2:]) for flag in flags if flag.startswith("R=")), 0)
        if status and target.startswith("/"):
            target = f"{'https' if https else 'http'}://{host}{target}"
        if separator:
            target += "?" + query
        return status, target
    return None


class SeoRoutingTest(unittest.TestCase):
    def test_known_hosts_upgrade_without_changing_environment(self):
        for source in (SEO, STAGING_REDIRECT + SEO):
            for host, canonical in (
                ("eqlwiki.com", "eqlwiki.com"),
                ("www.eqlwiki.com", "eqlwiki.com"),
                ("test.eqlwiki.com", "test.eqlwiki.com"),
                ("EQLWIKI.COM", "eqlwiki.com"),
                ("eqlwiki.com:80", "eqlwiki.com"),
                ("test.eqlwiki.com:80", "test.eqlwiki.com"),
            ):
                with self.subTest(host=host, staging=source != SEO):
                    self.assertEqual(route(source, host), (308, f"https://{canonical}/Bone_Bladed_Claymore"))

    def test_secure_apex_and_staging_do_not_loop(self):
        for source in (SEO, STAGING_REDIRECT + SEO):
            for host in ("eqlwiki.com", "test.eqlwiki.com", "eqlwiki.com:443", "test.eqlwiki.com:443"):
                with self.subTest(host=host):
                    self.assertIsNone(route(source, host, https=True))
        self.assertEqual(route(SEO, "www.eqlwiki.com", https=True), (308, "https://eqlwiki.com/Bone_Bladed_Claymore"))

    def test_local_and_unrecognized_hosts_keep_their_urls(self):
        for source in (SEO, STAGING_REDIRECT + SEO):
            for host in ("localhost", "127.0.0.1:8080", "wiki.local", "other.eqlwiki.com", "eqlwiki.com.example.org", "test.eqlwiki.com.example.org"):
                with self.subTest(host=host):
                    self.assertIsNone(route(source, host))

    def test_certificate_challenges_do_not_redirect(self):
        for source in (SEO, STAGING_REDIRECT + SEO):
            for host in ("eqlwiki.com", "www.eqlwiki.com", "test.eqlwiki.com"):
                self.assertIsNone(route(source, host, "/.well-known/acme-challenge/synthetic-token"))

    def test_escaped_paths_and_queries_are_preserved(self):
        uri = "/Staff_of_Elemental_Mastery%3A_Water%27s?oldid=42&returnto=Foo%26Bar"
        for host in ("eqlwiki.com", "test.eqlwiki.com"):
            self.assertEqual(route(SEO, host, uri), (308, f"https://{host}{uri}"))

    def test_wiki_forum_and_bridge_posts_use_method_preserving_status(self):
        # 308 is the protocol's permanent redirect that retains method/body.
        for host in ("eqlwiki.com", "www.eqlwiki.com", "test.eqlwiki.com"):
            for uri in ("/index.php?title=Special:UserLogin", "/bb/index.php?action=post2", "/wiki_session_bridge.php", "/bb/eql_inline_image_upload.php"):
                self.assertEqual(route(SEO, host, uri)[0], 308)

    def test_robots_routes_in_each_environment(self):
        for host in ("eqlwiki.com", "test.eqlwiki.com", "localhost"):
            self.assertEqual(route(SEO, host, "/robots.txt", https=True), (0, "robots.php"))

    def test_sitemap_alias_only_exists_on_production(self):
        self.assertEqual(route(SEO, "eqlwiki.com", "/sitemap.xml", https=True), (302, "https://eqlwiki.com/rest.php/site/v1/sitemap/0"))
        self.assertIsNone(route(SEO, "test.eqlwiki.com", "/sitemap.xml", https=True))
        self.assertIsNone(route(SEO, "localhost", "/sitemap.xml"))

    def test_ifmodule_containers_are_balanced(self):
        self.assertEqual(len(re.findall(r"<IfModule\b", BASE)), BASE.count("</IfModule>"))


if __name__ == "__main__":
    unittest.main()
