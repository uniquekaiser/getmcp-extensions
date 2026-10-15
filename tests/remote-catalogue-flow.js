/** Exercise the editor's save/authorize contract without contacting any provider. */
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
async function fixture(auth='oauth',catalogue=true,open=true){
  let states=[],cursor=0,effects=[],mounted=false,calls=[],assigned='';
  const draft={kind:'remote-mcp',name:'Fixture catalogue',slug:'',status:'active',allowed_user_ids:[7],remote:{endpoint:'https://mcp.notion.com/mcp',auth_mode:auth,publish_original:false}};
  let fail=true;
  const api=async(path,options={})=>{
    calls.push({path,...options});
    if(path==='connections'&&!options.method)return {servers:[]};
    if(path==='remote-catalogue/notion')return {id:'notion',name:'Fixture catalogue',draft};
    if(path==='connections'&&options.method==='POST'||path==='connections/77'&&options.method==='PUT')return {...options.data,id:77};
    if(path==='my-connections/77'){if(fail)throw Error('Fixture provider offline');return {authorization_url:'https://mcp.notion.com/fixture-consent'};}
    if(path==='connections/77/preview')return {'tools/list':{tools:[]}};
    throw Error('Unexpected fixture request: '+path);
  };
  const React={createElement:(type,props,...children)=>({type,props:props||{},children}),useState:initial=>{const i=cursor++;if(!(i in states))states[i]=initial;return[states[i],v=>states[i]=typeof v==='function'?v(states[i]):v];},useEffect:fn=>{if(!mounted)effects.push(fn);}};
  const context={window:{location:{search:catalogue?'?page=getmcp-connections':'?page=getmcp-remote-servers',hash:open?'#/connections?catalogue=notion':'#/connections',pathname:'/wp-admin/admin.php',assign:url=>assigned=url},history:{replaceState(){}},getmcpRemoteCatalogue:{currentUserId:7},getmcpExtensions:{effective_features:{}},createGetMCPRemoteCatalogue:()=> 'Catalogue'},createOAuthUserPicker:()=> 'Picker',URLSearchParams,TextEncoder,console};
  vm.createContext(context);vm.runInContext(fs.readFileSync('admin/native-connections.js','utf8'),context);
  const UI=Object.fromEntries(['Button','Card','Badge','CopyButton','Input','Select','PageHeader','PageContainer','ConfirmDialog','EmptyState','Skeleton'].map(k=>[k,k]));
  const Page=context.window.createGetMCPConnectionsPage({React,UI,api,useToast:()=>({addToast(){}})});
  const render=()=>{cursor=0;return Page({catalogue});};
  render();mounted=true;effects.forEach(fn=>fn());await new Promise(setImmediate);
  const nodes=tree=>tree&&typeof tree==='object'?[tree,...tree.children.flat(Infinity).flatMap(nodes)]:[];
  const click=async label=>{const node=nodes(render()).find(n=>n.type==='Button'&&n.children.includes(label));assert(node,'Missing button '+label);await node.props.onClick();await new Promise(setImmediate);};
  return {calls,render,nodes,click,recover:()=>fail=false,assigned:()=>assigned};
}
(async()=>{
  const oauth=await fixture();await oauth.click('Save & Authorize');
  let writes=oauth.calls.filter(c=>c.method==='POST'&&c.path==='connections');assert.equal(writes.length,1);
  assert.equal(writes[0].data.status,'active');assert.deepEqual(Array.from(writes[0].data.allowed_user_ids),[7]);assert.equal(writes[0].data.remote.publish_original,false);
  assert(oauth.calls.some(c=>c.path==='my-connections/77'));assert.equal(oauth.assigned(),'');
  assert(oauth.nodes(oauth.render()).some(n=>n.props.role==='alert'&&n.children.some(t=>typeof t==='string'&&t.includes('Saved, but authorization could not start'))));
  oauth.recover();await oauth.click('Save & Authorize');
  assert.equal(oauth.calls.filter(c=>c.method==='POST'&&c.path==='connections').length,1,'Retry must reuse saved ID');
  assert(oauth.calls.some(c=>c.path==='connections/77'&&c.method==='PUT'));assert.equal(oauth.assigned(),'https://mcp.notion.com/fixture-consent');
  const none=await fixture('none');await none.click('Save & Test');assert(none.calls.some(c=>c.path==='connections/77/preview'));assert(!none.calls.some(c=>c.path.startsWith('my-connections/')));
  const denied=await fixture();denied.nodes(denied.render()).find(n=>n.type==='Picker').props.onChange([]);await denied.click('Save & Authorize');assert(!denied.calls.some(c=>c.path==='connections'&&c.method==='POST'),'Removed creator must not be silently reallowed');
  const connections=await fixture('oauth',false,false);assert(connections.nodes(connections.render()).some(n=>n.type==='PageHeader'&&n.props.title==='Gateways & Remote MCP'),'Catalogue page query must not overtake connections route');assert(!connections.nodes(connections.render()).some(n=>n.type==='Catalogue'));
  const catalogue=await fixture('oauth',true,false);assert(catalogue.nodes(catalogue.render()).some(n=>n.type==='Catalogue'),'Catalogue route must work independently of WordPress page query');
  for(const file of ['build/index.js','compat/versions/1.7.0/build/index.js']){
    const source=fs.readFileSync(file,'utf8');assert(source.includes('path:"/remote-servers",element:(0,u.jsx)(nativeConnections,{catalogue:!0})'));assert(source.includes('path:"/connections",element:(0,u.jsx)(nativeConnections,{})'));assert.equal(source.split('key:"remote-servers"').length-1,1,'Native catalogue navigation appears once');
  }
  console.log(JSON.stringify({scenarios_passed:6,failed:[],provider_network:'none; API fixtures only'}));
})().catch(e=>{console.error(e);process.exitCode=1;});
