<?php
/**
 * @brief		ACP Member Profile: Purchases
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @since		5 Dec 2017
 */

namespace IPS\nexus\extensions\core\MemberACPProfileBlocks;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * @brief	ACP Member Profile: Purchases
 */
class _Purchases extends \IPS\core\MemberACPProfile\Block
{
	/**
	 * Purchase tree
	 */
	protected $_purchases = NULL;

	/**
	 * Get purchase table
	 *
	 * @return	\IPS\Helpers\Tree\Tree
	 */
	protected function purchases()
	{
		if ( $this->_purchases === NULL )
		{
			$this->_purchases = \IPS\nexus\Purchase::tree( $this->member->acpUrl()->setQueryString( 'blockKey', 'nexus_Purchases' ), array( array( 'ps_member=?', $this->member->member_id ) ) );
			$this->_purchases->rootsPerPage = 15;
			$this->_purchases->getTotalRoots = function()
			{
				return NULL;
			};
		}
		return $this->_purchases;
	}
	
	/**
	 * Get output
	 *
	 * @return	string
	 */
	public function output()
	{
		$purchaseCount = \IPS\Db::i()->select( 'COUNT(*)', 'nexus_purchases', array( 'ps_member=? AND ps_show=1', $this->member->member_id ) )->first();
		$purchaseRootCount = \IPS\Db::i()->select( 'COUNT(*)', 'nexus_purchases', array( 'ps_member=? AND ps_show=1 AND ps_parent=0', $this->member->member_id ) )->first();
		
		return \IPS\Theme::i()->getTemplate( 'customers', 'nexus' )->purchases( $this->member, $this->purchases(), $purchaseCount, $purchaseRootCount );
	}
	
	/**
	 * Get output
	 *
	 * @return	string
	 */
	public function tabOutput()
	{
		return (string) $this->purchases();
	}
}