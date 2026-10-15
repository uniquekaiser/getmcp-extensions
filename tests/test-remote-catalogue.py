"""Importer contracts: data-only parsing, safe defaults and bounded input types."""
import importlib.util, tempfile, unittest
from pathlib import Path
spec=importlib.util.spec_from_file_location('catalogue_import',Path(__file__).resolve().parents[1]/'tools/import-remote-catalogue.py')
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
class CatalogueTests(unittest.TestCase):
    def test_url_refusals(self):
        for url in ['http://example.net/mcp','https://localhost/mcp','https://127.0.0.1/mcp','https://[::1]/mcp','https://user:pass@example.net/mcp','https://example.net/mcp?key=secret','https://example.net/{tenant}/mcp','https://example.net/sse']:
            with self.subTest(url=url),self.assertRaises(ValueError):module.public_url(url)
    def test_endpoint_case_and_slash(self):
        self.assertEqual(module.public_url('https://Example.NET/MCP/'),'https://example.net/MCP')
        self.assertNotEqual(module.public_url('https://example.net/mcp'),module.public_url('https://example.net/MCP'))
    def test_curated_precedence(self):
        first={'id':'one','name':'First','endpoint':'https://example.net/mcp/','auth':'oauth'}
        second={**first,'id':'two','name':'Second','endpoint':'https://example.net/mcp'}
        entries,excluded=module.build([('curated',[first]),('official',[second])]);self.assertEqual(len(entries),1);self.assertEqual(entries[0]['name'],'First');self.assertEqual(excluded,[])
    def test_no_app_registration_guess(self):
        entries,excluded=module.build([('reviewed',[{'id':'app','endpoint':'https://example.net/mcp','check':'oauth-app'}])]);self.assertEqual(entries,[]);self.assertEqual(len(excluded),1)
    def test_literal_only(self):
        with tempfile.TemporaryDirectory() as d:
            p=Path(d)/'catalogue.ts';p.write_text("const CURATED: readonly Entry[] = [\n  { id: process.exit(), name: 'bad' },\n];")
            with self.assertRaises(ValueError):module.literal_entries(p,'CURATED')
    def test_literal_strings(self):
        with tempfile.TemporaryDirectory() as d:
            p=Path(d)/'catalogue.ts';p.write_text("const CURATED: readonly Entry[] = [\n  { id: 'test', name: 'A \\'quote\\' safely' },\n];")
            self.assertEqual(module.literal_entries(p,'CURATED')[0]['name'],"A 'quote' safely")
if __name__=='__main__':unittest.main()
