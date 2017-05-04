<?php
/**
 * payfast_notify.php
 *
 * PayFast ITN handler
 *
 * Copyright (c) 2008 PayFast (Pty) Ltd
 * You (being anyone who is not PayFast (Pty) Ltd) may download and use this plugin / code in your own website in conjunction with a registered and active PayFast account. If your PayFast account is terminated for any reason, you may not use this plugin / code or part thereof.
 * Except as expressly indicated in this licence, you may not use, copy, modify or distribute this plugin / code or part thereof in any way.
 * 
 * @author     Jonathan Smit
 * @link       http://www.payfast.co.za/help/virtuemart
 */

// Load PayFast configuration file
define( '_PF_ITN', '1' );    // Define so that CFG file can be included
require_once( dirname( __FILE__ ) .'/classes/payment/ps_payfast.cfg.php' );

//// bof: Load Joomla configuration
    define('_VALID_MOS', '1');
    global $mosConfig_absolute_path, $mosConfig_live_site, $mosConfig_lang, $database,
        $mosConfig_mailfrom, $mosConfig_fromname, $vendor_name, $vendor_url;

    $my_path = dirname( __FILE__ );

    if( file_exists( $my_path ."/../../../configuration.php" ) )
        require_once( $my_path ."/../../../configuration.php" );
    elseif( file_exists( $my_path ."/../../configuration.php" ) )
        require_once( $my_path ."/../../configuration.php" );
    elseif( file_exists( $my_path ."/configuration.php" ) )
        require_once( $my_path ."/configuration.php" );
    else
        die( "Joomla Configuration File not found!" );

    include_once( $my_path .'/compat.joomla1.5.php' );

    if( class_exists( 'jconfig') )
    {
		define( '_JEXEC', 1 );
		define('JPATH_BASE', $mosConfig_absolute_path );

		require_once( JPATH_BASE .'/includes/defines.php' );
		require_once( JPATH_BASE .'/includes/application.php' );
		require_once( JPATH_BASE. '/includes/database.php');

        // Create the mainframe object
		$mainframe = new JSite();

		// Set the configuration
		$mainframe->setConfiguration( JPATH_CONFIGURATION . DS . 'configuration.php' );

		// Load system plugin group
		JPluginHelper::importPlugin( 'system' );

		// Trigger the onStart events
		$mainframe->triggerEvent( 'onBeforeStart' );

		// Create the session
		$mainframe->setSession( $mainframe->getCfg('live_site').$mainframe->getClientId() );
		$database =& JFactory::getDBO();
    }
    else
    {
    	require_once($mosConfig_absolute_path. '/includes/database.php');
    	$database = new database( $mosConfig_host, $mosConfig_user, $mosConfig_password, $mosConfig_db, $mosConfig_dbprefix );
    }

    // load Joomla Language File
    if( file_exists( $mosConfig_absolute_path. '/language/'.$mosConfig_lang.'.php' ) )
        require_once( $mosConfig_absolute_path. '/language/'.$mosConfig_lang.'.php' );
    elseif( file_exists( $mosConfig_absolute_path. '/language/english.php' ) )
        require_once( $mosConfig_absolute_path. '/language/english.php' );
//// eof: Load Joomla configuration

//// bof: Load VirtueMart configuration
    require_once( $mosConfig_absolute_path.'/administrator/components/com_virtuemart/virtuemart.cfg.php' );
    require_once( CLASSPATH. 'ps_main.php');

	require_once( CLASSPATH. "language.class.php" );
	require_once( CLASSPATH."Log/Log.php" );
	$vmLoggerConf = array(
		'buffering' => true
		);

	/**
	 * This Log Object will help us log messages and errors
	 * See http://pear.php.net/package/Log
	 * @global Log vmLogger
	 */
	$vmLogger = &vmLog::singleton( 'display', '', '', $vmLoggerConf, PEAR_LOG_TIP );
	$GLOBALS['vmLogger'] =& $vmLogger;

    require_once( CLASSPATH . 'phpmailer/class.phpmailer.php');
    $mail = new vmPHPMailer();
    $mail->PluginDir = CLASSPATH . 'phpmailer/';
    $mail->SetLanguage("en", CLASSPATH . 'phpmailer/language/');

    // Load the VirtueMart Language File
    if( file_exists( ADMINPATH. 'languages/'.$mosConfig_lang.'.php' ) )
      require_once( ADMINPATH. 'languages/'.$mosConfig_lang.'.php' );
    else
      require_once( ADMINPATH. 'languages/english.php' );

    // Load the VirtueMart database class
    require_once( CLASSPATH. 'ps_database.php' );

    // Restart session
    require_once( CLASSPATH .'ps_session.php' );

    // Constructor initializes the session!
    $sess = new ps_session();

    // Include globals; for this, $db is needed, as is htmlTools.class.php
    $db = new ps_DB;
    require_once( CLASSPATH .'htmlTools.class.php' );
    require_once( ADMINPATH .'global.php' );
//// eof: Load VirtueMart configuration

// Include the PayFast common file
define( 'PF_DEBUG', ( PAYFAST_DEBUG ? true : false ) );
require_once( CLASSPATH .'payfast_common.inc' );

// Variable Initialization
$pfError = false;
$pfErrMsg = '';
$pfData = array();
$pfHost = ( ( PAYFAST_SERVER != 'LIVE' ) ? 'sandbox' : 'www' ) .'.payfast.co.za';
$pfOrderId = '';
$pfParamString = '';
$pfDebugEmail = ( PAYFAST_DEBUG_EMAIL != '' ) ? PAYFAST_DEBUG_EMAIL : $mosConfig_mailfrom;

pflog( 'PayFast ITN call received' );

//// Notify PayFast that information has been received
if( !$pfError )
{
    header( 'HTTP/1.0 200 OK' );
    flush();
}

//// Get data sent by PayFast
if( !$pfError )
{
    pflog( 'Clean data' );

    // Posted variables from ITN
    $pfData = pfGetData();

    pflog( 'PayFast Data: '. print_r( $pfData, true ) );

    if( $pfData === false )
    {
        $pfError = true;
        $pfErrMsg = PF_ERR_BAD_ACCESS;
    }
}

//// Verify security signature
if( !$pfError )
{
    pflog( 'Verify security signature' );

    // If signature different, log for debugging
    if( !pfValidSignature( $pfData, $pfParamString ) )
    {
        $pfError = true;
        $pfErrMsg = PF_ERR_INVALID_SIGNATURE;
    }
}

//// Verify source IP (If not in debug mode)
if( !$pfError && !PF_DEBUG )
{
    pflog( 'Verify source IP' );

    if( !pfValidIP( $_SERVER['REMOTE_ADDR'] ) )
    {
        $pfError = true;
        $pfErrMsg = PF_ERR_BAD_SOURCE_IP;
    }
}

//// Verify data received
if( !$pfError )
{
    pflog( 'Verify data received' );

    $pfValid = pfValidData( $pfHost, $pfParamString );

    if( !$pfValid )
    {
        $pfError = true;
        $pfErrMsg = PF_ERR_BAD_ACCESS;
    }
}

//// Check data against VirtueMart order
if( !$pfError )
{
    pflog( 'Check data against VirtueMart order' );

   	// Get the Order Details from the database
    $sql =
        "SELECT `order_id`, `order_number`, `user_id`, `order_subtotal`,
            `order_total`, `order_currency`, `order_tax`,
            `order_shipping_tax`, `coupon_discount`, `order_discount`
        FROM `#__{vm}_orders`
        WHERE `order_id` = '". $pfData['m_payment_id'] ."'";

    $db = new ps_DB;
    $db->query( $sql );
    $db->next_record();

    // Check order amount
    if( !pfAmountsEqual( $pfData['amount_gross'], $db->f('order_total') ) )
    {
        $pfError = true;
        $pfErrMsg = PF_ERR_AMOUNT_MISMATCH;
    }
    // Check order number
    elseif( strcasecmp( $pfData['custom_str1'], $db->f( 'order_number' ) ) != 0 )
    {
        $pfError = true;
        $pfErrMsg = PF_ERR_ORDER_NUMBER_MISMATCH;
    }
}

//// Check status and update order
if( !$pfError )
{
    pflog( 'Check status and update order' );

	// Check the payment_status is Completed
	if( $pfData['payment_status'] == 'COMPLETE' ||
        $pfData['payment_status'] == 'PENDING' )
    {
        if( $pfData['payment_status'] == 'COMPLETE' )
            $d['order_status'] = PAYFAST_VERIFIED_STATUS;
        else
            $d['order_status'] = PAYFAST_PENDING_STATUS;

        $d['order_id'] = $db->f( 'order_id' );
        $d['order_comment'] = 'Payment confirmed (PayFast ID = '. $pfData['pf_payment_id'] .')';
        $d['notify_customer'] = 'Y';

        require_once ( CLASSPATH . 'ps_order.php' );
        $ps_order= new ps_order;
        $ps_order->order_status_update( $d );

        if( PF_DEBUG )
        {
            $mail->From = $mosConfig_mailfrom;
            $mail->FromName = $mosConfig_fromname;
            $mail->AddAddress( $pfDebugEmail );
            $mail->Subject = "PayFast ITN on your site";
            $mail->Body =
                "Hi,\n\n".
                "A PayFast transaction has been completed on your website\n".
                "------------------------------------------------------------\n".
                "Site: ". $vendor_name ." (". $vendor_url .")\n".
                "Order ID: ". $db->f( 'order_id' ) ."\n".
                "User ID: ". $db->f( 'user_id' ) ."\n".
                "PayFast Transaction ID: ". $pfData['pf_payment_id'] ."\n".
                "PayFast Payment Status: ". $pfData['payment_status'] ."\n".
                "Order Status Code: ". $d['order_status'];
            $mail->Send();
        }
	}
	elseif( $pfData['payment_status'] == 'FAILED' )
	{
        $d['order_status'] = PAYFAST_INVALID_STATUS;
        $d['order_id'] = $db->f( 'order_id' );
        $d['order_comment'] = 'Payment failed (PayFast ID = '. $pfData['pf_payment_id'] .')';
        $d['notify_customer'] = 'Y';

        require_once ( CLASSPATH . 'ps_order.php' );
        $ps_order= new ps_order;
        $ps_order->order_status_update( $d );

        $mail->From = $mosConfig_mailfrom;
        $mail->FromName = $mosConfig_fromname;
        $mail->AddAddress( $pfDebugEmail );
        $mail->Subject = "PayFast ITN Transaction on your site";
        $mail->Body =
            "Hi,\n\n".
            "A failed PayFast transaction on your website requires attention\n".
            "------------------------------------------------------------\n".
            "Site: ". $vendor_name ." (". $vendor_url .")\n".
            "Order ID: ". $db->f( 'order_id' ) ."\n".
            "User ID: ". $db->f( 'user_id' ) ."\n".
            "PayFast Transaction ID: ". $pfData['pf_payment_id'] ."\n".
            "PayFast Payment Status: ". $pfData['payment_status'] ."\n".
            "Order Status Code: ". $d['order_status'];
        $mail->Send();
    }
}

// If an error occurred
if( $pfError )
{
    pflog( 'Error occurred: '. $pfErrMsg );
    pflog( 'Sending email notification' );

     // Send an email
    $mail->From = $mosConfig_mailfrom;
    $mail->FromName = $mosConfig_fromname;
    $mail->AddAddress( $pfDebugEmail );
    $mail->Subject = "PayFast ITN error: ". $pfErrMsg;

    $body =
        "Hi,\n\n".
        "An invalid PayFast transaction on your website requires attention\n".
        "------------------------------------------------------------\n".
        "Site: ". $vendor_name ." (". $vendor_url .")\n".
        "Remote IP Address: ".$_SERVER['REMOTE_ADDR']."\n".
        "Remote host name: ". gethostbyaddr( $_SERVER['REMOTE_ADDR'] ) ."\n".
        "Order ID: ". $db->f( 'order_id' ) ."\n".
        "User ID: ". $db->f("user_id") ."\n";
    if( isset( $pfData['pf_payment_id'] ) )
        $body .= "PayFast Transaction ID: ". $pfData['pf_payment_id'] ."\n";
    if( isset( $pfData['payment_status'] ) )
        $body .= "PayFast Payment Status: ". $pfData['payment_status'] ."\n";
    $body .=
        "\nError: ". $pfErrMsg ."\n";

    switch( $pfErrMsg )
    {
        case PF_ERR_AMOUNT_MISMATCH:
            $body .=
                "Value received : ". $pfData['amount_gross'] ."\n".
                "Value should be: ". $db->f('order_total');
            break;

        case PF_ERR_ORDER_ID_MISMATCH:
            $body .=
                "Value received : ". $pfData['m_payment_id'] ."\n".
                "Value should be: ". $db->f('order_id');
            break;

        case PF_ERR_ORDER_NUMBER_MISMATCH:
            $body .=
                "Value received : ". $pfData['custom_str1'] ."\n".
                "Value should be: ". $db->f('order_number');
            break;

        // For all other errors there is no need to add additional information
        default:
            break;
    }

    $mail->Body = $body;
    $mail->Send();
}

// Close log
pflog( '', true );
?>
