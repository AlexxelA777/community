<?php
/**
 * @brief		Stripe Webhook Handler
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @subpackage	Nexus
 * @since		20 Jul 2017
 */

define('REPORT_EXCEPTIONS', TRUE);
$body = trim( @file_get_contents('php://input') );
$data = json_decode( $body, TRUE );
require_once '../../../../init.php';
\IPS\Session\Front::i();

function loadTransaction( $returnUrl )
{
	$url = new \IPS\Http\Url( $returnUrl );
	$transaction = \IPS\nexus\Transaction::load( $url->queryString['nexusTransactionId'] );
		
	$settings = json_decode( $transaction->method->settings, TRUE );
	if ( !validate( $settings['webhook_secret'] ) )
	{
		throw new \Exception('INVALID_SIGNING_SECRET');
	}
	
	return $transaction;
}

function validate( $correctSigningSecret )
{
	global $body;
	
	if ( !$correctSigningSecret )
	{
		return TRUE; // In case they upgraded and haven't provided one
	}
	
	if ( isset( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) )
	{
		foreach ( explode( ',', $_SERVER['HTTP_STRIPE_SIGNATURE'] ) as $row )
		{
			list( $k, $v ) = explode( '=', trim( $row ) );
			$sig[ trim( $k ) ][] = trim( $v );
		}
		
		$signedPayload = $sig['t'][0] . '.' . $body;
		$signature = hash_hmac( 'sha256', $signedPayload, $correctSigningSecret );
		
		return in_array( $signature, $sig['v1'] );
	}
	else
	{
		return FALSE;
	}
}

try
{
	/* source.chargeable means we're ready to charge (then we need to wait for charge.succeeded) */
	if ( isset( $data['type'] ) and $data['type'] === 'source.chargeable' )
	{
		/* Is this a validation? */
		if ( isset( $data['data']['object']['owner']['email'] ) and $data['data']['object']['owner']['email'] === 'webhook-validate@example.com' and isset( $data['data']['object']['metadata']['method'] ) )
		{
			$method = \IPS\nexus\Gateway::load( $data['data']['object']['metadata']['method'] );
			$settings = json_decode( $method->settings, TRUE );
			if ( !isset( $settings['webhook_verified'] ) )
			{
				if ( validate( $settings['webhook_secret'] ) )
				{
					$settings['webhook_verified'] = $settings['webhook_secret'];
				}
				else
				{
					$settings['webhook_verified'] = 'ERR';
				}
				$method->settings = json_encode( $settings );
				$method->save();
			}
			exit;
		}
		
		/* Don't need to do anything for cards, since this is only for asynchronous payments */
		if ( isset( $data['data']['object']['type'] ) and in_array( $data['data']['object']['type'], array( 'card' ) ) )
		{
			\IPS\Output::i()->sendOutput( 'NO_PROCESSING_REQUIRED', 200, 'text/plain' );
			exit;
		}
		
		/* Load the transaction */
		if ( isset( $data['data']['object']['metadata']['Transaction ID'] ) )
		{
			$transaction = \IPS\nexus\Transaction::load( $data['data']['object']['metadata']['Transaction ID'] );
		}
		elseif ( isset( $data['data']['object']['redirect']['return_url'] ) )
		{
			$transaction = loadTransaction( $data['data']['object']['redirect']['return_url'] );
		}
		else
		{
			try
			{
				$where = array( array( 't_gw_id=?', $data['data']['object']['id'] ) );
				if ( isset( $data['data']['object']['metadata']['Invoice ID'] ) )
				{
					$where[] = array( 't_invoice=?', $data['data']['object']['metadata']['Invoice ID'] );
				}
				
				$transaction = \IPS\nexus\Transaction::constructFromData( \IPS\Db::i()->select( '*', 'nexus_transactions', $where )->first() );
			}
			catch ( \UnderflowException $e )
			{
				\IPS\Output::i()->sendOutput( 'COULD_NOT_FIND_TRANSACTION', 403, 'text/plain' );
				exit;
			}
		}
		if ( $transaction->status !== \IPS\nexus\Transaction::STATUS_PENDING and $transaction->status !== \IPS\nexus\Transaction::STATUS_WAITING )
		{
			if ( $transaction->status === \IPS\nexus\Transaction::STATUS_GATEWAY_PENDING )
			{
				\IPS\Output::i()->sendOutput( 'ALREADY_PROCESSED', 200, 'text/plain' );
			}
			else
			{
				\IPS\Output::i()->sendOutput( 'BAD_STATUS', 403, 'text/plain' );
			}
			exit;
		}
		
		/* Load the source */
		$source = $transaction->method->api( 'sources/' . preg_replace( '/[^A-Z0-9_]/i', '', $data['data']['object']['id'] ) );
		if ( $source['client_secret'] != $data['data']['object']['client_secret'] )
		{
			\IPS\Output::i()->sendOutput( 'BAD_SECRET', 403, 'text/plain' );
			exit;
		}
		
		/* Check we're not just going to refuse this */
		$maxMind = NULL;
		if ( \IPS\Settings::i()->maxmind_key and ( !\IPS\Settings::i()->maxmind_gateways or \IPS\Settings::i()->maxmind_gateways == '*' or in_array( $transaction->method->id, explode( ',', \IPS\Settings::i()->maxmind_gateways ) ) ) )
		{
			$maxMind = new \IPS\nexus\Fraud\MaxMind\Request( FALSE );
			$maxMind->setIpAddress( $transaction->ip );
			$maxMind->setTransaction( $transaction );
		}
		$fraudResult = $transaction->runFraudCheck( $maxMind );
		if ( $fraudResult === \IPS\nexus\Transaction::STATUS_REFUSED )
		{
			$transaction->executeFraudAction( $fraudResult, FALSE );
			$transaction->sendNotification();
		}
		
		/* Authorize */	
		else
		{	
			$transaction->auth = $transaction->method->auth( $transaction, array( $transaction->method->id . '_card' => $source['id'] ) );
			$transaction->status = \IPS\nexus\Transaction::STATUS_GATEWAY_PENDING;
			$transaction->save();
		}
		
		/* OK */
		\IPS\Output::i()->sendOutput( 'OK', 200, 'text/plain' );
	}
	
	/* charge.succeeded means we got the payment */
	elseif ( isset( $data['type'] ) and $data['type'] === 'charge.succeeded' )
	{
		/* Load the transaction */
		if ( isset( $data['data']['object']['metadata']['Transaction ID'] ) )
		{
			$transaction = \IPS\nexus\Transaction::load( $data['data']['object']['metadata']['Transaction ID'] );
		}
		elseif ( isset( $data['data']['object']['source']['redirect']['return_url'] ) )
		{
			$transaction = loadTransaction( $data['data']['object']['source']['redirect']['return_url'] );
		}
		else
		{
			try
			{
				$where = array( array( 't_gw_id=?', $data['data']['object']['id'] ) );
				if ( isset( $data['data']['object']['metadata']['Invoice ID'] ) )
				{
					$where[] = array( 't_invoice=?', $data['data']['object']['metadata']['Invoice ID'] );
				}
				
				$transaction = \IPS\nexus\Transaction::constructFromData( \IPS\Db::i()->select( '*', 'nexus_transactions', $where )->first() );
			}
			catch ( \UnderflowException $e )
			{
				\IPS\Output::i()->sendOutput( 'COULD_NOT_FIND_TRANSACTION', 403, 'text/plain' );
				exit;
			}
		}
		if ( $transaction->status !== \IPS\nexus\Transaction::STATUS_GATEWAY_PENDING )
		{
			if ( $transaction->status === \IPS\nexus\Transaction::STATUS_PAID )
			{
				\IPS\Output::i()->sendOutput( 'ALREADY_PROCESSED', 200, 'text/plain' );
			}
			else
			{
				\IPS\Output::i()->sendOutput( 'BAD_STATUS', 403, 'text/plain' );
			}
			exit;
		}
		
		/* Create a MaxMind request */
		$maxMind = NULL;
		if ( \IPS\Settings::i()->maxmind_key and ( !\IPS\Settings::i()->maxmind_gateways or \IPS\Settings::i()->maxmind_gateways == '*' or in_array( $transaction->method->id, explode( ',', \IPS\Settings::i()->maxmind_gateways ) ) ) )
		{
			$maxMind = new \IPS\nexus\Fraud\MaxMind\Request( FALSE );
			$maxMind->setIpAddress( $transaction->ip );
			$maxMind->setTransaction( $transaction );
		}
		
		/* Check fraud rules */
		$fraudResult = $transaction->runFraudCheck( $maxMind );
		if ( $fraudResult )
		{
			$transaction->executeFraudAction( $fraudResult, TRUE );
		}
		
		/* If we're not being fraud blocked, we can approve */
		if ( $fraudResult === \IPS\nexus\Transaction::STATUS_PAID )
		{
			$transaction->member->log( 'transaction', array(
				'type'			=> 'paid',
				'status'		=> \IPS\nexus\Transaction::STATUS_PAID,
				'id'			=> $transaction->id,
				'invoice_id'	=> $transaction->invoice->id,
				'invoice_title'	=> $transaction->invoice->title,
			) );
			$transaction->approve();
		}
		
		/* Either way, let the user know we got their payment */
		$transaction->sendNotification();
				
		/* OK */
		\IPS\Output::i()->sendOutput( 'OK', 200, 'text/plain' );
	}
	
	/* charge.failed means it failed */
	elseif ( isset( $data['type'] ) and $data['type'] === 'charge.failed' )
	{
		/* Load the transaction */
		if ( isset( $data['data']['object']['metadata']['Transaction ID'] ) )
		{
			$transaction = \IPS\nexus\Transaction::load( $data['data']['object']['metadata']['Transaction ID'] );
		}
		elseif ( isset( $data['data']['object']['source']['redirect']['return_url'] ) )
		{
			$transaction = loadTransaction( $data['data']['object']['source']['redirect']['return_url'] );
		}
		else
		{
			try
			{
				$where = array( array( 't_gw_id=?', $data['data']['object']['id'] ) );
				if ( isset( $data['data']['object']['metadata']['Invoice ID'] ) )
				{
					$where[] = array( 't_invoice=?', $data['data']['object']['metadata']['Invoice ID'] );
				}
				
				$transaction = \IPS\nexus\Transaction::constructFromData( \IPS\Db::i()->select( '*', 'nexus_transactions', $where )->first() );
			}
			catch ( \UnderflowException $e )
			{
				\IPS\Output::i()->sendOutput( 'COULD_NOT_FIND_TRANSACTION', 403, 'text/plain' );
				exit;
			}
		}
		if ( $transaction->status !== \IPS\nexus\Transaction::STATUS_GATEWAY_PENDING )
		{
			if ( $transaction->status === \IPS\nexus\Transaction::STATUS_REFUSED )
			{
				\IPS\Output::i()->sendOutput( 'ALREADY_PROCESSED', 200, 'text/plain' );
			}
			else
			{
				\IPS\Output::i()->sendOutput( 'BAD_STATUS', 403, 'text/plain' );
			}
			exit;
		}
		
		/* Mark it failed */
		$transaction->status = \IPS\nexus\Transaction::STATUS_REFUSED;
		$extra = $transaction->extra;
		$extra['history'][] = array( 's' => \IPS\nexus\Transaction::STATUS_REFUSED, 'noteRaw' => $data['data']['object']['failure_message'] );
		$transaction->extra = $extra;
		$transaction->save();
		$transaction->member->log( 'transaction', array(
			'type'			=> 'paid',
			'status'		=> \IPS\nexus\Transaction::STATUS_REFUSED,
			'id'			=> $transaction->id,
			'invoice_id'	=> $transaction->invoice->id,
			'invoice_title'	=> $transaction->title,
		), FALSE );
		
		/* Send notification */
		$transaction->sendNotification();
		
		/* Return */
		\IPS\Output::i()->sendOutput( 'OK', 200, 'text/plain' );
	}
	
	/* charge.dispute.created is a chargeback */
	elseif ( isset( $data['type'] ) and $data['type'] === 'charge.dispute.created' and isset( $data['data']['object']['charge'] ) )
	{
		$transaction = \IPS\nexus\Transaction::constructFromData( \IPS\Db::i()->select( '*', 'nexus_transactions', array( 't_gw_id=?', $data['data']['object']['charge'] ) )->first() );
		$settings = json_decode( $transaction->method->settings, TRUE );
		if ( !validate( $settings['webhook_secret'] ) )
		{
			throw new \Exception('INVALID_SIGNING_SECRET');
		}
		
		$transaction->status = $transaction::STATUS_DISPUTED;
		$extra = $transaction->extra;
		$extra['history'][] = array( 's' => $transaction::STATUS_DISPUTED, 'on' => $data['data']['object']['created'], 'ref' => $data['data']['object']['id'] );
		$transaction->extra = $extra;
		$transaction->save();
		
		if ( $transaction->member )
		{
			$transaction->member->log( 'transaction', array(
				'type'		=> 'status',
				'status'	=> $transaction::STATUS_DISPUTED,
				'id'		=> $transaction->id
			) );
		}
		
		$transaction->invoice->markUnpaid( \IPS\nexus\Invoice::STATUS_CANCELED );
	}
	
	/* charge.dispute.closed is a chargeback being resolved */
	elseif ( isset( $data['type'] ) and $data['type'] === 'charge.dispute.closed' and isset( $data['data']['object']['charge'] ) and isset( $data['data']['object']['status'] ) )
	{
		$transaction = \IPS\nexus\Transaction::constructFromData( \IPS\Db::i()->select( '*', 'nexus_transactions', array( 't_gw_id=?', $data['data']['object']['charge'] ) )->first() );
		$settings = json_decode( $transaction->method->settings, TRUE );
		if ( !validate( $settings['webhook_secret'] ) )
		{
			throw new \Exception('INVALID_SIGNING_SECRET');
		}
		
		if ( $data['data']['object']['status'] === 'won' )
		{
			$transaction->status = $transaction::STATUS_PAID;
			$transaction->save();
			if ( !$transaction->invoice->amountToPay()->amount->isGreaterThanZero() )
			{	
				$transaction->invoice->markPaid();
			}
		}
		else
		{
			$transaction->status = $transaction::STATUS_REFUNDED;
			$transaction->save();
		}
	}
}
catch ( \Exception $e )
{
	\IPS\Output::i()->sendOutput( $e->getMessage(), 500, 'text/plain' );
}
