<?php
/** Enabled-module shim for original standalone auth fixtures. Switch denial is tested in WordPress. */
namespace GetMCPExtensions { class Runtime { static function server_enabled($server):bool{return true;} static function assert_server($server):void{} } }
namespace { require __DIR__ . '/../gateway-fixture-bootstrap.php'; }
