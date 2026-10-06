<?php
/** Regression harness: actual deployed PHP classes with deterministic WP/DB fixtures. */
namespace GetMCP\Gateway { class McpGateway { const SERVER_ID = -2; static function is_gateway($s) { return $s->id === -2; } static function slug_is_reserved($s) { return $s === 'gateway'; } static function endpoint() { return 'https://site.test/mcp'; } } }
namespace GetMCP\Builtin { class BuiltinServer { const SERVER_ID = -1; static function slug_is_reserved($s) { return $s === 'getmcp'; } } }
namespace GetMCP\Utils {
    class PublicUrl { static function server($s) { return 'https://site.test/mcp/'.$s; } }
    class Encryption { static function decrypt_strict($s) { return $s; } }
}
namespace GetMCP\Execution { class ToolExecutor {} }
namespace {
define('ARRAY_A', 'ARRAY_A');
$principals = [1 => true]; $transients = []; $status = 200; $assertions = 0;
function user_can($id, $cap) { return $GLOBALS['principals'][$id] ?? false; }
function get_userdata($id) { return isset($GLOBALS['principals'][$id]) ? (object)['ID'=>$id] : false; }
function is_user_logged_in() { return true; }
function get_current_user_id() { return 1; }
function wp_unslash($s) { return $s; }
function sanitize_text_field($s) { return $s; }
function esc_url_raw($s) { return $s; }
function wp_parse_url($s, $part = -1) { return parse_url($s, $part); }
function wp_json_encode($s) { return json_encode($s); }
function status_header($s) { $GLOBALS['status'] = $s; }
function __($s, $domain = '') { return $s; }
function esc_html__($s, $d = '') { return htmlspecialchars($s); }
function get_transient($k) { return $GLOBALS['transients'][$k] ?? false; }
function set_transient($k, $v, $ttl) { $GLOBALS['transients'][$k] = $v; }
function delete_transient($k) { unset($GLOBALS['transients'][$k]); }
function apply_filters($hook, $value, ...$rest) { return $value; }
class FixtureDB {
    public $prefix = 'wp_'; public $clients = []; public $tokens = []; public $tools = []; public $race = false;
    function prepare($sql, ...$args) { return [$sql, $args]; }
    function get_row($query, $format = null) {
        [$sql, $args] = $query;
        if (str_contains($sql, 'getmcp_tools')) { $r = $this->tools[$args[1]] ?? null; return $r; }
        if (str_contains($sql, 'JOIN')) {
            foreach ($this->tokens as $r) {
                if ($r->access_token_hash === $args[0] && strtotime($r->expires_at.' UTC') > time()) {
                    $c = $this->clients[$r->client_id] ?? null;
                    return $c ? (object)(array_merge((array)$r, ['client_server_id' => $c->server_id])) : null;
                }
            } return null;
        }
        if (str_contains($sql, 'getmcp_oauth_clients')) {
            foreach ($this->clients as $r) if (str_contains($sql, 'WHERE id') ? $r->id === $args[0] : $r->client_id === $args[0]) return clone $r;
            return null;
        }
        $column = str_contains($sql, 'authorization_code_hash') ? 'authorization_code_hash' : 'refresh_token_hash';
        $expiry = $column === 'authorization_code_hash' ? 'expires_at' : 'refresh_expires_at';
        foreach ($this->tokens as $r) if (($r->$column ?? null) === $args[0] && strtotime($r->$expiry.' UTC') > time()) return clone $r;
        return null;
    }
    function update($table, $data, $where, ...$formats) {
        if ($this->race) { $this->race = false; return 0; }
        foreach ($this->tokens as $r) {
            foreach ($where as $k => $v) if (($r->$k ?? null) !== $v) continue 2;
            foreach ($data as $k => $v) $r->$k = $v;
            return 1;
        } return 0;
    }
}
$wpdb = new FixtureDB();
$root = (getenv('GETMCP_TEST_ROOT') ?: dirname(__DIR__).'/source/getmcp').'/includes/';
foreach (['core/class-server.php','core/class-tool.php','auth/class-first-party-oauth.php','auth/class-oauth-provider.php','auth/class-mcp-auth.php','execution/class-auth-injector.php','protocol/class-handler-interface.php','protocol/class-json-rpc-exception.php','protocol/class-progress-emitter.php','protocol/class-tools-call-handler.php','protocol/class-json-rpc-router.php'] as $file) {
    require $root.$file;
}
use GetMCP\Core\Server;
use GetMCP\Auth\FirstPartyOAuth as Native;
use GetMCP\Auth\OAuthProvider as OAuth;
use GetMCP\Auth\McpAuth;
function check($condition, $name) { $GLOBALS['assertions']++; if (!$condition) throw new \RuntimeException('FAIL: '.$name); }
function server($id = 9, $slug = 'alegra', $provider = 'getmcp') { return new Server(['id'=>$id,'slug'=>$slug,'name'=>'Alegra','auth_type'=>'oauth','auth_config'=>json_encode(['provider'=>$provider,'allowed_user_ids'=>[1]])]); }
function seed($scope = 'mcp:read', $serverId = 9, $resource = 'https://site.test/mcp/alegra', $uid = 1) {
    global $wpdb, $transients, $principals;
    $principals = [1 => true]; $transients = []; $wpdb->tokens = []; $wpdb->race = false;
    $wpdb->clients = [10 => (object)['id'=>10,'client_id'=>'fixture','server_id'=>$serverId,'redirect_uris'=>'["https://client.test/callback"]']];
    $verifier = str_repeat('v',43); $code = 'fixture-code';
    $wpdb->tokens[20] = (object)['id'=>20,'client_id'=>10,'user_id'=>$uid,'scopes'=>$scope,'resource'=>$resource,
        'authorization_code_hash'=>hash('sha256',$code),'code_challenge'=>rtrim(strtr(base64_encode(hash('sha256',$verifier,true)),'+/','-_'),'='),
        'access_token_hash'=>hash('sha256','fixture-access'),'refresh_token_hash'=>hash('sha256','fixture-refresh'),
        'expires_at'=>gmdate('Y-m-d H:i:s', time()+3600),'refresh_expires_at'=>gmdate('Y-m-d H:i:s',time()+3600)];
    $transients['getmcp_code_redirect_'.hash('sha256',$code)] = 'https://client.test/callback';
    $_POST = ['grant_type'=>'authorization_code','code'=>$code,'client_id'=>'fixture','code_verifier'=>$verifier,'redirect_uri'=>'https://client.test/callback','resource'=>$resource]; $_GET = [];
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer fixture-access'; McpAuth::reset();
}
function exchange($s) { ob_start(); Native::handle_token($s); $out=ob_get_clean(); return json_decode($out,true,512,JSON_THROW_ON_ERROR); }
function rejected($s, $name, $error = null) { $r=exchange($s); check(isset($r['error']) && ($error === null || $r['error'] === $error),$name); }
$s=server();
check(Native::owns($s),'custom explicit native ownership');
check(!Native::owns(server(9,'alegra','external')),'external provider preserved');
$legacy=server(); $legacy->auth_config='{"authorize_url":"https://provider.test/auth"}'; check(!Native::owns($legacy),'legacy provider preserved');
check(Native::owns(server(1,'getmcp','external')) && Native::owns(server(-2,'gateway','external')),'reserved server ownership preserved');
check(Native::supported_scopes($s) === ['mcp:read','mcp:write'],'custom excludes admin scope');
seed(); check(Native::can_user_access(1,$s),'authorized user'); check(!Native::can_user_access(2,$s),'unauthorized user');
$allowed=clone $s; $allowed->auth_config='{"provider":"getmcp","allowed_user_ids":[]}'; check(!Native::can_user_access(1,$allowed),'empty allowlist denies');
$allowed->auth_config='{"provider":"getmcp","allowed_user_ids":[1]}'; check(Native::can_user_access(1,$allowed),'allowlist allows');
$allowed->status='paused'; check(!Native::can_user_access(1,$allowed),'paused server denies');
$none=clone $s; $none->auth_config='{"provider":"getmcp"}'; check(!Native::can_user_access(1,$none),'admin cannot bypass missing allowlist');
$principals[2]=true; check(!Native::can_user_access(2,$s),'another admin cannot bypass allowlist');
$nonadmin=clone $s; $nonadmin->auth_config='{"provider":"getmcp","allowed_user_ids":[2]}'; check(Native::can_user_access(2,$nonadmin),'selected WordPress principal can authenticate');
unset($principals[2]); check(!Native::can_user_access(2,$nonadmin),'deleted principal cannot authenticate');
seed(); $r=exchange($s); check(isset($r['access_token'],$r['refresh_token']) && $r['scope']==='mcp:read','code exchange succeeds and retains scope');
check($wpdb->tokens[20]->access_token_hash===hash('sha256',$r['access_token']),'only access hash persisted');
check(!isset($transients['getmcp_code_redirect_'.hash('sha256','fixture-code')]),'redirect transient consumed');
rejected($s,'code replay rejected','invalid_grant');
$_POST=['grant_type'=>'refresh_token','refresh_token'=>$r['refresh_token'],'client_id'=>'fixture','resource'=>$s->get_endpoint_url()];
$rotated=exchange($s); check(isset($rotated['access_token'],$rotated['refresh_token']) && $rotated['refresh_token']!==$r['refresh_token'],'refresh rotation');
rejected($s,'refresh replay rejected','invalid_grant');
foreach (['code_verifier'=>str_repeat('x',43),'redirect_uri'=>'https://evil.test/callback','resource'=>'https://site.test/mcp/other','client_id'=>'other'] as $key=>$value) { seed(); $_POST[$key]=$value; rejected($s,'bad '.$key); }
seed(); unset($_POST['client_id']); rejected($s,'missing client identity');
seed(); $wpdb->tokens[20]->expires_at='2000-01-01 00:00:00'; rejected($s,'expired code');
seed(); $wpdb->race=true; rejected($s,'concurrent code exchange rejected');
seed(); $principals[1]=false; rejected($s,'revoked code principal');
seed(); rejected(server(5,'other'),'other endpoint code');
seed(); rejected(server(-2,'gateway'),'gateway code');
foreach (['resource'=>'https://site.test/mcp/other','client_id'=>'other','refresh_token'=>'bad'] as $key=>$value) { seed(); $_POST=['grant_type'=>'refresh_token','refresh_token'=>'fixture-refresh','client_id'=>'fixture','resource'=>$s->get_endpoint_url()]; $_POST[$key]=$value; rejected($s,'refresh bad '.$key); }
seed(); $_POST=['grant_type'=>'refresh_token','refresh_token'=>'fixture-refresh','client_id'=>'fixture']; rejected(server(5,'other'),'cross server refresh');
seed(); $_POST=['grant_type'=>'refresh_token','refresh_token'=>'fixture-refresh','client_id'=>'fixture']; $principals[1]=false; rejected($s,'revoked refresh principal');
seed(); $_POST=['grant_type'=>'refresh_token','refresh_token'=>'fixture-refresh','client_id'=>'fixture']; $wpdb->tokens[20]->refresh_expires_at='2000-01-01 00:00:00'; rejected($s,'expired refresh');
seed(); $_POST=['grant_type'=>'refresh_token','refresh_token'=>'fixture-refresh','client_id'=>'fixture']; $wpdb->race=true; rejected($s,'concurrent refresh rejected');
seed(); check(OAuth::validate_access_token('fixture-access',$s),'native access accepted');
check(!OAuth::validate_access_token('fixture-access',server(5,'other')) && !OAuth::validate_access_token('fixture-access',server(-2,'gateway')),'access cannot cross endpoints');
$principals[1]=false; check(!OAuth::validate_access_token('fixture-access',$s) && OAuth::validate_and_extract_row('fixture-access',$s)===null,'all access validators reject revoked principal');
seed(); check(!OAuth::validate_access_token('fixture-access',server(9,'alegra','external')),'mode change rejects old native grant');
seed('mcp:read',9,'https://site.test/mcp/alegra',0); check(!OAuth::validate_access_token('fixture-access',$s),'native rejects broker grant');
check(OAuth::validate_access_token('fixture-access',server(9,'alegra','external')),'external broker user-zero accepted');
foreach (['','*','mcp:admin','mcp:read unknown'] as $scope) { seed($scope); check(!OAuth::validate_access_token('fixture-access',$s),'native rejects scope '.$scope); }
seed('mcp:read',0); check(McpAuth::authenticate($s)===true && McpAuth::is_native_server_request(),'CIMD server-zero request remains native');
check(McpAuth::has_scope('mcp:read') && !McpAuth::has_scope('mcp:write'),'read token does not grant writes');
$wpdb->tools['write']=['id'=>1,'server_id'=>9,'slug'=>'write','name'=>'Write','status'=>'active','http_method'=>'POST'];
try { (new \GetMCP\Protocol\ToolsCallHandler($s,null))->handle('tools/call',['name'=>'write','arguments'=>[]]); check(false,'write must fail'); }
catch (\GetMCP\Protocol\JsonRpcException $e) { check(McpAuth::get_insufficient_scope()==='mcp:write','write blocked before upstream execution'); }
seed('mcp:write'); McpAuth::authenticate($s);
$router=new \GetMCP\Protocol\JsonRpcRouter($s); $method=new \ReflectionMethod($router,'process_single');
$denied=$method->invoke($router,['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/list']); check(($denied['error']['code']??0)===-32003 && McpAuth::get_insufficient_scope()==='mcp:read','write-only token cannot discover data');
seed(); McpAuth::authenticate($s); $injector=new \GetMCP\Execution\AuthInjector();
$request=['url'=>'https://api.test/company','headers'=>['authorization'=>'Bearer fixture-access','X-Test'=>'preserve']];
$out=$injector->inject($request,false,$s); check(!isset($out['headers']['authorization']) && $out['headers']['X-Test']==='preserve','native bearer never forwarded');
$s->outbound_auth_type='basic'; $s->outbound_auth_credentials='{"username":"fixture-user","password":"fixture-key"}';
$out=$injector->inject(['headers'=>[]],false,$s); check(($out['headers']['Authorization']??'')==='Basic '.base64_encode('fixture-user:fixture-key'),'stored Basic used independently');
seed('email profile',9,'https://site.test/mcp/alegra',0); $ext=server(9,'alegra','external'); $wpdb->tokens[20]->upstream_access_token='fixture-upstream'; McpAuth::authenticate($ext);
$out=$injector->inject(['headers'=>[]],false,$ext); check(($out['headers']['Authorization']??'')==='Bearer fixture-upstream','external per-user upstream OAuth retained');
$read=new \ReflectionMethod(Native::class,'read_authorize_request');
seed(); $_GET=$_POST+['response_type'=>'code','code_challenge'=>$wpdb->tokens[20]->code_challenge,'code_challenge_method'=>'S256']; $_POST=[];
check(!isset($read->invoke(null)['error']),'valid authorize PKCE'); $_GET['code_challenge_method']='plain'; check(isset($read->invoke(null)['error']),'plain PKCE rejected');
$_GET['code_challenge_method']='S256'; $_GET['code_challenge']='short'; check(isset($read->invoke(null)['error']),'invalid challenge rejected');
$redirect=new \ReflectionMethod(Native::class,'redirect_uri_allowed');
check($redirect->invoke(null,'http://127.0.0.1:49152/callback',['http://127.0.0.1:123/callback']),'loopback port compatibility');
foreach (['http://localhost:49152/callback','http://127.0.0.1:49152/evil','http://127.0.0.1:70000/callback','https://evil.test/callback'] as $url) check(!$redirect->invoke(null,$url,['http://127.0.0.1:123/callback']),'bad callback rejected');
echo json_encode(['passed'=>$assertions,'php'=>PHP_VERSION,'coverage'=>'Actual auth, grant, rotation, scope, router and injector classes; WP/DB fixtures. Browser consent and real DB separately required.'],JSON_PRETTY_PRINT).PHP_EOL;
}
