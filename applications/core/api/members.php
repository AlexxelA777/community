<?php
/**
 * @brief		Members API
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @since		3 Dec 2015
 */

namespace IPS\core\api;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * @brief	Members API
 */
class _members extends \IPS\Api\Controller
{
	/**
	 * GET /core/members
	 * Get list of members
	 *
	 * @apiparam	string	sortBy		What to sort by. Can be 'joined', 'name' or leave unspecified for ID
	 * @apiparam	string	sortDir		Sort direction. Can be 'asc' or 'desc' - defaults to 'asc'
	 * @apiparam	string	name		(Partial) user name to search for
	 * @apiparam	string	email		(Partial) Email address to search for
	 * @apiparam	int|array	group		Group ID or IDs to search for
	 * @apiparam	int		page		Page number
	 * @apiparam	int		perPage		Number of results per page - defaults to 25
	 * @return		\IPS\Api\PaginatedResponse<IPS\Member>
	 */
	public function GETindex()
	{
		/* Where clause */
		$where = array( array( 'core_members.name<>?', '' ) );

		/* Are we searching? */
		if( isset( \IPS\Request::i()->name ) )
		{
			$where[] = array( "name LIKE CONCAT( '%', ?, '%' )", \IPS\Request::i()->name );
		}

		if( isset( \IPS\Request::i()->email ) )
		{
			$where[] = array( "email LIKE CONCAT( '%', ?, '%' )", \IPS\Request::i()->email );
		}

		if( isset( \IPS\Request::i()->group ) )
		{
			if( is_array( \IPS\Request::i()->group ) )
			{
				$groups = array_map( function( $value ){ return intval( $value ); }, \IPS\Request::i()->group );
				$where[] = array( "(member_group_id IN(" . implode( ',', $groups ) . ") OR " . \IPS\Db::i()->findInSet( 'mgroup_others', $groups ) . ")" );
			}
			elseif( \IPS\Request::i()->group )
			{
				$where[] = array( "(member_group_id=" . intval( \IPS\Request::i()->group ) . ") OR (" . \IPS\Db::i()->findInSet( 'mgroup_others', array( intval( \IPS\Request::i()->group ) ) ) . ")" );
			}
		}

		/* Sort */
		$sortBy = ( isset( \IPS\Request::i()->sortBy ) and in_array( \IPS\Request::i()->sortBy, array( 'name', 'joined' ) ) ) ? \IPS\Request::i()->sortBy : 'member_id';
		$sortDir = ( isset( \IPS\Request::i()->sortDir ) and in_array( mb_strtolower( \IPS\Request::i()->sortDir ), array( 'asc', 'desc' ) ) ) ? \IPS\Request::i()->sortDir : 'asc';

		/* Return */
		return new \IPS\Api\PaginatedResponse(
			200,
			\IPS\Db::i()->select( '*', 'core_members', $where, "{$sortBy} {$sortDir}" ),
			isset( \IPS\Request::i()->page ) ? \IPS\Request::i()->page : 1,
			'IPS\Member',
			\IPS\Db::i()->select( 'COUNT(*)', 'core_members', $where )->first(),
			$this->member,
			isset( \IPS\Request::i()->perPage ) ? \IPS\Request::i()->perPage : NULL
		);
	}
	
	/**
	 * GET /core/members/{id}
	 * Get information about a specific member
	 *
	 * @param		int		$id			ID Number
	 * @apiparam	array	otherFields	An array of additional non-standard fields to return via the REST API
	 * @throws		1C292/2	INVALID_ID	The member ID does not exist
	 * @return		\IPS\Member
	 */
	public function GETitem( $id )
	{
		try
		{
			$member = \IPS\Member::load( $id );
			if ( !$member->member_id )
			{
				throw new \OutOfRangeException;
			}
			
			return new \IPS\Api\Response( 200, $member->apiOutput( $this->member, ( isset( \IPS\Request::i()->otherFields ) ) ? \IPS\Request::i()->otherFields : NULL ) );
		}
		catch ( \OutOfRangeException $e )
		{
			throw new \IPS\Api\Exception( 'INVALID_ID', '1C292/2', 404 );
		}
	}

	/**
	 * Create or update member
	 *
	 * @param	\IPS\Member	$member			The member
	 * @throws		1C292/4	USERNAME_EXISTS	The username provided is already in use
	 * @throws		1C292/5	EMAIL_EXISTS	The email address provided is already in use
	 * @throws		1C292/6	INVALID_GROUP	The group ID provided is not valid
	 * @return		\IPS\Member
	 */
	protected function _createOrUpdate( $member )
	{
		if ( isset( \IPS\Request::i()->name ) and \IPS\Request::i()->name != $member->name )
		{
			$existingUsername = \IPS\Member::load( \IPS\Request::i()->name, 'name' );
			if ( !$existingUsername->member_id )
			{				
				$member->logHistory( 'core', 'display_name', array( 'old' => $member->name, 'new' => \IPS\Request::i()->name, 'by' => 'api' ) );
				$member->name = \IPS\Request::i()->name;
			}
			else
			{
				throw new \IPS\Api\Exception( 'USERNAME_EXISTS', '1C292/4', 403 );
			}
		}

		if ( isset( \IPS\Request::i()->email ) and \IPS\Request::i()->email != $member->email )
		{
			$existingEmail = \IPS\Member::load( \IPS\Request::i()->email, 'email' );
			if ( !$existingEmail->member_id )
			{
				$member->logHistory( 'core', 'email_change', array( 'old' => $member->name, 'new' => \IPS\Request::i()->name, 'by' => 'api' ) );
				$member->email = \IPS\Request::i()->email;
				$member->invalidateSessionsAndLogins();
			}
			else
			{
				throw new \IPS\Api\Exception( 'EMAIL_EXISTS', '1C292/5', 403 );
			}
		}

		if ( isset( \IPS\Request::i()->group ) )
		{
			try
			{
				$group = \IPS\Member\Group::load( \IPS\Request::i()->group );
				$member->member_group_id = $group->g_id;
			}
			catch ( \OutOfRangeException $e )
			{
				throw new \IPS\Api\Exception( 'INVALID_GROUP', '1C292/6', 403 );
			}
		}

		if( isset( \IPS\Request::i()->secondaryGroups ) AND is_array( \IPS\Request::i()->secondaryGroups ) )
		{
			foreach( \IPS\Request::i()->secondaryGroups as $groupId )
			{
				try
				{
					$group = \IPS\Member\Group::load( $groupId );
				}
				catch ( \OutOfRangeException $e )
				{
					throw new \IPS\Api\Exception( 'INVALID_GROUP', '1C292/7', 403 );
				}
			}

			$member->mgroup_others = implode( ',', \IPS\Request::i()->secondaryGroups );
		}
		elseif( isset( \IPS\Request::i()->secondaryGroups ) AND \IPS\Request::i()->secondaryGroups == '' )
		{
			$member->mgroup_others = '';
		}

		if( isset( \IPS\Request::i()->registrationIpAddress ) AND filter_var( \IPS\Request::i()->registrationIpAddress, FILTER_VALIDATE_IP ) )
		{
			$member->ip_address	= \IPS\Request::i()->registrationIpAddress;
		}

		if( isset( \IPS\Request::i()->rawProperties ) AND is_array( \IPS\Request::i()->rawProperties ) )
		{
			foreach( \IPS\Request::i()->rawProperties as $property => $value )
			{
				$member->$property	= $value;
			}
		}

		if ( isset( \IPS\Request::i()->password ) )
		{
			/* Setting the password for the just created member shouldn't be logged to the member history and shouldn't fire the onPassChange Sync call */
			$logPasswordChange = TRUE;
			if ( $member->member_id )
			{
				$logPasswordChange = FALSE;
			}
			$member->setLocalPassword( \IPS\Request::i()->password );
			$member->save();

			if ( $logPasswordChange )
			{
				$member->memberSync( 'onPassChange', array( \IPS\Request::i()->password ) );
				$member->logHistory( 'core', 'password_change', 'api' );
			}

			$member->invalidateSessionsAndLogins();
		}
		else
		{
			$member->save();
		}

		/* Validation stuff */
		if( isset( \IPS\Request::i()->validated ) )
		{
			/* If the member is currently validating and we are setting the validated flag to true, then complete the validation */
			if( \IPS\Request::i()->validated == 1 AND $member->members_bitoptions['validating'] )
			{
				$member->validationComplete();
			}
			/* If the member is not currently validating, and we set the validated flag to false AND validation is enabled, mark the member validating */
			elseif( \IPS\Request::i()->validated == 0 AND !$member->members_bitoptions['validating'] AND \IPS\Settings::i()->reg_auth_type != 'none' )
			{
				$member->postRegistration();
			}
		}

		/* Any custom fields? */
		if( isset( \IPS\Request::i()->customFields ) )
		{
			/* Profile Fields */
			try
			{
				$profileFields = \IPS\Db::i()->select( '*', 'core_pfields_content', array( 'member_id=?', $member->member_id ) )->first();
			}
			catch( \UnderflowException $e )
			{
				$profileFields	= array();
			}

			/* If \IPS\Db::i()->select()->first() has only one column, then the contents of that column is returned. We do not want this here. */
			if ( !is_array( $profileFields ) )
			{
				$profileFields = array();
			}

			$profileFields['member_id'] = $member->member_id;

			foreach ( \IPS\Request::i()->customFields as $k => $v )
			{
				$profileFields[ 'field_' . $k ] = $v;
			}

			\IPS\Db::i()->replace( 'core_pfields_content', $profileFields );

			$member->changedCustomFields = $profileFields;
			$member->save();
		}

		return $member;
	}

	/**
	 * POST /core/members
	 * Create a member. Requires the standard login handler to be enabled
	 *
	 * @apiclientonly
	 * @apiparam	string	name			Username
	 * @apiparam	string	email			Email address
	 * @apiparam	string	password		Password (standard login handler only)
	 * @apiparam	int		group			Group ID number
	 * @apiparam	string	registrationIpAddress		IP Address
	 * @apiparam	array	secondaryGroups	Secondary group IDs, or empty value to reset secondary groups
	 * @apiparam	object	customFields	Array of custom fields as fieldId => fieldValue
	 * @apiparam	int		validated		Flag to indicate if the account is validated (1) or not (0)
	 * @apiparam	array	rawProperties	Key => value object of member properties to set. Note that values will be set exactly as supplied without validation. USE AT YOUR OWN RISK.
	 * @throws		1C292/4	USERNAME_EXISTS			The username provided is already in use
	 * @throws		1C292/5	EMAIL_EXISTS			The email address provided is already in use
	 * @throws		1C292/6	INVALID_GROUP			The group ID provided is not valid
	 * @throws		1C292/7	INVALID_GROUP			A secondary group ID provided is not valid
	 * @throws		1C292/8	NO_USERNAME_OR_EMAIL	No Username or Email Address was provided for the account
	 * @throws		1C292/9	NO_PASSWORD				No password was provided for the account
	 * @return		\IPS\Member
	 */
	public function POSTindex()
	{
		/* One of these must be provided to ensure user can log in. */
		if ( !isset( \IPS\Request::i()->name ) AND !isset( \IPS\Request::i()->email ) )
		{
			throw new \IPS\Api\Exception( 'NO_USERNAME_OR_EMAIL', '1C292/8', 403 );
		}

		/* This is required as there is no other way to allow the account to be authenticated when it is created via the API */
		if ( !isset( \IPS\Request::i()->password ) )
		{
			throw new \IPS\Api\Exception( 'NO_PASSWORD', '1C292/9', 403 );
		}

		$member = new \IPS\Member;
		$member->member_group_id = \IPS\Settings::i()->member_group;
		$member->members_bitoptions['created_externally'] = TRUE;
		
		$member = $this->_createOrUpdate( $member );

		return new \IPS\Api\Response( 201, $member->apiOutput( $this->member ) );
	}

	/**
	 * POST /core/members/{id}
	 * Edit a member
	 *
	 * @apiclientonly
	 * @apiparam	string	name			Username
	 * @apiparam	string	email			Email address
	 * @apiparam	string	password		Password (standard login handler only)
	 * @apiparam	int		group			Group ID number
	 * @apiparam	string	registrationIpAddress		IP Address
	 * @apiparam	array	secondaryGroups	Secondary group IDs, or empty value to reset secondary groups
	 * @apiparam	object	customFields	Array of custom fields as fieldId => fieldValue
	 * @apiparam	int		validated		Flag to indicate if the account is validated (1) or not (0)
	 * @apiparam	array	rawProperties	Key => value object of member properties to set. Note that values will be set exactly as supplied without validation. USE AT YOUR OWN RISK.
	 * @param		int		$id			ID Number
	 * @throws		2C292/7	INVALID_ID	The member ID does not exist
	 * @throws		1C292/4	USERNAME_EXISTS	The username provided is already in use
	 * @throws		1C292/5	EMAIL_EXISTS	The email address provided is already in use
	 * @throws		1C292/6	INVALID_GROUP	The group ID provided is not valid
	 * @throws		1C292/7	INVALID_GROUP	A secondary group ID provided is not valid
	 * @return		\IPS\Member
	 */
	public function POSTitem( $id )
	{
		try
		{
			$member = \IPS\Member::load( $id );
			if ( !$member->member_id )
			{
				throw new \OutOfRangeException;
			}
			
			$oldPrimaryGroup = $member->member_group_id;
			$oldSecondaryGroups = array_unique( array_filter( explode( ',', $member->mgroup_others ) ) );
			$member = $this->_createOrUpdate( $member );
			
			if ( $oldPrimaryGroup != $member->member_group_id )
			{
				$member->logHistory( 'core', 'group', array( 'type' => 'primary', 'by' => 'api', 'apiKey' => $this->apiKey ? $this->apiKey->id : NULL, 'client' => $this->client ? $this->client->client_id : NULL, 'old' => $oldPrimaryGroup, 'new' => $member->member_group_id ), $this->member ?: FALSE );
			}
			$newSecondaryGroups = array_unique( array_filter( explode( ',', $member->mgroup_others ) ) );
			if ( array_diff( $oldSecondaryGroups, $newSecondaryGroups ) or array_diff( $newSecondaryGroups, $oldSecondaryGroups ) )
			{
				$member->logHistory( 'core', 'group', array( 'type' => 'secondary', 'by' => 'api', 'apiKey' => $this->apiKey ? $this->apiKey->id : NULL, 'client' => $this->client ? $this->client->client_id : NULL, 'old' => $oldSecondaryGroups, 'new' => $newSecondaryGroups ), $this->member ?: FALSE );
			}

			return new \IPS\Api\Response( 200, $member->apiOutput( $this->member ) );
		}
		catch ( \OutOfRangeException $e )
		{
			throw new \IPS\Api\Exception( 'INVALID_ID', '2C292/7', 404 );
		}
	}
	
	/**
	 * DELETE /core/members/{id}
	 * Deletes a member
	 *
	 * @apiclientonly
	 * @param		int		$id			ID Number
	 * @throws		1C292/2	INVALID_ID	The member ID does not exist
	 * @return		void
	 */
	public function DELETEitem( $id )
	{
		try
		{
			$member = \IPS\Member::load( $id );
			if ( !$member->member_id )
			{
				throw new \OutOfRangeException;
			}
			
			$member->delete();
			
			return new \IPS\Api\Response( 200, NULL );
		}
		catch ( \OutOfRangeException $e )
		{
			throw new \IPS\Api\Exception( 'INVALID_ID', '1C292/2', 404 );
		}
	}

	/**
	 * GET /core/members/{id}/follows
	 * Get list of items a member is following
	 *
	 * @param		int		$id			ID Number
	 * @apiparam	int		page		Page number
	 * @apiparam	int		perPage		Number of results per page - defaults to 25
	 * @return		\IPS\Api\PaginatedResponse<IPS\core\Followed\Follow>
	 * @throws		2C292/F	NO_PERMISSION	The authorized user does not have permission to view the follows
	 * @throws		2C292/I	INVALID_ID		The member could not be found
	 */
	public function GETitem_follows( $id )
	{
		try
		{
			/* Load member */
			$member = \IPS\Member::load( $id );
			if( !$member->member_id )
			{
				throw new \OutOfRangeException;
			}

			/* We can only adjust follows for ourself, if we are an authorized member */
			if ( $this->member and $member->member_id != $this->member->member_id )
			{
				throw new \IPS\Api\Exception( 'NO_PERMISSION', '2C292/F', 403 );
			}

			/* Return */
			return new \IPS\Api\PaginatedResponse(
				200,
				\IPS\Db::i()->select( '*', 'core_follow', array( 'follow_member_id=?', $member->member_id ), "follow_added ASC" ),
				isset( \IPS\Request::i()->page ) ? \IPS\Request::i()->page : 1,
				'IPS\core\Followed\Follow',
				\IPS\Db::i()->select( 'COUNT(*)', 'core_follow', array( 'follow_member_id=?', $member->member_id ) )->first(),
				$this->member,
				isset( \IPS\Request::i()->perPage ) ? \IPS\Request::i()->perPage : NULL
			);
		}
		catch ( \OutOfRangeException $e )
		{
			throw new \IPS\Api\Exception( 'INVALID_ID', '2C292/I', 404 );
		}
	}

	/**
	 * POST /core/members/{id}/follows
	 * Store a new follow for the member
	 *
	 * @param		int		$id			ID Number
	 * @reqapiparam	string	followApp	Application of the content to follow
	 * @reqapiparam	string	followArea	Area of the content to follow
	 * @reqapiparam	int		followId	ID of the content to follow
	 * @apiparam	bool	followAnon	Whether or not to follow anonymously
	 * @apiparam	bool	followNotify	Whether or not to receive notifications
	 * @apiparam	string	followType		Type of notification to receive (immediate=send a notification immediately, daily=daily notification digest, weekly=weekly notification digest)
	 * @return		\IPS\core\Followed\Follow
	 * @throws		2C292/G	NO_PERMISSION	The authorized user does not have permission to view the follows
	 * @throws		2C292/H	INVALID_ID		The member could not be found
	 * @throws		2C292/J	INVALID_CONTENT	The app, area or content ID could not be found
	 */
	public function POSTitem_follows( $id )
	{
		try
		{
			/* Load member */
			$member = \IPS\Member::load( $id );
			if( !$member->member_id )
			{
				throw new \OutOfRangeException;
			}

			/* We can only adjust follows for ourself, if we are an authorized member */
			if ( $this->member and $member->member_id != $this->member->member_id )
			{
				throw new \IPS\Api\Exception( 'NO_PERMISSION', '2C292/G', 403 );
			}

			/* Make sure follow app/area/id is valid (Phil I'm looking at you) */
			try
			{
				$classToFollow	= 'IPS\\' . \IPS\Request::i()->followApp . '\\' . mb_ucfirst( \IPS\Request::i()->followArea );

				if( !class_exists( $classToFollow ) )
				{
					throw new \OutOfRangeException;
				}

				$thingToFollow	= $classToFollow::load( \IPS\Request::i()->followId );
			}
			catch( \Exception $e )
			{
				throw new \IPS\Api\Exception( 'INVALID_CONTENT', '2C292/J', 404 );
			}

			/* If we are already following this, update instead of insert */
			try
			{
				$follow = \IPS\core\Followed\Follow::load( md5( \IPS\Request::i()->followApp . ';' . \IPS\Request::i()->followArea . ';' . \IPS\Request::i()->followId . ';' . $member->member_id ) );
			}
			catch( \OutOfRangeException $e )
			{
				$follow = new \IPS\core\Followed\Follow;
				$follow->member_id	= $member->member_id;
				$follow->app		= \IPS\Request::i()->followApp;
				$follow->area		= \IPS\Request::i()->followArea;
				$follow->rel_id		= \IPS\Request::i()->followId;
			}

			$follow->is_anon	= ( isset( \IPS\Request::i()->followAnon ) ) ? (int) \IPS\Request::i()->followAnon : 0;
			$follow->notify_do	= ( isset( \IPS\Request::i()->followType ) AND \IPS\Request::i()->followType == 'none' ) ? 0 : ( ( isset( \IPS\Request::i()->followNotify ) ) ? (int) \IPS\Request::i()->followNotify : 1 );
			$follow->notify_freq	= ( isset( \IPS\Request::i()->followType ) AND in_array( \IPS\Request::i()->followType, array( 'none', 'immediate', 'daily', 'weekly' ) ) ) ? \IPS\Request::i()->followType : 'immediate';
			$follow->save();

			/* If we're following a club, follow all nodes in the club automatically */
			if( $follow->app == 'core' and $follow->area == 'club' )
			{
				$thing = \IPS\Member\Club::loadAndCheckPerms( $follow->rel_id );
				
				foreach ( $thing->nodes() as $node )
				{
					$itemClass = $node['node_class']::$contentItemClass;
					$followApp = $itemClass::$application;
					$followArea = mb_strtolower( mb_substr( $node['node_class'], mb_strrpos( $node['node_class'], '\\' ) + 1 ) );

					/* If we are already following this, update instead of insert */
					try
					{
						$nodeFollow = \IPS\core\Followed\Follow::load( md5( $followApp . ';' . $followArea . ';' . $node['node_id'] . ';' . $member->member_id ) );
					}
					catch( \OutOfRangeException $e )
					{
						$nodeFollow = new \IPS\core\Followed\Follow;
						$nodeFollow->member_id	= $member->member_id;
						$nodeFollow->app		= $followApp;
						$nodeFollow->area		= $followArea;
						$nodeFollow->rel_id		= $node['node_id'];
					}
					
					$nodeFollow->is_anon	= $follow->is_anon;
					$nodeFollow->notify_do	= $follow->notify_do;
					$nodeFollow->notify_freq	= $follow->notify_freq;
					$nodeFollow->save();
				}
			}

			return new \IPS\Api\Response( 200, $follow->apiOutput( $this->member ) );
		}
		catch ( \OutOfRangeException $e )
		{
			throw new \IPS\Api\Exception( 'INVALID_ID', '2C292/H', 404 );
		}
	}

	/**
	 * DELETE /core/members/{id}/follows
	 * Delete a follow for the member
	 *
	 * @param		int		$id			ID Number
	 * @apiparam	string	followKey	Follow Key
	 * @throws		2C292/C	INVALID_ID			The member could not be found
	 * @throws		2C292/E	INVALID_FOLLOW_KEY	The follow does not exist or does not belong to this member
	 * @throws		2C292/D	NO_PERMISSION		The authorized user does not have permission to delete the follow
	 * @return		void
	 */
	public function DELETEitem_follows( $id )
	{
		try
		{
			/* Load member */
			$member = \IPS\Member::load( $id );
			if( !$member->member_id )
			{
				throw new \OutOfRangeException;
			}

			/* We can only adjust follows for ourself, if we are an authorized member */
			if ( $this->member and $member->member_id != $this->member->member_id )
			{
				throw new \IPS\Api\Exception( 'NO_PERMISSION', '2C292/D', 403 );
			}
			
			/* Load our follow, and make sure it belongs to the specified member */
			try
			{
				if( !isset( \IPS\Request::i()->followKey ) )
				{
					throw new \UnderflowException;
				}

				$follow = \IPS\Db::i()->select( '*', 'core_follow', array( 'follow_id=?', \IPS\Request::i()->followKey ) )->first();

				if( $follow['follow_member_id'] != $member->member_id )
				{
					throw new \UnderflowException;
				}
			}
			catch( \UnderflowException $e )
			{
				throw new \IPS\Api\Exception( 'INVALID_FOLLOW_KEY', '2C292/E', 404 );
			}

			/* Unfollow */
			\IPS\Db::i()->delete( 'core_follow', array( 'follow_id=?', \IPS\Request::i()->followKey ) );

			/* If this is a club, unfollow all nodes in the club too */
			if( $follow['follow_app'] == 'core' AND $follow['follow_area'] == 'club' )
			{
				$class = 'IPS\Member\Club';

				try
				{
					$thing = $class::loadAndCheckPerms( $follow['follow_rel_id'] );

					foreach ( $thing->nodes() as $node )
					{
						$itemClass = $node['node_class']::$contentItemClass;
						$followApp = $itemClass::$application;
						$followArea = mb_strtolower( mb_substr( $node['node_class'], mb_strrpos( $node['node_class'], '\\' ) + 1 ) );
						
						\IPS\Db::i()->delete( 'core_follow', array( 'follow_id=? AND follow_member_id=?', md5( $followApp . ';' . $followArea . ';' . $node['node_id'] . ';' .  $member->member_id ), $member->member_id ) );
					}
				}
				catch ( \OutOfRangeException $e ){}
			}

			return new \IPS\Api\Response( 200, NULL );
		}
		catch ( \OutOfRangeException $e )
		{
			throw new \IPS\Api\Exception( 'INVALID_ID', '2C292/C', 404 );
		}
	}
}