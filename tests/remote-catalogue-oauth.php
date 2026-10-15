<?php
/** Owned local fixture: synthetic upstream metadata/tokens, never live account requests. */
if ( ! defined('GETMCP_TELEGRAM_TESTING') || ! GETMCP_TELEGRAM_TESTING || wp_get_environment_type() !== 'local' ) { throw new RuntimeException('Owned local fixture required.'); }
use GetMCPExtensions\RemoteCatalogue;
use GetMCP\Core\ServerManager;
use GetMCP\Gateway\FeatureManager;
use GetMCP\Remote\{UpstreamOAuth,UpstreamConnections};
$passed=0;$assert=static function($ok,$label)use(&$passed){if(!$ok)throw new RuntimeException($label);$passed++;};
$admin=get_user_by('login','fixture-admin');$other=get_user_by('login','fixture-other');wp_set_current_user($admin->ID);
$calls=[];$states=[];$remote=null;$sm=new ServerManager();
$mock=static function($pre,$args,$url)use(&$calls){
 $calls[]=$url;$base='https://mcp.notion.com';
 if($url===$base.'/.well-known/oauth-protected-resource/mcp')$data=['resource'=>$base.'/mcp','authorization_servers'=>[$base],'scopes_supported'=>['fixture.read']];
 elseif($url===$base.'/.well-known/oauth-authorization-server')$data=['issuer'=>$base,'authorization_endpoint'=>$base.'/fixture-authorize','token_endpoint'=>$base.'/fixture-token','registration_endpoint'=>$base.'/fixture-register','code_challenge_methods_supported'=>['S256'],'token_endpoint_auth_methods_supported'=>['none']];
 elseif($url===$base.'/fixture-register')$data=['client_id'=>'fixture-client'];
 elseif($url===$base.'/fixture-token')$data=['access_token'=>'fixture-only-access','refresh_token'=>'fixture-only-refresh','token_type'=>'Bearer','expires_in'=>3600];
 else throw new RuntimeException('Unexpected upstream request blocked.');
 return ['response'=>['code'=>200,'message'=>'OK'],'headers'=>['content-type'=>'application/json'],'body'=>wp_json_encode($data),'cookies'=>[]];
};
add_filter('pre_http_request',$mock,-999,3);
try{
 $draft=RemoteCatalogue::draft(RemoteCatalogue::get('notion'));$draft['name']='Fixture catalogue OAuth';
 $remote=FeatureManager::run('save',$draft);$server=$sm->get($remote['id']);
 $start=UpstreamOAuth::begin($server,(int)$admin->ID,'admin');parse_str(wp_parse_url($start['authorization_url'],PHP_URL_QUERY),$query);$states[]=$query['state'];
 $assert($query['code_challenge_method']==='S256'&&$query['client_id']==='fixture-client','metadata discovery and dynamic registration');
 $assert($query['resource']==='https://mcp.notion.com/mcp','upstream resource bound');
 $assert(UpstreamConnections::get($server->id,(int)$admin->ID)===null,'not connected before callback');
 try{UpstreamOAuth::complete(['state'=>$query['state'],'code'=>'fixture-code'],(int)$other->ID);$denied=false;}catch(Throwable $e){$denied=true;}
 $assert($denied,'wrong user callback refused');
 $return=UpstreamOAuth::complete(['state'=>$query['state'],'code'=>'fixture-code'],(int)$admin->ID);
 $assert(str_contains($return,'page=getmcp-my-connections'),'returns to admin portal');
 $assert(UpstreamConnections::get($server->id,(int)$admin->ID)!==null&&UpstreamConnections::get($server->id,(int)$other->ID)===null,'per-user token isolation');
 try{UpstreamOAuth::complete(['state'=>$query['state'],'code'=>'fixture-code'],(int)$admin->ID);$replay=false;}catch(Throwable $e){$replay=true;}
 $assert($replay,'callback replay refused');
 $assert(count($calls)===4,'all upstream calls served by fixture');
}finally{
 foreach($states as$state)delete_option('getmcp_upstream_state_'.hash('sha256',$state));
 if($remote)$sm->delete($remote['id']);remove_filter('pre_http_request',$mock,-999);
}
echo wp_json_encode(['passed'=>$passed,'failed'=>[],'upstream_http'=>'synthetic metadata, registration and token responses only'])."\n";
