<?php
/** Local-only WordPress integration. No upstream HTTP or account authorization. */
if ( ! defined('GETMCP_TELEGRAM_TESTING') || ! GETMCP_TELEGRAM_TESTING || wp_get_environment_type() !== 'local' ) { throw new RuntimeException('Owned local fixture required.'); }
use GetMCPExtensions\RemoteCatalogue as Catalogue;
use GetMCP\Core\{ServerManager,ToolManager};
use GetMCP\Gateway\FeatureManager;
use GetMCP\Auth\FirstPartyOAuth;
$passed=0;$assert=static function($ok,$label)use(&$passed){if(!$ok)throw new RuntimeException($label);$passed++;};
$admin=get_user_by('login','fixture-admin');$other=get_user_by('login','fixture-other');
$req=static function($route){return rest_do_request(new WP_REST_Request('GET','/getmcp/v1/'.$route));};
$http=static function(){throw new RuntimeException('Catalogue browsing must not make upstream HTTP requests.');};add_filter('pre_http_request',$http,-999,3);
wp_set_current_user(0);$assert(in_array($req('remote-catalogue')->get_status(),[401,403],true),'anonymous catalogue refused');
wp_set_current_user($other->ID);$assert($req('remote-catalogue')->get_status()===403,'subscriber refused');
wp_set_current_user($admin->ID);$response=$req('remote-catalogue');$d=$response->get_data();
// The selected adapter must invalidate old main bundles even within one release.
wp_enqueue_script('getmcp-admin','https://example.invalid/stale.js',[],'stale',true);
GetMCPExtensions\Runtime::assets();$main=wp_scripts()->registered['getmcp-admin'];
$bundle=GETMCP_EXTENSIONS_PATH.'compat/versions/1.7.0/build/index.js';
$assert($main->src===GETMCP_EXTENSIONS_URL.'compat/versions/1.7.0/build/index.js','current adapter source');
$assert($main->ver===substr(hash_file('sha256',$bundle),0,16),'current adapter content version');
$assert($response->get_status()===200&&$d['total']===274,'catalogue count');$assert(count($d['items'])===12&&$d['has_more'],'bounded page');
$assert(count(Catalogue::search('notion')['items'])>=1,'catalogue search');$assert(Catalogue::search('', 'Database')['total']>0,'category search');
$assert(Catalogue::get('unknown')===null&&$req('remote-catalogue/unknown')->get_status()===404,'unknown ID refusal');
$all=Catalogue::data()['items'];$assert(count(array_unique(array_column($all,'id')))===count($all),'unique IDs');
$assert(count(array_unique(array_map(static fn($e)=>rtrim($e['endpoint'],'/'),$all)))===count($all),'unique endpoints');
foreach(['notion'=>'oauth','github'=>'shared','context7'=>'none']as$id=>$mode){$draft=Catalogue::draft(Catalogue::get($id));$assert($draft['remote']['auth_mode']===$mode&&$draft['status']==='active'&&$draft['allowed_user_ids']===[(int)$admin->ID]&&!$draft['remote']['publish_original'],'self-only '.$mode.' defaults');}
$detail=$req('remote-catalogue/notion')->get_data();$assert($detail['draft']['allowed_user_ids']===[(int)$admin->ID],'server supplies current user defaults');
$listing=$req('templates')->get_data();$assert(count(array_filter($listing,static fn($e)=>($e['kind']??'')==='remote-mcp'))===0,'remote catalogue separate from native templates');
$settings=GetMCPExtensions\Runtime::settings();$saved=get_option('getmcp_extensions_settings');
update_option('getmcp_extensions_settings',['features'=>array_merge($settings['features'],['remote_mcp'=>false])]);
$assert($req('remote-catalogue')->get_status()===403,'module off blocks catalogue');
if($saved===false)delete_option('getmcp_extensions_settings');else update_option('getmcp_extensions_settings',$saved);
$sm=new ServerManager();$remote=null;$gateway=null;
try{
 $draft=Catalogue::draft(Catalogue::get('notion'));$draft['name']='Fixture catalogue remote';
 $remote=FeatureManager::run('save',$draft);$row=$sm->get($remote['id']);
 $assert($row->server_kind==='remote-mcp'&&$row->status==='active','saved active remote record');
 $assert((new ToolManager())->get_by_server($row->id)['total']===0,'no native tools created');
 $assert(FirstPartyOAuth::can_user_access($admin->ID,$row),'creator can sign in immediately');
 $assert(!FirstPartyOAuth::can_user_access($other->ID,$row),'other users not automatically allowed');
 $assert(GetMCP\Remote\UpstreamConnections::can_connect($row,$admin->ID),'creator can authorize upstream immediately');
 FeatureManager::run('save',['id'=>$row->id,'allowed_user_ids'=>[]]);$row=$sm->get($row->id);
 $assert(!FirstPartyOAuth::can_user_access($admin->ID,$row),'no administrator direct bypass');
 FeatureManager::run('save',['id'=>$row->id,'status'=>'active']);$row=$sm->get($row->id);
 $assert(!GetMCP\Remote\UpstreamConnections::can_connect($row,$admin->ID),'cannot connect before gateway permission');
 $gateway=FeatureManager::run('save',['kind'=>'gateway','name'=>'Fixture catalogue gateway','server_ids'=>[$row->id],'allowed_user_ids'=>[(int)$admin->ID]]);
 $assert(GetMCP\Remote\UpstreamConnections::can_connect($row,$admin->ID),'gateway permission allows personal sign-in');
 $assert(!FirstPartyOAuth::can_user_access($admin->ID,$row),'gateway does not widen direct allowlist');
 $assert(!GetMCP\Remote\UpstreamConnections::can_connect($row,$other->ID),'excluded gateway user denied');
 FeatureManager::run('save',['id'=>$gateway['id'],'server_ids'=>[]]);
 $assert(!GetMCP\Remote\UpstreamConnections::can_connect($row,$admin->ID),'removed member loses account access');
}finally{if($gateway)$sm->delete($gateway['id']);if($remote)$sm->delete($remote['id']);remove_filter('pre_http_request',$http,-999);}
echo wp_json_encode(['passed'=>$passed,'failed'=>[],'upstream_http'=>'blocked; no real provider accounts contacted'])."\n";
