<?php
/**
 * @brief		Stripe Handler
 * @author		<a href='https://www.invisioncommunity.com'>Invision Power Services, Inc.</a>
 * @copyright	(c) Invision Power Services, Inc.
 * @license		https://www.invisioncommunity.com/legal/standards/
 * @package		Invision Community
 * @subpackage	Nexus
 * @since		20 Jul 2017
 */

define('REPORT_EXCEPTIONS', TRUE);
require_once '../../../../init.php';
\IPS\Session\Front::i();

/* Load Source */
try
{
	$transaction = \IPS\nexus\Transaction::load( \IPS\Request::i()->nexusTransactionId );
	$source = $transaction->method->api( 'sources/' . preg_replace( '/[^A-Z0-9_]/i', '', \IPS\Request::i()->source ), NULL, 'get' );
	if ( $source['client_secret'] != \IPS\Request::i()->client_secret )
	{
		throw new \Exception;
	}
}
catch ( \Exception $e )
{
	\IPS\Output::i()->redirect( \IPS\Http\Url::internal( "app=nexus&module=checkout&controller=checkout&do=transaction&id=&t=" . \IPS\Request::i()->nexusTransactionId, 'front', 'nexus_checkout', \IPS\Settings::i()->nexus_https ) );
}

/* If we haven't had the webhook yet, wait a few seconds */
if ( !in_array( $source['status'], array( 'failed', 'consumed' ) ) )
{
	sleep( 5 );
}

/* And then send them on */
if ( $source['status'] === 'failed' )
{
	\IPS\Output::i()->redirect( $transaction->invoice->checkoutUrl() );
}
else
{
	\IPS\Output::i()->redirect( $transaction->url()->setQueryString( 'pending', 1 ) );
}