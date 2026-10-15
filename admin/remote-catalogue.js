/** Owned catalogue with the same React components and tokens as native Templates.
 * @wp-test feature=remote.catalogue scenario=catalogue-browse-draft
 */
window.createGetMCPRemoteCatalogue = function({React, UI, api}) {
  const {createElement:h,useState,useEffect}=React;
  const {Button,Card,Input,Select,PageHeader,PageContainer,Skeleton}=UI;
  return function RemoteCatalogue({onSelect}) {
    const [query,setQuery]=useState(''),[category,setCategory]=useState(''),[page,setPage]=useState(1);
    const [data,setData]=useState(null),[items,setItems]=useState([]),[error,setError]=useState(''),[loading,setLoading]=useState(true),[retry,setRetry]=useState(0);
    useEffect(()=>{
      let current=true;
      const timer=setTimeout(()=>{
        setLoading(true);setError('');
        api('remote-catalogue',{params:{q:query,category,page}}).then(result=>{
          if(!current)return;
          setData(result);setItems(prior=>page===1?result.items:[...prior,...result.items]);
        }).catch(e=>{if(current)setError(e.message||'Catalogue unavailable.');}).finally(()=>{if(current)setLoading(false);});
      },query?250:0);
      return()=>{current=false;clearTimeout(timer);};
    },[query,category,page,retry]);
    return h(PageContainer,null,
      h(PageHeader,{title:'Remote MCP Servers',description:'Connect hosted MCP services with your account, then group them in project gateways.'}),
      h('div',{className:'space-y-6'},
        h('div',{className:'flex flex-wrap gap-4'},
          h(Input,{label:'Search remote MCP servers',type:'search',value:query,placeholder:'Search remote servers...',onChange:e=>{setQuery(e.target.value);setPage(1);}}),
          h(Select,{label:'Remote MCP category',value:category,options:[{value:'',label:'All categories'},...(data?.categories||[]).map(value=>({value,label:value}))],onChange:e=>{setCategory(e.target.value);setPage(1);}})),
        error&&h('div',{role:'alert',className:'rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-600'},error,h(Button,{variant:'outline',onClick:()=>setRetry(v=>v+1)},'Retry catalogue')),
        h('p',{role:'status',className:'text-sm text-gray-500'},loading?'Loading remote servers…':`${data?.total||0} hosted services · Reference checked ${data?.reference_checked||'date unavailable'}. Verify each provider when connecting.`),
        h('p',{className:'text-sm text-gray-500'},'New connections allow your WordPress user by default. Review access before saving. Gateway memberships and original /mcp publication are never added automatically.'),
        loading&&!data?h(Skeleton,{variant:'card'}):h('div',{className:'grid gap-6',style:{gridTemplateColumns:'repeat(auto-fit,minmax(min(100%,280px),1fr))'},'data-remote-catalogue-grid':true},
          ...items.map(entry=>h(Card,{key:entry.id},h('div',{className:'flex flex-col h-full space-y-4','data-remote-catalogue-id':entry.id},
            h('div',{className:'text-xs text-gray-500'},entry.category+' · Hosted MCP'),
            h('h3',{className:'text-base font-semibold text-gray-900'},entry.name),
            h('p',{className:'text-sm text-gray-500 flex-1'},entry.description),
            h('code',{className:'block text-xs text-gray-500 break-all'},entry.endpoint),
            h('p',{className:'text-xs text-gray-400'},({oauth:'Personal OAuth sign-in',bearer:'Shared operator token',none:'No upstream authentication'}[entry.auth])),
            entry.docs&&h('a',{href:entry.docs,target:'_blank',rel:'noopener noreferrer',className:'text-sm text-brand-600 hover:text-brand-700'},'Provider documentation'),
            h(Button,{variant:'primary',disabled:loading,onClick:()=>onSelect(entry.id)},'Connect '+entry.name))))),
        !loading&&!items.length&&h(Card,null,h('p',{className:'text-sm text-gray-500'},'No matching remote servers. Try another search or category.')),
        !error&&data?.has_more&&h(Button,{variant:'outline',disabled:loading,onClick:()=>setPage(p=>p+1)},'Load more remote servers')));
  };
};

// Keep WordPress menu state and reload URLs aligned with the native router.
(() => {
  const config=window.getmcpRemoteCatalogue;if(!config)return;
  const sync=()=>{
    const route=window.location.hash.split('?')[0];
    const page={'#/connections':'getmcp-connections','#/remote-servers':'getmcp-remote-servers'}[route];
    if(!page||!document.getElementById('getmcp-admin-root'))return;
    const url=new URL(window.location.href);
    if(!url.pathname.includes('/wp-admin/'))return;
    if(url.searchParams.get('page')!==page){url.searchParams.set('page',page);window.history.replaceState(window.history.state,'',url);}
    document.querySelectorAll('#toplevel_page_getmcp .wp-submenu li').forEach(item=>{
      const link=item.querySelector('a');if(!link)return;
      const selected=new URL(link.href,window.location.href).searchParams.get('page')===page;
      item.classList.toggle('current',selected);link.classList.toggle('current',selected);
      if(selected)link.setAttribute('aria-current','page');else link.removeAttribute('aria-current');
    });
    document.title=page==='getmcp-remote-servers'?'Remote MCP Servers — GetMCP':'Gateways & Remote MCP — GetMCP';
  };
  window.getmcpSyncConnectionNavigation=()=>requestAnimationFrame(sync);
  window.addEventListener('hashchange',window.getmcpSyncConnectionNavigation);
  window.addEventListener('popstate',window.getmcpSyncConnectionNavigation);sync();
})();
