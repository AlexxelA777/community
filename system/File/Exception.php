<?php
/**
 * @brief		File Exception Class
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @since		26 Mar 2013
 */

namespace IPS\File;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * File Exception Class
 */
class _Exception extends \RuntimeException
{
	/**
	 * Exception types
	 */
	const CANNOT_OPEN		= 1;
	const DOES_NOT_EXIST	= 2;
	const CANNOT_WRITE		= 3;
	const CANNOT_COPY		= 4;
	const CANNOT_MOVE		= 5;
	const CANNOT_MAKE_DIR	= 6;
	const MISSING_REGION	= 7;

	/**
	 * @brief	File path
	 */
	public $file = NULL;

	/**
	 * Constructor
	 *
	 * @param	string		$file		File path
	 * @param	int			$error		One of the defined exception constants
	 * @return	void
	 */
	public function __construct( $file, $error )
	{
		/* Store the file */
		$this->file = $file;

		/* We use a prettier error message if we have a dispatcher instance */
		if( \IPS\Dispatcher::hasInstance() )
		{
			$message = sprintf( \IPS\Member::loggedIn()->language()->get( 'files-' . $error ), $file );
		}
		else
		{
			$message = array_flip( ( new \ReflectionClass( __CLASS__ ) )->getConstants() )[ $error ];
		}

		parent::__construct( $message, $error );
	}
}