<?php
/**
 * @brief		Base API endpoint for Nodes
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @since		3 Apr 2017
 */

namespace IPS\Node\Api;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * @brief	Base API endpoint for Nodes
 */
class _NodeController extends \IPS\Api\Controller
{
	/**
	 * List
	 *
	 * @param	array	$where	Extra WHERE clause
	 * @return	\IPS\Api\PaginatedResponse
	 */
	protected function _list( $where = array() )
	{
		$class = $this->class;
		
		if ( $this->member and in_array( 'IPS\Node\Permissions', class_implements( $class ) ) )
		{
			$where[] = array( '(' . \IPS\Db::i()->findInSet( 'core_permission_index.perm_' . $class::$permissionMap['view'], $this->member->permissionArray() ) . ' OR ' . 'core_permission_index.perm_' . $class::$permissionMap['view'] . '=? )', '*' );
			if ( $class::$databaseColumnEnabledDisabled )
			{
				$where[] = array( $class::$databasePrefix . $class::$databaseColumnEnabledDisabled . '=1' );
			}
		}
		
		$select = \IPS\Db::i()->select( '*', $class::$databaseTable, $where, $class::$databaseColumnOrder ? $class::$databasePrefix . $class::$databaseColumnOrder . " asc" : NULL, NULL, NULL, NULL, \IPS\Db::SELECT_SQL_CALC_FOUND_ROWS );
		if ( $this->member and in_array( 'IPS\Node\Permissions', class_implements( $class ) ) )
		{
			$select->join( 'core_permission_index', array( "core_permission_index.app=? AND core_permission_index.perm_type=? AND core_permission_index.perm_type_id=" . $class::$databaseTable . "." . $class::$databasePrefix . $class::$databaseColumnId, $class::$permApp, $class::$permType ) );
		}

		/* Return */
		return new \IPS\Api\PaginatedResponse(
			200,
			$select,
			isset( \IPS\Request::i()->page ) ? \IPS\Request::i()->page : 1,
			$class,
			$select->count( TRUE ),
			$this->member,
			isset( \IPS\Request::i()->perPage ) ? \IPS\Request::i()->perPage : NULL
		);
	}

	/**
	 * View
	 *
	 * @param	int	$id	ID Number
	 * @return	\IPS\Api\Response
	 */
	protected function _view( $id )
	{
		$class = $this->class;
		
		$node = $class::load( $id );
		if ( $this->member and !$node->can( 'view', $this->member ) )
		{
			throw new \OutOfRangeException;
		}
		
		return new \IPS\Api\Response( 200, $node->apiOutput( $this->member ) );
	}

	/**
	 * View
	 *
	 * @param	int	$id	ID Number
	 * @throws	1S359/1	INVALID_ID	The member ID does not exist
	 * @return	\IPS\Api\Response
	 */
	protected function _delete( $id )
	{
		$class = $this->class;

		try
		{
			$class::load( $id )->delete();

			return new \IPS\Api\Response( 200, NULL );
		}
		catch ( \OutOfRangeException $e )
		{
			throw new \IPS\Api\Exception( 'INVALID_ID', '1S359/1', 404 );
		}
	}

	/**
	 * Create or update node
	 *
	 * @param	\IPS\node\Model	$node				The node
	 * @return	\IPS\node\Model
	 */
	protected function _createOrUpdate( \IPS\node\Model $node )
	{
		$node->save();

		/* Return */
		return $node;
	}

	/**
	 * Create
	 *
	 * @return	\IPS\Content\Node
	 */
	protected function _create()
	{
		$class = $this->class;

		/* Create item */
		$node = new $class;

		if( isset( $node::$databaseColumnOrder ) AND $node::$automaticPositionDetermination === TRUE )
		{
			$orderColumn = $node::$databaseColumnOrder;
			$node->$orderColumn = \IPS\Db::i()->select( 'MAX(' . $node::$databasePrefix . $orderColumn . ')', $node::$databaseTable  )->first() + 1;
		}

		$node->save();
		$node = $this->_createOrUpdate( $node );

		/* Output */
		return $node;
	}
}