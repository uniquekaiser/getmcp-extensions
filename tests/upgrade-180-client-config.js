/* Verify native WordPress sign-in keeps the OAuth URL-only client setup. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const context = { window: {}, console };
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../admin/connection-tools.js'), 'utf8'), context);
function render(auth) {
  let state = 0;
  const React = {
    Fragment: 'fragment',
    createElement: (type, props, ...children) => ({ type, props: props || {}, children }),
    useState: initial => [state++ === 0 ? true : initial, () => {}],
    useEffect: () => {},
  };
  const { CodexConnect } = context.window.createGetMCPConnectionTools({ React, api: async () => ({}) });
  const tree = CodexConnect({ connection: { slug: 'fixture', url: 'https://example.org/mcp', auth_type: auth, auth_header: 'Authorization' } });
  function find(node) {
    if (!node || typeof node !== 'object') return null;
    if (node.type === 'textarea') return node.props.value;
    for (const child of node.children || []) { const found = find(child); if (found !== null) return found; }
    return null;
  }
  function alerts(node) {
    if (!node || typeof node !== 'object') return 0;
    return (node.props?.role === 'alert' ? 1 : 0) + (node.children || []).reduce((count, child) => count + alerts(child), 0);
  }
  return { text: find(tree), alerts: alerts(tree) };
}
const wordpress = render('wordpress');
const oauth = render('oauth');
const bearer = render('bearer');
assert.ok(wordpress.text.includes('url = "https://example.org/mcp"'));
assert.equal(wordpress.text, oauth.text);
assert.ok(!wordpress.text.includes('GETMCP_AUTH_HEADER'));
assert.equal(wordpress.alerts, 0);
assert.ok(bearer.text.includes('GETMCP_TOKEN'));
console.log(JSON.stringify({ passed: 5, fixture: 'synthetic client configuration' }));
