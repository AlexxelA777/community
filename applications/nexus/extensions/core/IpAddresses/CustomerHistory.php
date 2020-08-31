<?php
/**
 * @brief		IP Address Lookup extension
 * @author		<a href='http://www.invisionpower.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) 2001 - SVN_YYYY Invision Power Services, Inc.
 * @license		http://www.invisionpower.com/legal/standards/
 * @package		IPS Social Suite
 * @subpackage	
 * @since		18 Sep 2014
 * @version		SVN_VERSION_NUMBER
 * @deprecated	4.3
 */

namespace IPS\nexus\extensions\core\IpAddresses;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * IP Address Lookup extension
 */
class _CustomerHistory
{
	/**
	 * @deprecated
	 *
	 *@return	bool
	 */
	final public function deprecated()
	{
		return TRUE;
	}	
}