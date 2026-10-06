<?php
/** Safe errors for remote MCP connections. @package GetMCP */
namespace GetMCP\Remote;

class RemoteException extends \GetMCP\Protocol\JsonRpcException {

	public function __construct( string $message, int $code = -32000, array $data = array() ) {
		parent::__construct( $code, $message, $data );
	}
}
