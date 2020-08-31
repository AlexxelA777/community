<?php
/**
 * @brief		Lost Password
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @since		26 Aug 2013
 */

namespace IPS\core\modules\front\system;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * Lost Password
 */
class _lostpass extends \IPS\Dispatcher\Controller
{
	/**
	 * Execute
	 *
	 * @return	void
	 */
	public function execute()
	{
		if ( \IPS\Settings::i()->allow_forgot_password == 'disabled' )
		{
			\IPS\Output::i()->error( 'page_doesnt_exist', '2S151/2', 404, '' );
		}
		
		if ( \IPS\Settings::i()->allow_forgot_password == 'redirect' )
		{
			\IPS\Output::i()->redirect( \IPS\Http\Url::external( \IPS\Settings::i()->allow_forgot_password_target ) );
		}
		
		return parent::execute();
	}	
	
	/**
	 * Manage
	 *
	 * @return	void
	 */
	protected function manage()
	{
		/* Build the form */
		$form =  new \IPS\Helpers\Form( "lostpass", 'request_password' );
		$form->add( new \IPS\Helpers\Form\Email( 'email_address', NULL, TRUE, array( 'bypassProfanity' => TRUE ), function( $val ){
			if( !\IPS\Login::emailIsInUse( $val ) )
			{
				throw new \LogicException( 'lost_pass_no_email' );
			}
		}) );
		
		\IPS\Output::i()->title = \IPS\Member::loggedIn()->language()->addToStack('lost_password');
		
		/* Handle the reset */
		if ( $values = $form->values() )
		{
			/* If using "normal" method and we have an account, and at least one login handler we can process a password change for, we're good */
			$member = \IPS\Member::load( $values['email_address'], 'email' );
			if ( $member->member_id and \IPS\Settings::i()->allow_forgot_password == 'normal' )
			{
				foreach ( \IPS\Login::methods() as $method )
				{
					if ( $method->canChangePassword( $member ) )
					{
						return $this->_sendForgotPasswordEmail( $member );
					}
				}
			}
						
			/* If not, send them to the handler if we can */
			foreach( \IPS\Login::methods() as $method )
			{
				if( $method->emailIsInUse( $values['email_address'] ) === TRUE )
				{
					if ( $url = $method->forgotPasswordUrl() )
					{
						\IPS\Output::i()->redirect( $url );
					}
				}
			}
			
			/* If we have no way to reset the password, can we allow creating a local password as a last attempt? */
			if ( $member->member_id )
			{
				foreach( \IPS\Login::methods() as $method )
				{
					if ( $method instanceof \IPS\Login\Handler\Standard )
					{
						return $this->_sendForgotPasswordEmail( $member );
					}
				}
			}
			
			/* Otherwise, sorry, we can't do this */
			\IPS\Output::i()->error( 'lost_pass_not_possible', '1S151/3', 403, '' );
		}
		
		/* Show form */
		\IPS\Output::i()->sidebar['enabled'] = FALSE;
		\IPS\Output::i()->bodyClasses[] = 'ipsLayout_minimal';
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'system' )->lostPass( $form );
	}
	
	/**
	 * Send forgot password email
	 *
	 * @param	\IPS\Member	$member	The member
	 * @return	void
	 */
	protected function _sendForgotPasswordEmail( \IPS\Member $member )
	{
		/* If we have an existing validation record, we can just reuse it */
		$sendEmail = TRUE;
		try
		{
			$existing = \IPS\Db::i()->select( array( 'vid', 'email_sent' ), 'core_validating', array( 'member_id=? AND lost_pass=1', $member->member_id ) )->first();
			$vid = $existing['vid'];
			
			/* If we sent a lost password email within the last 15 minutes, don't send another one otherwise someone could be a nuisence */
			if ( $existing['email_sent'] and $existing['email_sent'] > ( time() - 900 ) )
			{
				$sendEmail = FALSE;
			}
			else
			{
				\IPS\Db::i()->update( 'core_validating', array( 'email_sent' => time() ), array( 'vid=?', $vid ) );
			}
		}
		catch ( \UnderflowException $e )
		{
			$vid = md5( $member->members_pass_hash . \IPS\Login::generateRandomString() );

			\IPS\Db::i()->insert( 'core_validating', array(
				'vid'         => $vid,
				'member_id'   => $member->member_id,
				'entry_date'  => time(),
				'lost_pass'   => 1,
				'ip_address'  => $member->ip_address,
				'email_sent'  => time(),
			) );
		}
					
		/* Send email */
		if ( $sendEmail )
		{
			\IPS\Email::buildFromTemplate( 'core', 'lost_password_init', array( $member, $vid ), \IPS\Email::TYPE_TRANSACTIONAL )->send( $member );
			$message = "lost_pass_confirm";
		}
		else
		{
			$message = "lost_pass_too_soon";
		}
		
		/* Show confirmation page with further instructions */
		\IPS\Output::i()->sidebar['enabled'] = FALSE;
		\IPS\Output::i()->bodyClasses[] = 'ipsLayout_minimal';
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'system' )->lostPassConfirm( $message );
	}
	
	/**
	 * Validate
	 *
	 * @return	void
	 */
	protected function validate()
	{
		try
		{
			$record = \IPS\Db::i()->select( '*', 'core_validating', array( 'vid=? AND member_id=? AND lost_pass=1', \IPS\Request::i()->vid, \IPS\Request::i()->mid ) )->first();
		}
		catch ( \UnderflowException $e )
		{
			\IPS\Output::i()->error( 'no_validation_key', '2S151/1', 410, '' );
		}
		
		/* Show form for new password */
		$form =  new \IPS\Helpers\Form( "resetpass", 'save' );
		$form->add( new \IPS\Helpers\Form\Password( 'password', NULL, TRUE, array( 'showMeter' => \IPS\Settings::i()->password_strength_meter ) ) );
		$form->add( new \IPS\Helpers\Form\Password( 'password_confirm', NULL, TRUE, array( 'confirm' => 'password' ) ) );

		/* Set new password */
		if ( $values = $form->values() )
		{
			/* Get the member */
			$member = \IPS\Member::load( $record['member_id'] );

			/* Reset the failed logins storage - we don't need to save because the login handler will do that for us later */
			$member->failed_logins		= array();

			/* Now reset the member's password. If no handlers accept the change, create a local password */
			if ( !$member->changePassword( $values['password'], 'lost' ) )
			{
				$member->setLocalPassword( $values['password'] );
				$member->save();
			}
			
			/* Log in, and invalidate all other sessions */
			\IPS\Session::i()->setMember( $member );
			$member->invalidateSessionsAndLogins( \IPS\Session::i()->id );
			\IPS\Member\Device::loadOrCreate( $member )->updateAfterAuthentication( isset( \IPS\Request::i()->cookie['login_key'] ) );
			
			/* Delete validating record and log in */
			\IPS\Db::i()->delete( 'core_validating', array( 'member_id=? AND lost_pass=1', $member->member_id ) );
			
			/* Redirect */
			\IPS\Output::i()->redirect( \IPS\Http\Url::internal( '' ) );
		}

		\IPS\Output::i()->sidebar['enabled'] = FALSE;
		\IPS\Output::i()->bodyClasses[] = 'ipsLayout_minimal';
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'system' )->resetPass( $form );
		\IPS\Output::i()->title = \IPS\Member::loggedIn()->language()->addToStack( 'lost_password' );
	}
}