<?php
/* This file has been removed in 4.1 but we do not want the 4.0 extension to load */
namespace IPS\cms\extensions\core\FrontNavigation;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * Front Navigation Extension: Pages
 */
class _Cms
{
	/**
	 * @deprecated
	 *
	 * @return	bool
	 */
	final public function deprecated()
	{
		return TRUE;
	}
}