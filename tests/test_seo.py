"""Validate indexable public content while keeping administration out of crawling."""
from html.parser import HTMLParser
from pathlib import Path
import unittest
from urllib.robotparser import RobotFileParser
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
CANONICAL = 'https://adm-service-nsk.ru/'


class Head(HTMLParser):
    def __init__(self, html):
        super().__init__()
        self.robots = []
        self.canonicals = []
        self.feed(html)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'meta' and attrs.get('name', '').lower() == 'robots':
            self.robots.append(attrs.get('content', '').lower())
        if tag == 'link' and attrs.get('rel', '').lower() == 'canonical':
            self.canonicals.append(attrs.get('href'))


class SEOTests(unittest.TestCase):
    def test_public_page_has_one_indexable_canonical(self):
        for folder in ('public', 'dist'):
            with self.subTest(folder=folder):
                head = Head((ROOT / folder / 'index.html').read_text(encoding='utf-8'))
                self.assertEqual(head.canonicals, [CANONICAL])
                self.assertEqual(head.robots, ['index,follow'])

    def test_crawlers_can_reach_public_content_and_sitemap(self):
        for folder in ('public', 'dist'):
            with self.subTest(folder=folder):
                parser = RobotFileParser()
                parser.parse((ROOT / folder / 'robots.txt').read_text(encoding='utf-8').splitlines())
                self.assertEqual(parser.site_maps(), [CANONICAL + 'sitemap.xml'])
                for agent in ('Googlebot', 'YandexBot', '*'):
                    self.assertTrue(parser.can_fetch(agent, CANONICAL))
                    self.assertTrue(parser.can_fetch(agent, CANONICAL + 'styles.css'))
                    self.assertTrue(parser.can_fetch(agent, CANONICAL + 'sitemap.xml'))
                    self.assertFalse(parser.can_fetch(agent, CANONICAL + 'admin/'))
                    self.assertFalse(parser.can_fetch(agent, CANONICAL + 'api.php?action=me'))

    def test_sitemap_contains_only_public_canonical_urls(self):
        namespace = {'s': 'http://www.sitemaps.org/schemas/sitemap/0.9'}
        for folder in ('public', 'dist'):
            with self.subTest(folder=folder):
                tree = ET.parse(ROOT / folder / 'sitemap.xml')
                locations = [node.text for node in tree.findall('s:url/s:loc', namespace)]
                self.assertEqual(locations, [CANONICAL])
                self.assertEqual(tree.find('s:url/s:lastmod', namespace).text, '2026-10-21')

    def test_admin_retains_noindex(self):
        admin = (ROOT / 'public/admin/index.php').read_text(encoding='utf-8')
        self.assertIn("header('X-Robots-Tag: noindex, nofollow')", admin)
        self.assertIn('noindex,nofollow', admin)


if __name__ == '__main__':
    unittest.main(verbosity=2)
