<?php
/**
 * @brief		Login Exception Class
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @since		26 Mar 2013
 */

namespace IPS\Login;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * Login Exception Class
 */
class _Exception extends \DomainException
{
	// If two login handlers produce different exceptions, the one with the higher code is shown
	// For example: if internal database returns "NO_ACCOUNT", but external returns "BAD_PASSWORD", the
	// message the user needs to see is of course "Password incorrect" (rather than "Account does not exist")
	const NO_ACCOUNT = 1;
	const INTERNAL_ERROR = 2;
	const REGISTRATION_DENIED_BY_SPAM_SERVICE = 3;
	const REGISTRATION_DISABLED = 4;
	const BAD_PASSWORD = 5;
	const MERGE_SOCIAL_ACCOUNT = 6;
	const ACCOUNT_LOCKED = 7;
	
	/**
	 * @brief	Member
	 */
	public $member = NULL;
	
	/**
	 * @bried	Handler
	 */
	public $handler = NULL;
	
	/**
	 * @brief	Details
	 */
	public $details = NULL;

	/**
	 * Constructor
	 *
	 * @param	string				$message	Message
	 * @param	int					$code		Code
	 * @param	\Exception|NULL		$previous	Previous Exception
	 * @param	\IPS\Member|null	$member		Member
	 * @return	void
	 */
	public function __construct( $message, $code=NULL, $previous=NULL, $member=NULL )
	{
		parent::__construct( $message, $code, $previous );
		$this->member = $member;
	}
}