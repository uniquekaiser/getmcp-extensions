<?php
namespace GetMCP\Core {
    class ServerManager { function get($id) { return $id === 9 ? clone $GLOBALS['server'] : false; } }
}
namespace GetMCP\Auth { class FirstPartyOAuth { static function is_custom($s) { return $s->auth_type === 'oauth' && (json_decode($s->auth_config,true)['provider']??'') === 'getmcp'; } } }
namespace {
class WP_Error { function __construct(public $code,public $message,public $data=[]) {} }
function is_wp_error($v) { return $v instanceof WP_Error; }
function get_current_user_id() { return 1; }
function user_can($id,$cap) { $id=is_object($id)?$id->ID:$id; return $cap==='getmcp_manage_servers' ? $id===1 : in_array($id,[1,2],true); }
function get_userdata($id) { return in_array($id,[1,2,3],true)?(object)['ID'=>$id]:false; }
function sanitize_text_field($s) { return $s; }
function wp_json_encode($s) { return json_encode($s); }
function do_action($name,...$args) { $GLOBALS['actions'][]=[$name,$args]; }
class WP_User_Query {
    function __construct(public $args) { $GLOBALS['queries'][]=$args; }
    function get_results() { return [(object)['ID'=>1,'display_name'=>'Admin','user_login'=>'admin','user_pass'=>'never expose']]; }
    function get_total() { return 10000; }
}
class DB {
    public $prefix='wp_'; public $fail=null;
    function update($table,$data,$where,...$rest) {
        if($this->fail!==null) return $this->fail;
        if($where['auth_config']!==$GLOBALS['server']->auth_config) return 0;
        $GLOBALS['server']->auth_config=$data['auth_config']; return 1;
    }
}
$server=(object)['auth_type'=>'oauth','auth_config'=>'{"provider":"getmcp","allowed_user_ids":[1],"custom":"preserve"}','outbound_auth_credentials'=>'encrypted-fixture'];
$wpdb=new DB();$queries=[];$actions=[];$checks=0;
require (getenv('GETMCP_TEST_ROOT') ?: dirname(__DIR__).'/source/getmcp').'/includes/auth/class-oauth-user-access.php';
use GetMCP\Auth\OAuthUserAccess as Access;
function check($ok,$name) { $GLOBALS['checks']++;if(!$ok)throw new RuntimeException($name); }
function error($result,$code) { return is_wp_error($result)&&$result->code===$code; }
check(error(Access::search(['search'=>'admin'],2),'getmcp_forbidden'),'read-capable user cannot search');
check(error(Access::get(9,2),'getmcp_forbidden'),'read-capable user cannot read access');
check(error(Access::set(9,[2],null,2),'getmcp_forbidden'),'read-capable user cannot grant access');
foreach(['','a','**',' * '] as $term) check(Access::search(['search'=>$term])['users']===[],'empty/short/wildcard does not enumerate');
check(count($queries)===0,'no query for empty/short search');
$r=Access::search(['search'=>'admin','per_page'=>999,'page'=>2]);$q=end($queries);
check($q['number']===50&&$q['offset']===50,'bounded search and pagination');
check($q['search']==='*admin*'&&$q['search_columns']===['user_login','display_name'],'search limited to public user labels');
check($r['has_more']&&$r['total_pages']===200,'ten thousand user pagination');
check(array_keys($r['users'][0])===['id','name','login','eligible'],'minimal response without secrets/email');
$r=Access::search(['include'=>[1,2,2],'page'=>20]);$q=end($queries);
check($q['include']===[1,2]&&$q['number']===2&&$q['offset']===0,'selected ID lookup bounded and deduplicated');
foreach([[0],['1'],range(1,101)] as $ids) check(error(Access::search(['include'=>$ids]),'getmcp_invalid_user_lookup'),'bad lookup IDs rejected');
foreach([null,['1'],[0],[-1],[999],[3],['x'=>1]] as $ids) check(error(Access::set(9,$ids),'getmcp_invalid_oauth_users'),'bad/ineligible grant rejected');
check(error(Access::get(99),'getmcp_not_found'),'missing server rejected');
$original=$server->auth_config;$r=Access::set(9,[2,1,2],[1]);
check($r['allowed_user_ids']===[2,1],'multiple users deduplicated');
check(json_decode($server->auth_config,true)['custom']==='preserve'&&$server->outbound_auth_credentials==='encrypted-fixture','credentials and config preserved');
check(count($actions)===1&&$actions[0][1]===[9,[2,1],[1],1],'change hook includes actor and old/new IDs');
check(error(Access::set(9,[1],[1]),'getmcp_access_conflict'),'stale expected list rejected');
$r=Access::set(9,[2,1],[2,1]);check(count($actions)===1,'idempotent save does not fire change hook');
$wpdb->fail=0;check(error(Access::set(9,[1]),'getmcp_access_conflict'),'CAS race rejected');
$wpdb->fail=false;check(error(Access::set(9,[1]),'getmcp_access_update_failed'),'DB failure reported');
$wpdb->fail=null;$r=Access::set(9,[],[2,1]);check($r['allowed_user_ids']===[],'empty allowlist permitted and denies all');
$server->auth_config='{"provider":"external"}';check(error(Access::set(9,[1]),'getmcp_not_native_oauth'),'external OAuth never converted');
echo json_encode(['passed'=>$checks,'php'=>PHP_VERSION,'coverage'=>'actual directory/access service with WP/DB fixtures']).PHP_EOL;
}
