<?php
/**
 * @brief		Download categories API
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @subpackage	Downloads
 * @since		3 Apr 2017
 */

namespace IPS\downloads\api;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * @brief	Downloads Category API
 */
class _categories extends \IPS\Node\Api\NodeController
{
	/**
	 * Class
	 */
	protected $class = 'IPS\downloads\Category';

	/**
	 * GET /downloads/category
	 * Get list of categories
	 *
	 * @return		\IPS\Api\PaginatedResponse<IPS\downloads\Category>
	 */
	public function GETindex()
	{
		/* Return */
		return $this->_list();
	}

	/**
	 * GET /downloads/category/{id}
	 * Get specific category
	 *
	 * @param		int		$id			ID Number
	 *
	 * @return		\IPS\Api\PaginatedResponse<IPS\downloads\Category>
	 */
	public function GETitem( $id )
	{
		/* Return */
		return $this->_view( $id );
	}

	/**
	 * POST /downloads/category
	 * Create a category
	 *
	 * @apiparam	int|null	parent					The ID number of the parent the category should be created in. NULL for root.
	 * @apiparam	int			moderation				Files must be approved?
	 * @apiparam	int			moderation_edits		New versions must be re-approved?
	 * @apiparam	int			allowss					Allow screenshots?
	 * @apiparam	int			reqss					Require screenshots?
	 * @apiparam	int			comments				Allow comments?
	 * @apiparam	int			comments_moderation		Comments must be approved?
	 * @apiparam	int			reviews					Allow reviews?
	 * @apiparam	int			reviews_mod				Reviews must be approved?
	 * @apiparam	int			reviews_download		Files must be downloaded before a review can be left?
	 *
	 * @return		\IPS\downloads\Category
	 */
	public function POSTindex()
	{
		return new \IPS\Api\Response( 201, $this->_create()->apiOutput( $this->member ) );
	}

	/**
	 * POST /downloads/category/{id}
	 * Edit a category
	 * 
	 * @apiparam	int|null	parent					The ID number of the parent the category should be created in. NULL for root.
	 * @apiparam	int			moderation				Files must be approved?
	 * @apiparam	int			moderation_edits		New versions must be re-approved?
	 * @apiparam	int			allowss					Allow screenshots?
	 * @apiparam	int			reqss					Require screenshots?
	 * @apiparam	int			comments				Allow comments?
	 * @apiparam	int			comments_moderation		Comments must be approved?
	 * @apiparam	int			reviews					Allow reviews?
	 * @apiparam	int			reviews_mod				Reviews must be approved?
	 * @apiparam	int			reviews_download		Files must be downloaded before a review can be left?
	 *
	 * @return		\IPS\downloads\Category
	 */
	public function POSTitem( $id )
	{
		$class = $this->class;
		$category = $class::load( $id );
		
		return new \IPS\Api\Response( 201, $this->_createOrUpdate( $category )->apiOutput( $this->member ) );
	}

	/**
	 * DELETE /downloads/category/{id}
	 * Delete a category
	 *
	 * @param		int		$id			ID Number
	 * @return		void
	 */
	public function DELETEitem( $id )
	{
		return $this->_delete( $id );
	}

	/**
	 * Create or update node
	 *
	 * @param	\IPS\node\Model	$category				The node
	 * @return	\IPS\node\Model
	 */
	protected function _createOrUpdate( \IPS\node\Model $category )
	{
		if ( !\IPS\Request::i()->title )
		{
			throw new \IPS\Api\Exception( 'NO_TITLE', '', 400 );
		}

		foreach ( array( 'title' => "downloads_category_{$category->id}", 'description' => "downloads_category_{$category->id}_desc" ) as $fieldKey => $langKey )
		{
			if ( isset( \IPS\Request::i()->$fieldKey ) )
			{
				\IPS\Lang::saveCustom( 'downloads', $langKey, \IPS\Request::i()->$fieldKey );

				if ( $fieldKey === 'title' )
				{
					$category->name_furl = \IPS\Http\Url\Friendly::seoTitle( \IPS\Request::i()->$fieldKey );
				}
			}
		}

		$category->parent = (int) \IPS\Request::i()->parent?: 0;

		foreach ( array( 'moderation', 'moderation_edits', 'allowss', 'reqss', 'comments', 'comment_moderation', 'reviews', 'reviews_mod', 'reviews_download' ) as $k )
		{
			if ( isset( \IPS\Request::i()->$k ) )
			{
				$category->bitoptions[ $k ] = \IPS\Request::i()->$k;
			}
		}

		return parent::_createOrUpdate( $category );
	}
}