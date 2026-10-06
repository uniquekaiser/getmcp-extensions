/** Adapter for the captured distribution only. Upstream should inject source imports. */
window.registerGetMCPConnectionRuntime = function(requireModule) {
  const tools = window.createGetMCPConnectionTools({React:requireModule(1609),api:requireModule(2854).AT});
  window.GetMCPExtensionsCodexConnect = tools.CodexConnect;
  window.GetMCPExtensionsProjectGateways = tools.ProjectGateways;
  window.loadGetMCPExtensionsPage = async function() { await window.loadGetMCPConnectionPage(); return window.createGetMCPExtensionsPage({React:requireModule(1609),useToast:requireModule(7942).dj,UI:{Button:requireModule(891).A,Card:requireModule(6929).A,PageHeader:requireModule(2711).A,PageContainer:requireModule(1868).A,Badge:requireModule(8938).A}}); };
  window.loadGetMCPConnectionPage = async function() {
    await Promise.all([866, 425, 859, 879].map(chunk => requireModule.e(chunk)));
    const component = id => requireModule(id).A;
    return window.createGetMCPConnectionsPage({
      React: requireModule(1609), api: requireModule(2854).AT, useToast: requireModule(7942).dj,
      UI: {Button: component(891), Card: component(6929), Badge: component(8938),
        CopyButton: component(2772), Input: component(7539), Select: component(1327),
        PageHeader: component(2711), PageContainer: component(1868),
        ConfirmDialog: component(4783), EmptyState: component(2853), Skeleton: component(7326)}
    });
  };
  window.mountGetMCPConnectionPortal = async function() {
    const target = document.getElementById('getmcp-native-connections');
    if (!target) return;
    try {
      const Page = await window.loadGetMCPConnectionPage();
      const React = requireModule(1609);
      requireModule(5338).H(target).render(React.createElement(requireModule(7942).tE, null, React.createElement(Page, {portal: true})));
    } catch (_) { target.textContent = 'Could not load My MCP Connections. Reload this page to try again.'; }
  };
};
