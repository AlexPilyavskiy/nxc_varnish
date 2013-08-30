<?php
/**
 * @package nxcVarnish
 * @class   nxcVarnishClearType
 * @author  Serhey Dolgushev <serhey.dolgushev@nxc.no>
 * @date    23 Jan 2013
 **/

class nxcVarnishClearType extends eZWorkflowEventType
{
	const TYPE_ID = 'nxcvarnishclear';

	public function __construct() {
		$this->eZWorkflowEventType( self::TYPE_ID, 'Clear Varnish cache' );
		$this->setTriggerTypes(
			array(
				'content' => array(
					'publish' => array( 'after', 'before' )
				)
			)
		);
	}

	public function execute( $process, $event ) {
		$nodeIDs    = array();
		$parameters = $process->attribute( 'parameter_list' );

		$object = eZContentObject::fetch( $parameters['object_id'] );
		if( $object instanceof eZContentObject === false ) {
			return eZWorkflowType::STATUS_ACCEPTED;
		}

		// Assigned nodes
		$assignedNodes = $object->assignedNodes( false );
		foreach( $assignedNodes as $node ) {
			$nodeIDs[] = $node['node_id'];
		}

		// Reverse related objects
		$objects = $object->attribute( 'reverse_related_contentobject_array' );
		foreach( $objects as $object ) {
			$assignedNodes = $object->assignedNodes( false );
			foreach( $assignedNodes as $node ) {
				$nodeIDs[] = $node['node_id'];
			}
		}

		// Content cache manager
		$nodes = eZContentCacheManager::nodeList( $object->attribute( 'id' ), $object->attribute( 'current_version' ) );
		foreach( $nodes as $node ) {
			$nodeIDs[] = $node;
		}

		// Flow blocks
		$db = eZDB::instance();
		$c  = $db->arrayQuery( 'SHOW TABLES LIKE "ezm_block"' );
		if( count( $c ) > 0 ) {
			$q = 'SELECT DISTINCT block.node_id '
				. 'FROM ezm_pool pool '
				. 'LEFT JOIN ezm_block block ON pool.block_id = block.id '
				. 'WHERE pool.object_id = ' . $parameters['object_id'] . ' AND pool.ts_visible > 0 AND pool.ts_hidden = 0';
			$nodes = $db->arrayQuery( $q );
			foreach( $nodes as $node ) {
				$nodeIDs[] = $node['node_id'];
			}
		}

		$nodeIDs        = array_unique( $nodeIDs );
		$installationID = nxcVarnish::getInstallationID();

		$ini = eZINI::instance( 'varnish.ini' );
		if( $ini->hasVariable( 'AdditionalClearCacheHandler', 'Callback' ) ) {
			$callback = $ini->variable( 'AdditionalClearCacheHandler', 'Callback' );
			$callback = explode( '::', $callback );
			if( is_callable( $callback ) ) {
				$nodeIDs = array_unique(
					array_merge(
						$nodeIDs,
						call_user_func_array(
							$callback,
							array( $object, $nodeIDs )
						)
					)
				);
			}
		}

		if( count( $nodeIDs ) > 0 ) {
			$varnish = nxcVarnish::getInstance();
			foreach( $nodeIDs as $nodeID ) {
				$request = 'ban obj.http.X-eZPublish-NodeID == ' . $nodeID
					. ' && obj.http.X-eZPublish-InstallationID == ' . $installationID;
				try{
					$varnish->cli( $request );
				} catch( Exception $e ) {}
			}
		}

		return eZWorkflowType::STATUS_ACCEPTED;
	}

	public static function getAdditionalNodeIDs( $object, $nodeIDs ) {
		$additionalNodeIDs = array();
		return $additionalNodeIDs;
	}
}

eZWorkflowEventType::registerEventType( nxcVarnishClearType::TYPE_ID, 'nxcVarnishClearType' );
