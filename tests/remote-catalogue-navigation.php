<?php
/** Disposable rows for browser navigation only; no tools, credentials or user access. */
if(!defined('GETMCP_TELEGRAM_TESTING')||!GETMCP_TELEGRAM_TESTING||wp_get_environment_type()!=='local')throw new RuntimeException('Owned fixture only.');
use GetMCP\Core\ServerManager;
use GetMCP\Gateway\FeatureManager;
$mode=getenv('GETMCP_NAVIGATION_FIXTURE');if(!in_array($mode,['prepare','cleanup'],true))throw new RuntimeException('Choose prepare or cleanup.');
$sm=new ServerManager();$result=[];
foreach(['gateway','remote-mcp']as$kind){
 $slug=$kind==='gateway'?'fixture-catalogue-navigation-gateway':'fixture-catalogue-navigation-remote';
 $name=$kind==='gateway'?'Navigation QA gateway':'Navigation QA remote';$server=$sm->get_by_slug($slug);
 if($server&&($server->name!==$name||$server->server_kind!==$kind||$server->status!=='draft'))throw new RuntimeException('Unexpected fixture row.');
 if($mode==='cleanup'){if($server)$sm->delete($server->id);$result[]=['kind'=>$kind,'removed'=>(bool)$server];continue;}
 if(!$server){$data=['kind'=>$kind,'name'=>$name,'slug'=>$slug,'status'=>'draft','allowed_user_ids'=>[]];if($kind==='gateway')$data['server_ids']=[];else$data['remote']=['endpoint'=>'https://mcp.notion.com/mcp','auth_mode'=>'oauth','publish_original'=>false];$saved=FeatureManager::run('save',$data);$server=$sm->get($saved['id']);}
 $result[]=['kind'=>$kind,'id'=>$server->id,'name'=>$server->name];
}
echo wp_json_encode(['mode'=>$mode,'rows'=>$result,'upstream_requests'=>'none'])."\n";
