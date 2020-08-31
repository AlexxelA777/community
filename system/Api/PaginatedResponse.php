<?php
/**
 * @brief		PaginatedAPI Response
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @since		3 Dec 2015
 */

namespace IPS\Api;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * API Response
 */
class _PaginatedResponse extends Response
{
	/**
	 * @brief	HTTP Response Code
	 */
	public $httpCode;
	
	/**
	 * @brief	Select query
	 */
	protected $select;
	
	/**
	 * @brief	Current page
	 */
	protected $page = 1;
	
	/**
	 * @brief	Results per page
	 */
	protected $resultsPerPage = 25;
	
	/**
	 * @brief	ActiveRecord class
	 */
	protected $activeRecordClass;
	
	/**
	 * @brief	Total Count
	 */
	protected $count;
	
	/**
	 * @brief	The member making the API request or NULL for API Key / client_credentials
	 */
	protected $authorizedMember;
	
	/**
	 * Constructor
	 *
	 * @param	int				$httpCode			HTTP Response code
	 * @param	\IPS\Db\Select	$select				Select query
	 * @param	int				$page				Current page
	 * @param	string			$activeRecordClass	ActiveRecord class
	 * @param	int				$count				Total Count
	 * @param	\IPS\Member|NULL	$authorizedMember	The member making the API request or NULL for API Key / client_credentials
	 * @param	int				$perPage			Number of results per page
	 * @return	void
	 */
	public function __construct( $httpCode, $select, $page, $activeRecordClass, $count, \IPS\Member $authorizedMember = NULL, $perPage=NULL )
	{
		$this->httpCode				= $httpCode;
		$this->page					= (int) $page;
		$this->select				= $select;
		$this->activeRecordClass	= $activeRecordClass;
		$this->count				= (int) $count;
		$this->resultsPerPage		= $perPage ? (int) $perPage : 25;
		$this->authorizedMember		= $authorizedMember;
	}
	
	/**
	 * Data to output
	 *
	 * @return	string
	 */
	public function getOutput()
	{
		$results = array();
		$this->select->query .= \IPS\Db::i()->compileLimitClause( array( ( $this->page - 1 ) * $this->resultsPerPage, $this->resultsPerPage ) );
		
		if ( $this->activeRecordClass )
		{
			foreach ( new \IPS\Patterns\ActiveRecordIterator( $this->select, $this->activeRecordClass ) as $result )
			{
				$results[] = $result->apiOutput( $this->authorizedMember );
			}
		}
		else
		{
			foreach ( $this->select as $result )
			{
				$results[] = $result;
			}
		}
				
		return array(
			'page'			=> $this->page,
			'perPage'		=> $this->resultsPerPage,
			'totalResults'	=> $this->count,
			'totalPages'	=> ceil( $this->count / $this->resultsPerPage ),
			'results'		=> $results
		);
	}
}