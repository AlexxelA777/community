<?php
/**
 * @brief		Profile-sync Task
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @since		21 Jun 2013
 */

namespace IPS\core\tasks;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * Profile-sync Task
 */
class _profilesync extends \IPS\Task
{
	/**
	 * Execute
	 *
	 * @return	void
	 */
	public function execute()
	{
		$totalToSync = \IPS\Db::i()->select( 'count(*)', 'core_members', array( "temp_ban=0 AND profilesync_lastsync > 0" ) )->first();

		if( !$totalToSync )
		{
			return NULL;
		}
		else
		{
			/* The task runs once per hour and looks for all accounts that haven't been synced in 12+ hours, so we want to set a hard limit that
				will allow all accounts to be processed in a 12 hour period if resources permit */
			$accounts = ceil( $totalToSync / 12 );
		}

		$this->runUntilTimeout( function()
		{
			try
			{
				$member = \IPS\Db::i()->select( '*', 'core_members', array( "temp_ban=0 AND profilesync_lastsync > 0 AND profilesync_lastsync < ?", ( time() - ( 60 * 60 * 12 ) ) ), 'profilesync_lastsync ASC', 1 )->first();
			}
			catch ( \UnderflowException $e )
			{
				return FALSE;
			}
			
			$member = \IPS\Member::constructFromData( $member );
			$member->profileSync();
			
			return TRUE;
		}, $accounts );
		
		return NULL;
	}
}