<?php
/**
 * @brief		subscriptions
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @subpackage	Commerce
 * @since		09 Feb 2018
 */

namespace IPS\nexus\modules\admin\subscriptions;

/* To prevent PHP errors (extending class does not exist) revealing path */
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( isset( $_SERVER['SERVER_PROTOCOL'] ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

/**
 * subscriptions
 */
class _subscriptions extends \IPS\Node\Controller
{
	/**
	 * Node Class
	 */
	protected $nodeClass = 'IPS\nexus\Subscription\Package';
	
	/**
	 * Fetch any additional HTML for this row
	 *
	 * @param	object	$node	Node returned from $nodeClass::load()
	 * @return	NULL|string
	 */
	public function _getRowHtml( $node )
	{
		$active = \IPS\Db::i()->select( 'COUNT(*)', 'nexus_member_subscriptions', array( 'sub_package_id=? and sub_active=1', $node->id ) )->first();
		$inactive = \IPS\Db::i()->select( 'COUNT(*)', 'nexus_member_subscriptions', array( 'sub_package_id=? and sub_active=0', $node->id ) )->first();
		
		return \IPS\Theme::i()->getTemplate( 'subscription', 'nexus' )->rowHtml( $node, $node->priceBlurb(), $active, $inactive );
	}
	
	/**
	 * Execute
	 *
	 * @return	void
	 */
	public function execute()
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'subscriptions_manage' );

		parent::execute();
	}
	
	/**
	 * Manage
	 *
	 * @return	void
	 */
	public function manage()
	{	
		if ( \IPS\Settings::i()->nexus_subs_enabled )
		{
			\IPS\Output::i()->sidebar['actions']['settings'] = array(
					'primary'	=> false,
					'title'	=> 'settings',
					'icon'	=> 'cog',
					'link'	=> \IPS\Http\Url::internal('app=nexus&module=subscriptions&controller=subscriptions&do=settings')
				);
				
			parent::manage();
		}
		else
		{
			\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'subscription' )->disabled();
		}
	}
	
	/**
	 * Convert a package to a subscription
	 *
	 * @return void
	 */
	public function convertToSubscription()
	{
		try
		{
			$package = \IPS\nexus\Package::load( \IPS\Request::i()->id );
		}
		catch( \OutOfRangeException $ex )
		{
			
		}
		
		$renewOptions = array();
		if ( $package->renew_options and $_renewOptions = json_decode( $package->renew_options, TRUE ) and is_array( $_renewOptions ) )
		{
			foreach ( $_renewOptions as $option )
			{
				$costs = array();
				foreach ( $option['cost'] as $cost )
				{
					$costs[ $cost['currency'] ] = new \IPS\nexus\Money( $cost['amount'], $cost['currency'] );
				}
				
				/* Catch any invalid renewal terms, these can occasionally appear from legacy IP.Subscriptions */
				try
				{
					$renewOptions[] = new \IPS\nexus\Purchase\RenewalTerm( $costs, new \DateInterval( "P{$option['term']}" . mb_strtoupper( $option['unit'] ) ), NULL, $option['add'] );
				}
				catch( \Exception $ex) {}
			}
		}
		
		$useRenewals = array_pop( $renewOptions );
		
		$form = new \IPS\Helpers\Form;
		$form->addHeader('nexus_subs_review_pricing');
		$form->add( new \IPS\nexus\Form\Money( 'sp_price', $package->base_price, TRUE ) );
		$form->add( new \IPS\Helpers\Form\YesNo( 'sp_renews', !empty( $useRenewals ), FALSE, array( 'togglesOn' => array( 'sp_renew_options' ) ), NULL, NULL, NULL, 'sp_renews' ) );
		$form->add( new \IPS\nexus\Form\RenewalTerm( 'sp_renew_options', $useRenewals, NULL, array( 'allCurrencies' => TRUE ), NULL, NULL, NULL, 'sp_renew_options' ) );
		$form->add( new \IPS\Helpers\Form\Node( 'sp_tax', (int) $package->tax, FALSE, array( 'class' => 'IPS\nexus\Tax', 'zeroVal' => 'do_not_tax' ) ) );
		$form->addHeader('nexus_subs_after_conversion');
		$form->add( new \IPS\Helpers\Form\YesNo( 'sp_after_conversion_delete', FALSE, FALSE ) );
		
		if ( $values = $form->values() )
		{
			$sub = new \IPS\nexus\Subscription\Package;
			$sub->enabled = 1;
			$sub->tax = $values['sp_tax'] ? $values['p_tax']->id : 0;
			$sub->gateways = ( isset( $values['sp_gateways'] ) and is_array( $values['sp_gateways'] ) ) ? implode( ',', array_keys( $values['sp_gateways'] ) ) : '*';
			$sub->price = json_encode( $values['sp_price'] );
			
			foreach( array( 'primary_group', 'secondary_group') as $thingsWotAreTheSame )
			{
				$sub->$thingsWotAreTheSame = $package->$thingsWotAreTheSame;
			}
			
			/* Renewal options */
			if ( $values['sp_renews'] )
			{
				$renewOptions = array();
				$option = $values['sp_renew_options'];
				$term = $option->getTerm();
				
				$sub->renew_options = json_encode( array(
					'cost'	=> $option->cost,
					'term'	=> $term['term'],
					'unit'	=> $term['unit']
				) );
			}
			else
			{
				$sub->renew_options = '';
			}
			
			$sub->save();

			/* Language stuffs */
			\IPS\Lang::copyCustom( 'nexus', "nexus_package_{$package->id}", "nexus_subs_{$sub->id}" );
			\IPS\Lang::copyCustom( 'nexus', "nexus_package_{$package->id}_desc", "nexus_subs_{$sub->id}_desc" );
			
			/* Purchases */
			foreach( \IPS\Db::i()->select( '*', 'nexus_purchases', array( 'ps_app=? and ps_type=? and ps_active=1 and ps_cancelled=0 and ps_item_id=?', 'nexus', 'package', $package->id ) ) as $purchase )
			{
				try
				{
					$customer = \IPS\nexus\Customer::load( $purchase['ps_member'] );
					
					\IPS\Db::i()->update( 'nexus_purchases', array( 'ps_type' => 'subscription', 'ps_item_id' => $sub->id ), array( 'ps_id=?', $purchase['ps_id'] ) );
					
					$subscription = $sub->addMember( $customer );
					$subscription->purchase_id = $purchase['ps_id'];
					$subscription->invoice_id = $purchase['ps_original_invoice'];
					$subscription->expire = $purchase['ps_expire'];
					$subscription->start = $purchase['ps_start'];
					$subscription->save();
				}
				catch( \Exception $e ) { }
			}
			
			/* Delete original product */
			if ( $values['sp_after_conversion_delete'] )
			{
				$package->delete();
			}
			
			\IPS\Output::i()->redirect( \IPS\Http\Url::internal('app=nexus&module=subscriptions&controller=subscriptions'), 'nexus_package_converted_lovely' );
		}
		
		\IPS\Output::i()->title = \IPS\Member::loggedIn()->language()->addToStack('nexus_subs_convert');
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'subscription', 'nexus' )->convert( $form, $package );
	}
	
	/**
	 * Enable
	 *
	 * @return	void
	 */
	public function enable()
	{
		\IPS\Settings::i()->changeValues( array( 'nexus_subs_enabled' => true ) );
		
		\IPS\Session::i()->log( 'acplog__subscription_settings' );
		
		\IPS\Output::i()->redirect( \IPS\Http\Url::internal('app=nexus&module=subscriptions&controller=subscriptions') );
	}
	
	/**
	 * Add a member for free!
	 *
	 * @return void
	 */
	protected function addMember()
	{
		$form = new \IPS\Helpers\Form;
		$form->add( new \IPS\Helpers\Form\Member( 'nexus_subs_member_to_add', NULL, TRUE, array(), function( $val )
		{
			if ( $val instanceof \IPS\Member )
			{
				try
				{
					$package = \IPS\nexus\Subscription\Package::load( \IPS\Request::i()->id );
					$sub = \IPS\nexus\Subscription::loadByMemberAndPackage( $val, $package, TRUE );
					
					/* We have cannot have duplicate active subscriptions, so error out */
					throw new \InvalidArgumentException('nexus_subs_add_member_already_subscribed');
				}
				catch( \OutOfRangeException $e )
				{
					/* Nothing found, so that's all lovely and good */
				}
			}
		}, NULL, NULL, 'nexus_subs_member_to_add' ) );
		
		if ( $values = $form->values() )
		{
			$sub = \IPS\nexus\Subscription\Package::load( \IPS\Request::i()->id )->addMember( $values['nexus_subs_member_to_add'] );
			$sub->added_manually = 1;
			$sub->save();
			
			\IPS\Output::i()->redirect( \IPS\Http\Url::internal('app=nexus&module=subscriptions&controller=subscriptions'), 'nexus_sub_member_added' );
		}
		
		\IPS\Output::i()->title = \IPS\Member::loggedIn()->language()->addToStack('nexus_subs_add_member');
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'subscription', 'nexus' )->addMemberMessage( $form );
	}
	
	/**
	 * Manage Settings
	 *
	 * @return	void
	 */
	protected function settings()
	{
		$groups = array();
		foreach ( \IPS\Member\Group::groups( FALSE, FALSE ) as $group )
		{
			$groups[ $group->g_id ] = $group->name;
		}
        
		$form = new \IPS\Helpers\Form;

		$form->addHeader('subscription_basic_settings');
		$form->add( new \IPS\Helpers\Form\YesNo( 'nexus_subs_enabled', \IPS\Settings::i()->nexus_subs_enabled, FALSE, array(), NULL, NULL, NULL, 'nexus_subs_enabled' ) );
		$form->add( new \IPS\Helpers\Form\YesNo( 'nexus_subs_register', \IPS\Settings::i()->nexus_subs_register, FALSE, array(), NULL, NULL, NULL, 'nexus_subs_register' ) );
		$form->add( new \IPS\Helpers\Form\YesNo( 'nexus_subs_show_public', \IPS\Settings::i()->nexus_subs_show_public, FALSE, array(), NULL, NULL, NULL, 'nexus_subs_show_public' ) );
		$form->add( new \IPS\Helpers\Form\Number( 'nexus_subs_invoice_grace', \IPS\Settings::i()->nexus_subs_invoice_grace, FALSE, array( ), NULL, NULL, \IPS\Member::loggedIn()->language()->addToStack('days'), 'nexus_subs_invoice_grace' ) );
		$form->add( new \IPS\Helpers\Form\Select( 'nexus_subs_exclude_groups', explode( ',', \IPS\Settings::i()->nexus_subs_exclude_groups ), FALSE, array( 'options' => $groups, 'multiple' => TRUE ) ) );


		$form->addHeader('package_upgrade_downgrade');
		$form->add( new \IPS\Helpers\Form\YesNo( 'nexus_subs_upgrade_toggle', \IPS\Settings::i()->nexus_subs_upgrade > -1, FALSE, array( 'togglesOn' => array( 'nexus_subs_upgrade' ) ) ) );
		$form->add( new \IPS\Helpers\Form\Radio( 'nexus_subs_upgrade', \IPS\Settings::i()->nexus_subs_upgrade, FALSE, array( 'options' => array(
			0	=> 'p_upgrade_charge_none',
			1	=> 'p_upgrade_charge_full',
			2	=> 'p_upgrade_charge_prorate'
		) ), NULL, NULL, NULL, 'nexus_subs_upgrade' ) );
		$form->add( new \IPS\Helpers\Form\YesNo( 'nexus_subs_downgrade_toggle', \IPS\Settings::i()->nexus_subs_downgrade > -1, FALSE, array( 'togglesOn' => array( 'nexus_subs_downgrade' ) ) ) );
		$form->add( new \IPS\Helpers\Form\Radio( 'nexus_subs_downgrade', \IPS\Settings::i()->nexus_subs_downgrade, FALSE, array( 'options' => array(
			0	=> 'p_downgrade_refund_none',
			1	=> 'p_downgrade_refund_full',
			2	=> 'p_downgrade_refund_prorate'
		)), NULL, NULL, NULL, 'nexus_subs_downgrade' ) );
		
		if ( $values = $form->values() )
		{
			if ( ! $values['nexus_subs_upgrade_toggle'] )
			{
				$values['nexus_subs_upgrade'] = -1;
			}
			
			if ( ! $values['nexus_subs_downgrade_toggle'] )
			{
				$values['nexus_subs_downgrade'] = -1;
			}
			
			foreach( array( 'nexus_subs_upgrade_toggle', 'nexus_subs_downgrade_toggle' ) as $field )
			{
				unset( $values[ $field ] );
			}
			
			$values['nexus_subs_exclude_groups'] = implode( ',', $values['nexus_subs_exclude_groups'] );
			
			$form->saveAsSettings( $values );
			
			\IPS\Session::i()->log( 'acplog__nexus_subs_settings' );
			\IPS\Output::i()->redirect( \IPS\Http\Url::internal('app=nexus&module=subscriptions&controller=subscriptions') );
		}
		
		\IPS\Output::i()->title = \IPS\Member::loggedIn()->language()->addToStack('settings');
		\IPS\Output::i()->output = $form;
	}

}