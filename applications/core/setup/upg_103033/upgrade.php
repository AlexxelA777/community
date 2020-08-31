<?php
/**
 * @brief		4.3.6 Beta 1 Upgrade Code
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community

 * @since		01 Aug 2018
 */

namespace IPS\core\setup\upg_103033;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * 4.3.6 Beta 1 Upgrade Code
 */
class _Upgrade
{
	/**
	 * Step 1
	 * Remove announcement widgets
	 *
	 * @return bool
	 */
	public function step1()
	{
		foreach( \IPS\Db::i()->select( 'id, widgets', 'core_widget_areas', array( "widgets LIKE CONCAT( '%', ?, '%' )", 'announcements' ) )->setKeyField('id')->setValueField( 'widgets' ) as $id => $config )
		{
			$json = json_decode( $config, TRUE );

			foreach( $json as $key => $value )
			{
				if( $value['key'] == 'announcements' AND $value['app'] == 'core' )
				{
					unset( $json[ $key ] );
				}
			}

			\IPS\Db::i()->update( 'core_widget_areas', array( 'widgets' => json_encode( $json ) ), array( 'id=?', $id ) );
		}

		\IPS\Db::i()->delete( 'core_widgets', array( 'app=? AND `key`=?', 'core', 'announcements' ) );

		return TRUE;
	}

	/**
	 * Custom title for this step
	 *
	 * @return	string
	 */
	public function step1CustomTitle()
	{
		return "Removing announcement widgets";
	}
}