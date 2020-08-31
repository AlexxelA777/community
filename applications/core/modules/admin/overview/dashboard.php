<?php
/**
 * @brief		ACP Dashboard
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @since		2 July 2013
 */

namespace IPS\core\modules\admin\overview;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * ACP Dashboard
 */
class _dashboard extends \IPS\Dispatcher\Controller
{
	/**
	 * Show the ACP dashboard
	 *
	 * @return	void
	 */
	protected function manage()
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'view_dashboard' );
		
		\IPS\Output::i()->jsFiles = array_merge( \IPS\Output::i()->jsFiles, \IPS\Output::i()->js('admin_dashboard.js', 'core') );
		\IPS\Output::i()->cssFiles = array_merge( \IPS\Output::i()->cssFiles, \IPS\Theme::i()->css( 'system/dashboard.css', 'core', 'admin' ) );

		/* Figure out which blocks we should show */
		$toShow	= $this->current( TRUE );
		
		/* Now grab dashboard extensions */
		$blocks	= array();
		$info	= array();
		foreach ( \IPS\Application::allExtensions( 'core', 'Dashboard', TRUE, 'core' ) as $key => $extension )
		{
			if ( !method_exists( $extension, 'canView' ) or $extension->canView() )
			{
				$info[ $key ]	= array(
							'name'	=> \IPS\Member::loggedIn()->language()->addToStack('block_' . $key ),
							'key'	=> $key,
							'app'	=> \substr( $key, 0, \strpos( $key, '_' ) )
				);

				if( method_exists( $extension, 'getBlock' ) )
				{
					foreach( $toShow as $row )
					{
						if( in_array( $key, $row ) )
						{
							$blocks[ $key ]	= $extension->getBlock();
							break;
						}
					}
				}
			}
		}
		
		/* ACP Bulletin */
		$bulletin = isset( \IPS\Data\Store::i()->acpBulletin ) ? \IPS\Data\Store::i()->acpBulletin : NULL;
		if ( !$bulletin or $bulletin['time'] < ( time() - 86400 ) )
		{
			try
			{
				$bulletins = \IPS\Http\Url::ips('bulletin')->request()->get()->decodeJson();
				\IPS\Data\Store::i()->acpBulletin = array(
					'time'		=> time(),
					'content'	=> $bulletins ?: array()
				);
			}
			catch( \RuntimeException $e )
			{
				$bulletins = array();
			}
		}
		else
		{
			$bulletins = $bulletin['content'];
		}
		if( !empty( $bulletins ) )
		{
			foreach ( $bulletins as $k => $data )
			{
				if ( count( $data['files'] ) )
				{
					$skip = TRUE;
					foreach ( $data['files'] as $file )
					{
						if ( filemtime( \IPS\ROOT_PATH . '/' . $file ) < $data['timestamp'] )
						{
							$skip = FALSE;
						}
					}
					if ( $skip )
					{
						unset( $bulletins[ $k ] );
					}
				}
			}
		}

		/* Warnings */
		$warnings = array();
		
		$functionsToDisable = array( 'exec', 'system', 'passhtru', 'pcntl_exec', 'popen', 'proc_open', 'shell_exec' );
		$showingFunctionWarning = FALSE;
		foreach ( $functionsToDisable as $k => $function )
		{
			if ( function_exists( $function ) )
			{
				$showingFunctionWarning = TRUE;
			}
			else
			{
				unset( $functionsToDisable[ $k ] );
			}
		}
		if ( $showingFunctionWarning )
		{
			$warnings[] = array(
                'key' => 'disable_functions',
				'title'			=> \IPS\Member::loggedIn()->language()->addToStack('disable_functions_title'),
				'description'	=> \IPS\Member::loggedIn()->language()->addToStack('disable_functions_desc', FALSE, array( 'sprintf' => array( implode( ', ', $functionsToDisable ) ) ) ),
			);
		}
		
		if ( !\IPS\IN_DEV and ( (bool) ini_get( 'display_errors' ) ) !== FALSE AND ini_get( 'display_errors' ) !== 'Off' )
		{
			$warnings[] = array(
                    'key' => 'display_errors',
					'title'			=> \IPS\Member::loggedIn()->language()->addToStack('display_errors_title'),
					'description'	=> \IPS\Member::loggedIn()->language()->addToStack('display_errors_desc'),
					'risk'			=> "medium",
			);
		}

		$tasks = \IPS\Db::i()->select( '*', 'core_tasks', 'lock_count >= 3 AND enabled=1' );
		$keys = array();
		foreach( $tasks as $task )
		{
			$keys[] = $task['key'];
		}
		if ( !empty( $keys ) )
		{
			$warnings[] = array(
			    'key' => 'dashboard_tasks_broken',
				'title' => \IPS\Member::loggedIn()->language()->addToStack( 'dashboard_tasks_broken' ),
				'description' => \IPS\Member::loggedIn()->language()->addToStack( 'dashboard_tasks_broken_desc', TRUE, array( 'sprintf' => array( \IPS\Member::loggedIn()->language()->formatList( $keys ) ) ) )
			);
		}

		if( isset( \IPS\Data\Store::i()->failedMailCount ) AND \IPS\Data\Store::i()->failedMailCount >= 3 )
		{
			$warnings[] = array(
                'key' => 'dashboard_email_broken',
				'title' => \IPS\Member::loggedIn()->language()->addToStack( 'dashboard_email_broken' ),
				'description' => \IPS\Member::loggedIn()->language()->addToStack( 'dashboard_email_broken_desc', TRUE )
			);
		}
		
		$supportAccount = \IPS\Member::load( 'nobody@invisionpower.com', 'email' );
		if ( $supportAccount->member_id )
		{
			$warnings[] = array(
                'key' => 'dashboard_support_account',
				'title' => \IPS\Member::loggedIn()->language()->addToStack( 'dashboard_support_account' ),
				'description' => \IPS\Member::loggedIn()->language()->addToStack( 'dashboard_support_account_desc', TRUE, array( 'sprintf' => array( $supportAccount->acpUrl() ) ) )
			);
		}

		if( !\IPS\Settings::i()->orig_tables_checked )
		{
			/* Check if we have any orig_* tables */
			$tables = \IPS\Db::i()->getTables( 'orig_' . \IPS\Db::i()->prefix );

			/* If we don't have any, we're good. Set a flag so we don't check this every dashboard load. */
			if( !count( $tables ) )
			{
				\IPS\Db::i()->update( 'core_sys_conf_settings', array( 'conf_value' => 1 ), array( 'conf_key=?', 'orig_tables_checked' ) );
				unset( \IPS\Data\Store::i()->settings );
			}
			else
			{
				/* Determine if the background queue task has already been launched */
				try
				{
					\IPS\Db::i()->select( '*', 'core_queue', array( "`key`=?", 'CleanupOrigTables' ) )->first();
					$inProgress = TRUE;
				}
				catch( \UnderflowException $e )
				{
					$inProgress = FALSE;
				}

				/* Launch the task if we clicked to do so */
				if( !$inProgress AND isset( \IPS\Request::i()->cleanupOrigTables ) AND \IPS\Request::i()->cleanupOrigTables )
				{
					\IPS\Task::queue( 'core', 'CleanupOrigTables', array( 'originalCount' => count( $tables ) ), 5 );

					$inProgress = TRUE;

					\IPS\Output::i()->inlineMessage	= \IPS\Member::loggedIn()->language()->addToStack('orig_tables_rebuilding');
				}

				if( !$inProgress )
				{
					$warnings[] = array(
                        'key' => 'block_core_OrigTables',
						'title' => \IPS\Member::loggedIn()->language()->addToStack('block_core_OrigTables'),
						'description' => \IPS\Member::loggedIn()->language()->addToStack('orig_cleanup_suggested')
					);
				}
			}
		}

		/* Check Tasks */
		try
		{
			$task = \IPS\DateTime::ts( \IPS\Db::i()->select( 'next_run', 'core_tasks', array( 'core_tasks.enabled=1 AND (core_plugins.plugin_enabled=1 OR core_applications.app_enabled=1)' ), 'next_run ASC' )
										->join( 'core_applications', array( 'core_applications.app_directory=core_tasks.app' ) )
										->join( 'core_plugins', array( 'core_plugins.plugin_id=core_tasks.plugin' ) )
										->first() );
			$today = new \IPS\DateTime;
			$difference = $today->diff( $task )->h + ( $today->diff( $task )->days * 24 );

			if ( $difference >= 36 )
			{
				if( \IPS\Settings::i()->task_use_cron == 'cron' )
				{
					$warnings[] = array(
                        'key' => 'dashboard_tasksrun_broken',
						'title' => \IPS\Member::loggedIn()->language()->addToStack( 'dashboard_tasksrun_broken' ),
						'description' => \IPS\Member::loggedIn()->language()->addToStack( \IPS\CIC ? 'dashboard_tasks_cron_broken_desc_cic' : 'dashboard_tasks_cron_broken_desc' )
					);
				}
				elseif( \IPS\Settings::i()->task_use_cron == 'web' )
				{
					$warnings[] = array(
                        'key' => 'dashboard_tasksrun_broken',
						'title' => \IPS\Member::loggedIn()->language()->addToStack( 'dashboard_tasksrun_broken' ),
						'description' => \IPS\Member::loggedIn()->language()->addToStack( 'dashboard_tasks_web_broken_desc' )
					);
				}
				else
				{
					$warnings[] = array(
                        'key' => 'dashboard_tasksrun_broken',
						'title' => \IPS\Member::loggedIn()->language()->addToStack( 'dashboard_tasksrun_broken' ),
						'description' => \IPS\Member::loggedIn()->language()->addToStack( 'dashboard_tasks_not_enough_desc' )
					);
				}
			}
		}
		catch ( \UnderflowException $e ) { }

		if ( !\IPS\Settings::i()->site_online AND \IPS\Settings::i()->task_use_cron == 'normal' AND !\IPS\CIC )
		{
			$warnings[] = array(
                'key' => 'dasbhoard_tasks_site_offline',
				'title' => \IPS\Member::loggedIn()->language()->addToStack('dasbhoard_tasks_site_offline'),
				'description' => \IPS\Member::loggedIn()->language()->addToStack('dasbhoard_tasks_site_offline_desc')
			);
		}

		/* Check MySQL and PHP min versions */

		$phpVersion = PHP_VERSION;
		$mysqlVersion = \IPS\Db::i()->server_info;
		$requirements = json_decode( file_get_contents( \IPS\ROOT_PATH . '/applications/core/data/requirements.json' ), TRUE );

		if ( version_compare( $phpVersion, $requirements['php']['recommended'] ) < 0 )
		{
			$warnings[] = array(
				'key' => 'dashboard_recommended_php_version',
				'title' => \IPS\Member::loggedIn()->language()->addToStack('dashboard_recommended_php_version'),
				'description'	=> \IPS\Member::loggedIn()->language()->addToStack( 'requirements_php_version_advice', FALSE, array( 'sprintf' => array( $phpVersion, $requirements['php']['recommended'] ) ) )
			);
		}
		
		if ( version_compare( $mysqlVersion, $requirements['mysql']['recommended'] ) < 0 AND !mb_strpos( $mysqlVersion, "MariaDB" ) )
		{
			$warnings[] = array(
				'key' => 'dasbhoard_required_mysql_version',
				'title' => \IPS\Member::loggedIn()->language()->addToStack('dasbhoard_required_mysql_version'),
				'description'	=> \IPS\Member::loggedIn()->language()->addToStack( 'requirements_mysql_version_advice', FALSE, array( 'sprintf' => array( $mysqlVersion,  $requirements['mysql']['recommended'] ) ) )
			);
		}

		
		/* Don't do this for IN_DEV on localhost */
		//

		/* If there have been more than 10 datastore failures in the last hour, or if an instant test fails, show a message */
		if( !\IPS\Data\Store::i()->test() OR \IPS\Db::i()->select( 'COUNT(*)', 'core_log', array( '`category`=? AND `time`>?', 'datastore', \IPS\DateTime::create()->sub( new \DateInterval( 'PT1H' ) )->getTimestamp() ) )->first() >= 10 )
		{
			/* Have we just recently updated the configuration? If so, ignore this warning for 24 hours */
			if( \IPS\Settings::i()->last_data_store_update < \IPS\DateTime::create()->sub( new \DateInterval( 'PT24H' ) )->getTimestamp() )
			{
				$warnings[] = array(
                    'key' => 'dashboard_datastore_broken',
					'title' => \IPS\Member::loggedIn()->language()->addToStack( 'dashboard_datastore_broken' ),
					'description' => \IPS\Member::loggedIn()->language()->addToStack( 'dashboard_datastore_broken_desc' )
				);
			}
		}
		
		if ( \IPS\CIC )
		{
			try
			{
				$cicEmails = \IPS\Data\Cache::i()->getWithExpire( 'cicEmailUsage', TRUE );
			}
			catch( \OutOfRangeException $e )
			{
				preg_match( '/^\/var\/www\/html\/(.+?)(?:\/|$)/i', \IPS\ROOT_PATH, $matches );
				
				try
				{
					$cicEmails = \IPS\Http\Url::external( "http://ips-cic-email.invisioncic.com/blocked.php?account={$matches[1]}" )->request()->get()->decodeJson();
				}
				catch( \Exception $e )
				{
					/* Request failed, so assume okay and try again in an hour */
					$cicEmails = array( 'status' => 'OKAY', 'time' => time() );
				}
				
				\IPS\Data\Cache::i()->storeWithExpire( 'cicEmailUsage', $cicEmails, \IPS\DateTime::create()->add( new \DateInterval( 'PT1H' ) ), true );
			}
			
			if ( isset( $cicEmails['status'] ) AND $cicEmails['status'] == 'BLOCKED' )
			{
				$warnings[] = array(
                    'key' => 'dashboard_cic_email_quota',
					'title'			=> \IPS\Member::loggedIn()->language()->addToStack( 'dashboard_cic_email_quota' ),
					'description'	=> \IPS\Member::loggedIn()->language()->addToStack( 'dashboard_cic_email_quota_desc' )
				);
			}
		}

		/* Cache Check */
		if ( \IPS\CACHE_METHOD AND \IPS\CACHE_METHOD != 'None' AND \IPS\Data\Cache::i() instanceof \IPS\Data\Cache\None )
		{
			$warnings[] = array(
                'key' => 'dashboard_invalid_cachesetup',
				'title' => \IPS\Member::loggedIn()->language()->addToStack( 'dashboard_invalid_cachesetup' ),
				'description' => \IPS\Member::loggedIn()->language()->addToStack( 'dashboard_invalid_cachesetup_desc' )
			);
		}
		
		/* Temporary thing for to show a warning if Facebook login is enabled but site doesn't use https */
		if ( \IPS\Login\Handler::findMethod( 'IPS\Login\Handler\OAuth2\Facebook' ) and mb_substr( \IPS\Settings::i()->base_url, 0, 8 ) !== 'https://' )
		{
			$warnings[] = array(
                'key' => 'facebook_https',
				'title' => \IPS\Member::loggedIn()->language()->addToStack( time() < mktime( 0, 0, 0, 10, 6, 2018 ) ? 'facebook_https_warning_title' : 'facebook_https_warning_title2' ),
				'description' => \IPS\Member::loggedIn()->language()->addToStack( \IPS\CIC ? 'facebook_https_warning_desc_cic' : 'facebook_https_warning_desc' )
			);
		}


		/* Get new core update available data */
		$update			= \IPS\Application::load( 'core' )->availableUpgrade( TRUE );

		/* Determine if there are any new features to show */
		$latestFeatureId	= \IPS\Application::load( 'core' )->newFeature();
		$features			= array();

		try
		{
			$latestSeenFeature	= \IPS\Db::i()->select( 'feature_id', 'core_members_feature_seen', array( 'member_id=?', \IPS\Member::loggedIn()->member_id ) )->first();
		}
		catch( \UnderflowException $e )
		{
			$latestSeenFeature	= 0;
		}

		if( $latestFeatureId AND ( !$latestSeenFeature OR $latestSeenFeature < $latestFeatureId ) )
		{
			try
			{
				$features = json_encode( \IPS\Http\Url::ips('newFeatures')->setQueryString( array( 'since' => (int) $latestSeenFeature ) )->request()->get()->decodeJson() );

				/* Reset our last feature ID information so this doesn't show on subsequent page loads */
				\IPS\Db::i()->replace( 'core_members_feature_seen', array( 'member_id' => \IPS\Member::loggedIn()->member_id, 'feature_id' => $latestFeatureId ) );
			}
			catch( \RuntimeException $e ){}
		}

		/* Don't show warnings we've hidden */
        if ( isset( \IPS\Request::i()->cookie['acpWarnings_mute'] ) )
        {
            $keys = explode( ',', \IPS\Request::i()->cookie['acpWarnings_mute'] );
            foreach( $warnings as $index => $data )
            {
                if ( isset( $data['key'] ) and in_array( $data['key'], $keys ) )
                {
                    unset( $warnings[ $index ] );
                }
            }
        }

		\IPS\Output::i()->title		= \IPS\Member::loggedIn()->language()->addToStack('dashboard');
		\IPS\Output::i()->customHeader = \IPS\Theme::i()->getTemplate( 'dashboard' )->dashboardHeader( $info, $blocks );
		\IPS\Output::i()->output	= \IPS\Theme::i()->getTemplate( 'dashboard' )->dashboard( $update, $features, $toShow, $blocks, $info, $bulletins, $warnings );
	}

	/**
	 * Reset the latest features we've seen so that we can see them again
	 *
	 * @return void
	 */
	public function whatsNew()
	{
		\IPS\Db::i()->delete( 'core_members_feature_seen', array( 'member_id=?', \IPS\Member::loggedIn()->member_id ) );

		\IPS\Output::i()->redirect( \IPS\Http\Url::internal( "app=core&module=overview&controller=dashboard" ) );
	}

	/**
	 * Return a json-encoded array of the current blocks to show
	 *
	 * @param	bool	$return	Flag to indicate if the array should be returned instead of output
	 * @return	void
	 */
	public function current( $return=FALSE )
	{
		if( \IPS\Settings::i()->acp_dashboard_blocks )
		{
			$blocks = json_decode( \IPS\Settings::i()->acp_dashboard_blocks, TRUE );
		}
		else
		{
			$blocks = array();
		}

		$toShow	= isset( $blocks[ \IPS\Member::loggedIn()->member_id ] ) ? $blocks[ \IPS\Member::loggedIn()->member_id ] : array();

		if( !$toShow OR !isset( $toShow['main'] ) OR !isset( $toShow['side'] ) )
		{
			$toShow	= array(
				'main'		=> array( 'core_AdminNotes', 'core_Registrations', 'core_AwaitingValidation', 'core_BackgroundQueue' ),
				'side'		=> array( 'core_OnlineAdmins', 'core_FailedLogins', 'core_OnlineUsers' ),
				'collapsed'	=> array( 'core_BackgroundQueue' ),
			);

			$blocks[ \IPS\Member::loggedIn()->member_id ]	= $toShow;

			\IPS\Settings::i()->changeValues( array( 'acp_dashboard_blocks' => json_encode( $blocks ) ) );
		}
		/* Upon initial upgrade to 4.3 the key won't exist, so apply to bg queue by default */
		elseif( !array_key_exists( 'collapsed', $toShow ) )
		{
			$toShow['collapsed']	= array( 'core_BackgroundQueue' );
		}

		if( $return === TRUE )
		{
			return $toShow;
		}

		\IPS\Output::i()->output		= json_encode( $toShow );
	}

	/**
	 * Return an individual block's HTML
	 *
	 * @return	void
	 */
	public function getBlock()
	{
		$output		= '';

		/* Loop through the dashboard extensions in the specified application */
		foreach( \IPS\Application::load( \IPS\Request::i()->appKey )->extensions( 'core', 'Dashboard', 'core' ) as $key => $_extension )
		{
			if( \IPS\Request::i()->appKey . '_' . $key == \IPS\Request::i()->blockKey )
			{
				if( method_exists( $_extension, 'getBlock' ) )
				{
					$output	= $_extension->getBlock();
				}

				break;
			}
		}

		\IPS\Output::i()->output	= $output;
	}

	/**
	 * Update our current block configuration/order
	 *
	 * @return	void
	 * @note	When submitted via AJAX, the array should be json-encoded
	 */
	public function update()
	{
		if( \IPS\Settings::i()->acp_dashboard_blocks )
		{
			$blocks = json_decode( \IPS\Settings::i()->acp_dashboard_blocks, TRUE );
		}
		else
		{
			$blocks = array();
		}

		$saveBlocks = \IPS\Request::i()->blocks;
		
		foreach( array( 'main', 'side', 'collapsed' ) as $saveKey )
		{
			if( !isset( $saveBlocks[ $saveKey ] ) )
			{
				$saveBlocks[ $saveKey ]	= array();
			}
		}
		
		$blocks[ \IPS\Member::loggedIn()->member_id ] = $saveBlocks;

		\IPS\Settings::i()->changeValues( array( 'acp_dashboard_blocks' => json_encode( $blocks ) ) );

		if( \IPS\Request::i()->isAjax() )
		{
			\IPS\Output::i()->output = 1;
			return;
		}

		\IPS\Output::i()->redirect( \IPS\Http\Url::internal( "app=core&module=overview&controller=dashboard" ), 'saved' );
	}	
}