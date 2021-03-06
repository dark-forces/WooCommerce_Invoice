<?php
/**
 * @copyright Copyright (c) 2016 Green World FinTech Service Co., Ltd. (https://www.ecpay.com.tw)
 * @version 1.1.0911
 * 
 * Plugin Name: WooCommerce ECPay_Invoice
 * Plugin URI: https://www.ecpay.com.tw
 * Description: ECPay Invoice For WooCommerce
 * Author: ECPay Green World FinTech Service Co., Ltd. 
 * Author URI: https://www.ecpay.com.tw
 * Version: V1.1.0911
 * Text Domain: woocommerce-ecpayinvoice
 * Domain Path: /i18n/languages/
 */

defined( 'ABSPATH' ) or exit;


// include Invoice SDK
require_once( 'includes/Ecpay_Invoice.php' );

// Check if WooCommerce is active
if ( ! WC_ECPayinvoice::is_woocommerce_active() ) {

	add_action( 'admin_notices', 'wc_ecpayinvoice_render_wc_inactive_notice' );
	return;
}

// WC version check
if ( version_compare( get_option( 'woocommerce_db_version' ), '2.4.13', '<' ) ) {

	add_action( 'admin_notices', 'wc_ecpayinvoice_render_outdated_wc_version_notice' );
	return;
}

/**
 * Renders a notice when WooCommerce version is outdated
 *
 * @since 2.3.1
 */
function wc_ecpayinvoice_render_outdated_wc_version_notice() {

	$message = sprintf(
		/* translators: %1$s and %2$s are <strong> tags. %3$s and %4$s are <a> tags */
		__( '%1$sWooCommerce ECPay Invoice is inactive.%2$s This version requires WooCommerce 2.5.5 or newer. Please %3$supdate WooCommerce to version 2.4.13 or newer%4$s', 'woocommerce-ecpayinvoice' ),
		'<strong>',
		'</strong>',
		'<a href="' . admin_url( 'plugins.php' ) . '">',
		'&nbsp;&raquo;</a>'
	);

	printf( '<div class="error"><p>%s</p></div>', $message );
}


/**
 * Renders a notice when WooCommerce version is outdated
 *
 * @since 2.3.1
 */
function wc_ecpayinvoice_render_wc_inactive_notice() {

	$message = sprintf(
		/* translators: %1$s and %2$s are <strong> tags. %3$s and %4$s are <a> tags */
		__( '%1$sWooCommerce ECPay Invoice is inactive%2$s as it requires WooCommerce. Please %3$sactivate WooCommerce version 2.5.5 or newer%4$s', 'woocommerce-ecpayinvoice' ),
		'<strong>',
		'</strong>',
		'<a href="' . admin_url( 'plugins.php' ) . '">',
		'&nbsp;&raquo;</a>'
	);

	printf( '<div class="error"><p>%s</p></div>', $message );
}


/**
 * # WooCommerce ECPayinvoice Main Plugin Class
 *
 * ## Plugin Overview
 *
 * Adds a few settings pages which make uses of some of the simpler filters inside WooCommerce, so if you want to quickly
 * change button text or the number of products per page, you can use this instead of having to write code for the filter.
 * Note this isn't designed as a rapid development/prototyping tool -- for a production site you should use the actual filter
 * instead of relying on this plugin.
 *
 * ## Admin Considerations
 *
 * A 'ECPayinvoice' sub-menu page is added to the top-level WooCommerce page, which contains 4 tabs with the settings
 * for each section - Shop Loop, Product Page, Checkout, Misc
 *
 * ## Frontend Considerations
 *
 * The filters that the plugin exposes as settings as used exclusively on the frontend.
 *
 * ## Database
 *
 * ### Global Settings
 *
 * + `wc_ecpayinvoice_active_model` - a serialized array of active model in the format
 * filter name => filter value
 *
 * ### Options table
 *
 * + `wc_ecpayinvoice_version` - the current plugin version, set on install/upgrade
 *
 */
class WC_ECPayinvoice {


	/** plugin version number */
	const VERSION = 'v.1.1.0222';

	/** @var \WC_ECPayinvoice single instance of this plugin */
	protected static $instance;

	/** @var \WC_ECPayinvoice_Settings instance */
	public $settings;

	/** var array the active filters */
	public $filters;


	/**
	 * Initializes the plugin
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		// load translation
		//add_action( 'init', array( $this, 'load_translation' ) );


		// admin
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {

			// load settings page
			add_filter( 'woocommerce_get_settings_pages', array( $this, 'add_settings_page' ) );

			// add a 'Configure' link to the plugin action links
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_plugin_action_links' ) );

			// run every time
			$this->install();
		}

		//add_action( 'woocommerce_init', array( $this, 'load_model' ) );

		// 後臺手動開立按鈕
                add_action( 'woocommerce_admin_order_data_after_order_details', array(&$this,'action_woocommerce_admin_generate_invoice_manual' )); 

                // 前台統一編號 載具資訊填寫
              	add_filter( 'woocommerce_checkout_fields', array(&$this, 'ecpay_invoice_info_fields' )); 

              	// 發票自動開立程序(需綁ECPAY金流)
              	add_action('ecpay_auto_invoice', array(&$this, 'ecpay_auto_invoice' ),10 ,3); 

		add_action('woocommerce_checkout_process', array(&$this,'my_custom_checkout_field_process' )); 

	}


	/**
	 * Cloning instances is forbidden due to singleton pattern.
	 *
	 * @since 2.3.0
	 */
	public function __clone() {

		/* translators: Placeholders: %s - plugin name */
		_doing_it_wrong( __FUNCTION__, sprintf( esc_html__( 'You cannot clone instances of %s.', 'woocommerce-ecpayinvoice' ), 'WooCommerce ECPayinvoice' ), 'v.1.1.0801' );
	}


	/**
	 * Unserializing instances is forbidden due to singleton pattern.
	 *
	 * @since 2.3.0
	 */
	public function __wakeup() {

		/* translators: Placeholders: %s - plugin name */
		_doing_it_wrong( __FUNCTION__, sprintf( esc_html__( 'You cannot unserialize instances of %s.', 'woocommerce-ecpayinvoice' ), 'WooCommerce ECPayinvoice' ), 'v.1.1.0801' );
	}


	/**
	 * Add settings page
	 *
	 * @since 2.0.0
	 * @param array $settings
	 * @return array
	 */
	public function add_settings_page( $settings ) {

		$settings[] = require_once( 'includes/class-wc-ecpayinvoice-settings.php' );
		return $settings;
	}


	/**
	 * Handle localization, WPML compatible
	 *
	 * @since 1.1.0
	 */
	public function load_translation() {

		// localization in the init action for WPML support
		//load_plugin_textdomain( 'woocommerce-ecpayinvoice', false, dirname( plugin_basename( __FILE__ ) ) . '/i18n/languages' );
	}


	/**
	 * Checks if WooCommerce is active
	 *
	 * @since 2.3.0
	 * @return bool true if WooCommerce is active, false otherwise
	 */
	public static function is_woocommerce_active() {

		$woo_active = false ;
		$active_plugins = (array) get_option( 'active_plugins', array() );

		$active_plugins = array_merge( $active_plugins, get_site_option( 'active_sitewide_plugins', array() ) );

		foreach($active_plugins as $key => $value)
		{
			if ( (strpos($value,'/woocommerce.php') !== false))
                        {
                                $woo_active = true;
                        }			
		}

		return in_array( 'woocommerce/woocommerce.php', $active_plugins ) || array_key_exists( 'woocommerce/woocommerce.php', $active_plugins ) || $woo_active;
	}


	/** Frontend methods ******************************************************/


	// 統一編號 捐贈愛心碼 填寫
	function ecpay_invoice_info_fields($fields) { 

		?>

		<script type="text/javascript">
			var $ = jQuery.noConflict();

			$( document ).ready(function() {
				$("#billing_love_code").val("");
				$("#billing_customer_identifier").val("");
				$("#billing_carruer_num").val("");
				$("#billing_carruer_type").val("0");
				$("#billing_invoice_type").val("p");
				$("#billing_customer_identifier_field").slideUp();
				$("#billing_love_code_field").slideUp();
				$("#billing_carruer_num_field").slideUp();

				$("#billing_invoice_type").change(function() {
					invoice_type = $("#billing_invoice_type").val();
					carruer_type = $("#billing_carruer_type").val(); // 載具類型

					if (invoice_type == 'p') {
						$("#billing_customer_identifier_field").slideUp();
						$("#billing_love_code_field").slideUp();

						$("#billing_customer_identifier").val("");
						$("#billing_love_code").val("");
					} else if (invoice_type == 'c') {
						$("#billing_customer_identifier_field").slideDown();
						$("#billing_love_code_field").slideUp();
						$("#billing_love_code").val("");
						if (carruer_type == '2') {
							$("#billing_carruer_type").val("0");
							$("#billing_carruer_num").val("");
							$("#billing_carruer_num_field").slideUp();
						}
					} else if (invoice_type == 'd') {
						$("#billing_customer_identifier_field").slideUp();
						$("#billing_love_code_field").slideDown();
						$("#billing_customer_identifier").val("");
					}
				});

				// 載具判斷
				$("#billing_carruer_type").change(function() {
					carruer_type = $("#billing_carruer_type").val();
					invoice_type = $("#billing_invoice_type").val();
					identifier = $("#billing_customer_identifier").val();

					// 無載具
					if (carruer_type == '0') {
						$("#billing_carruer_num_field").slideUp();
						$("#billing_carruer_num").val("");
					} else if (carruer_type == '2') {
						// 自然人憑證
						if (identifier != '' || invoice_type == 'c') {
							alert('公司發票，不能使用自然人憑證做為載具');
							$("#billing_carruer_type").val("0");
							$("#billing_carruer_num").val("");
							$("#billing_carruer_num_field").slideUp();
						} else {
							$("#billing_carruer_num_field").slideDown();
						}
					} else if (carruer_type == '3') {
						$("#billing_carruer_num_field").slideDown();
					}
				});
			});
        </script>
        
		<?php

		// 
		$fields['billing']['billing_invoice_type'] = array(
			'type' 		=> 'select',
			'label'         => '發票開立',
			'required'      => false,
			'options' 	=> array(
					'p' => '個人',
					'c' => '公司',
					'd' => '捐贈'
				)
		);

		$fields['billing']['billing_customer_identifier'] = array(
			'type' 		=> 'text',
			'label'         => '統一編號',
			'required'      => false
		);

		$fields['billing']['billing_love_code'] = array(
			'type' 		=> 'text',
			'label'         => '愛心碼',
			'required'      => false
		);

		// 載具資訊
		$fields['billing']['billing_carruer_type'] = array(
			'type' 		=> 'select',
			'label'         => '載具類別',
			'required'      => false,
			'options' 	=> array(
				'0' => '無載具',
				'2' => '自然人憑證',
				'3' => '手機條碼'
			)
		);


		$fields['billing']['billing_carruer_num'] = array(
			'type' 		=> 'text',
			'label'         => '載具編號',
			'required'      => false
		);

		return $fields;
	}

	function my_custom_checkout_field_process()
	{
	    // Check if set, if its not set add an error.
		
	    	if ( isset($_POST['billing_invoice_type']) && $_POST['billing_invoice_type'] == 'c' && $_POST['billing_customer_identifier'] == '' )
	    	{
	        	wc_add_notice( __( '請輸入統一編號' ), 'error' );
	    	}

		if ( isset($_POST['billing_invoice_type']) && $_POST['billing_invoice_type'] == 'd' && $_POST['billing_love_code'] == '' )
	        {
	        	wc_add_notice( __( '請輸入愛心碼' ), 'error' );
	        }

	        if ( isset($_POST['billing_carruer_type']) && $_POST['billing_carruer_type'] == '2' && $_POST['billing_carruer_num'] == '' )
	        {
	        	wc_add_notice( __( '請輸入自然人憑證載具編號' ), 'error' );
	        }

	        if ( isset($_POST['billing_carruer_type']) && $_POST['billing_carruer_type'] == '3' && $_POST['billing_carruer_num'] == '' )
	        {
	        	wc_add_notice( __( '請輸入手機條碼載具編號' ), 'error' );
	        }

	    	// 統一編號格式判斷
	        if ( isset($_POST['billing_invoice_type']) && $_POST['billing_invoice_type'] == 'c' && $_POST['billing_customer_identifier'] != '' )
	        {
	        	if ( !preg_match('/^[0-9]{8}$/', $_POST['billing_customer_identifier']) )
			{
              			wc_add_notice( __( '統一編號格式錯誤' ), 'error' );
			}
	        }

	        // 愛心碼格式判斷
	        if ( isset($_POST['billing_invoice_type']) && $_POST['billing_invoice_type'] == 'd' && $_POST['billing_love_code'] != '' )
	        {
	        	if ( !preg_match('/^([xX]{1}[0-9]{2,6}|[0-9]{3,7})$/', $_POST['billing_love_code']) )
			{
              			wc_add_notice( __( '愛心碼格式錯誤' ), 'error' );
			}
	        }

	        // 自然人憑證格式判斷
	        if ( isset($_POST['billing_carruer_type']) && $_POST['billing_carruer_type'] == '2' && $_POST['billing_carruer_num'] != '' )
	        {
	        	if ( !preg_match('/^[a-zA-Z]{2}\d{14}$/', $_POST['billing_carruer_num']) )
			{
              			wc_add_notice( __( '自然人憑證格式錯誤' ), 'error' );
			}
	        }

	        // 手機載具格式判斷
	        if ( isset($_POST['billing_carruer_type']) && $_POST['billing_carruer_type'] == '3' && $_POST['billing_carruer_num'] != '' )
	        {
	        	if ( !preg_match('/^\/{1}[0-9a-zA-Z+-.]{7}$/', $_POST['billing_carruer_num']) )
			{
              			wc_add_notice( __( '手機條碼載具格式錯誤' ), 'error' );
			}
	        }

	}


	/** Admin methods ******************************************************/

	// 後臺手動開立發票按鈕
	function action_woocommerce_admin_generate_invoice_manual() { 
	 	global $woocommerce, $post;

        	// 判斷是否已經開過發票

	 	$oOrder_Obj 	= new WC_Order($post->ID);
                $nOrder_Status 	= $oOrder_Obj->get_status($post->ID);
                $aOrder_Info 	= get_post_meta($post->ID);

                // 付款成功次數 第一次付款或沒有此欄位則設定為空值
	       	$nTotalSuccessTimes = ( isset($aOrder_Info['_total_success_times'][0]) && $aOrder_Info['_total_success_times'][0] == '' ) ? '' :  $aOrder_Info['_total_success_times'][0] ;

                $aConfig_Invoice = get_option('wc_ecpayinvoice_active_model') ;

                if($aConfig_Invoice['wc_ecpay_invoice_enabled'] == 'enable')
                {	
	               
                	$_ecpay_invoice_status = '_ecpay_invoice_status'.$nTotalSuccessTimes ;



	                if($aConfig_Invoice['wc_ecpay_invoice_auto'] == 'manual')
	                {
		                // 尚未開立發票且訂單狀態為處理中
		                if( ( !isset($aOrder_Info[$_ecpay_invoice_status][0]) || $aOrder_Info[$_ecpay_invoice_status][0] == 0 ) && $nOrder_Status == 'processing' )
		                {
		                	// 產生按鈕
		                	echo "<p ><input class='button' type='button' id='invoice_button' onclick='send_orderid_to_gen_invoice(".$post->ID.");' value='開立發票' /></p>";
		                }
	                }

	                if( isset($aOrder_Info[$_ecpay_invoice_status][0]) && $aOrder_Info[$_ecpay_invoice_status][0] == 1 )
	                {
	                	// 產生按鈕
	                	echo "<p ><input class='button' type='button' id='invoice_button_issue_invalid' onclick='send_orderid_to_issue_invalid(".$post->ID.");' value='作廢發票' /></p>";
	                }
                }

	}


	// 開立發票
	function gen_invoice($nOrder_Id, $sMode = 'manual') {
		
		global $woocommerce, $post;

		$oOrder_Obj 	= new WC_Order($nOrder_Id);
	        $nOrder_Status 	= $oOrder_Obj->get_status($nOrder_Id);

	       	$aOrder_Info 	= get_post_meta($nOrder_Id);

	       	// 付款成功次數 第一次付款或沒有此欄位則設定為空值
	       	$nTotalSuccessTimes = ( isset($aOrder_Info['_total_success_times'][0]) && $aOrder_Info['_total_success_times'][0] == '' ) ? '' :  $aOrder_Info['_total_success_times'][0] ;

	       	$bInvoice_Enable = false ;
	       	$sInvoiceRemark = '' ; 

	       	if($sMode == 'manual')
	       	{
	       		
			if( $aOrder_Info['_payment_method'][0] == 'allpay_dca' || $aOrder_Info['_payment_method'][0] == 'ecpay_dca' ) // 定期定額 20170922 wesley
			{
				$_ecpay_invoice_status = '_ecpay_invoice_status'.$nTotalSuccessTimes ;

				if( ( !isset($aOrder_Info[$_ecpay_invoice_status][0]) || $aOrder_Info[$_ecpay_invoice_status][0] == 0 ) && $nOrder_Status == 'processing' )
		        	{
		        		$bInvoice_Enable = true ;
		        	}
			}
			else
			{
				if( ( !isset($aOrder_Info['_ecpay_invoice_status'][0]) || $aOrder_Info['_ecpay_invoice_status'][0] == 0 ) && $nOrder_Status == 'processing' )
		        	{
		        		$bInvoice_Enable = true ;
		        	}
			}
	       	}
	       	elseif($sMode == 'auto')
	       	{
	       		if( $aOrder_Info['_payment_method'][0] == 'allpay_dca' || $aOrder_Info['_payment_method'][0] == 'ecpay_dca' ) // 定期定額 20170922 wesley
			{
				$_ecpay_invoice_status = '_ecpay_invoice_status'.$nTotalSuccessTimes ;

				if( ( !isset($aOrder_Info[$_ecpay_invoice_status][0]) || $aOrder_Info[$_ecpay_invoice_status][0] == 0 ))
		        	{
		        		$bInvoice_Enable = true ;
		        	}
			}
			else
			{
				if( ( !isset($aOrder_Info['_ecpay_invoice_status'][0]) || $aOrder_Info['_ecpay_invoice_status'][0] == 0 ))
		        	{
		        		$bInvoice_Enable = true ;
		        	}
			}
	       	}

	        // 尚未開立發票且訂單狀態為處理中
	        if($bInvoice_Enable)
	        {
	        	
	 		//var_dump($aOrder_Info);

	 		// 取得發票介接參數設定
	 		$aConfig_Invoice 	= get_option('wc_ecpayinvoice_active_model') ;
	 		$MerchantID 		= $aConfig_Invoice['wc_ecpay_invoice_merchantid'] ;
	 		$HashKey 		= $aConfig_Invoice['wc_ecpay_invoice_hashkey'] ;
	 		$HashIV 		= $aConfig_Invoice['wc_ecpay_invoice_hashiv'] ;

	 		
	 		$Invoice_Url 		= '' ;

	 		$nOrder_Amount_Total 	= $oOrder_Obj->get_total(); 					// 訂單總金額
	 		$aOrder_Info 		= $oOrder_Obj->get_address(); 					// 訂單地址與電話

	 		$sOrder_Address		= $aOrder_Info['country'] . $aOrder_Info['city'] . $aOrder_Info['state'] . $aOrder_Info['address_1']. $aOrder_Info['address_2'] ; //  地址
	 		$sOrder_User_Name	= $aOrder_Info['first_name'] . $aOrder_Info['last_name'] ; 	// 購買人
	 		$sOrder_Email		= $aOrder_Info['email'] ; 					// EMAIL
	 		$sOrder_Phone		= $aOrder_Info['phone'] ; 					// Phone

	 		$sCustomerIdentifier 	= get_post_meta($nOrder_Id, '_billing_customer_identifier', true) ; // 統一編號	

	 		$sInvoice_Type		= get_post_meta($nOrder_Id, '_billing_invoice_type', true) ; 

	 		// 捐贈
	 		$nDonation 		= ( $sInvoice_Type == 'd' ) ? 1 : 2 ; 

	 		$nDonation 		= (empty($sCustomerIdentifier)) ? $nDonation : 2 ; // 如果有寫統一發票號碼則無法捐贈

	 		$nPrint 		= 0 ;

	 		// 有打統一編號 強制列印
	 		if( !empty($sCustomerIdentifier) )
	 		{
				$nPrint = 1 ;
	 		}

	 		$LoveCode 		= get_post_meta($nOrder_Id, '_billing_love_code', true); 		// 愛心碼
	 		$nCarruerType 		= get_post_meta($nOrder_Id, '_billing_carruer_type', true); 		// 載具
	 		$nCarruerType 		= ($nCarruerType == 0) ? '' : $nCarruerType ;

	 		$nCarruerNum		= get_post_meta($nOrder_Id, '_billing_carruer_num', true) ; 		// 載具編號

	 		$Invoice_Url = ($aConfig_Invoice['wc_ecpay_invoice_testmode'] == 'enable_testmode') ? 'https://einvoice-stage.ecpay.com.tw/Invoice/Issue'  : 'https://einvoice.ecpay.com.tw/Invoice/Issue' ;
	 		

	 		// 寫入發票資訊到備註中
	 		$sInvoice_Info = '' ;
	 		$sInvoice_Type_Tmp = ($sInvoice_Type == 'p') ? '個人' : ( ( $sInvoice_Type == 'd' ) ? '捐贈' : '公司') ;

	 		$sInvoice_Info .= ' 發票開立 : ' . $sInvoice_Type_Tmp . '<br />';
	 		

	 		if($sInvoice_Type == 'c')
	 		{
	 			$sInvoice_Info .= ' 統一編號 : ' . $sCustomerIdentifier . '<br />';
	 		}

	 		if($sInvoice_Type == 'd')
	 		{
	 			$sInvoice_Info .= ' 愛心碼 : ' . $LoveCode . '<br />';
	 		}


	 		if($nCarruerType != '')
	 		{
	 			$nCarruerType_Tmp = ($nCarruerType == 1 ) ? '合作店家' : (($nCarruerType == 2 ) ? '自然人憑證號碼' : '手機條碼' )  ;
	 			$sInvoice_Info .= ' 發票載具 : ' . $nCarruerType_Tmp . '<br />';
	 			$sInvoice_Info .= ' 載具編號 : ' . $nCarruerNum . '<br />';
	 		}

	 		$sInvoice_Info .= '開立金額：' . $nOrder_Amount_Total . ' 元' ;


	 		// 寫入開立資訊
	 		if(!empty($sInvoice_Info))
	 		{
	 			$oOrder_Obj->add_order_note($sInvoice_Info);
	 		}


	 		// 呼叫SDK 開立發票
			try
			{
				$sMsg = '' ;

				$ecpay_invoice = new EcpayInvoice ;
				
				// 2.寫入基本介接參數
				$ecpay_invoice->Invoice_Method 	= 'INVOICE' ;
				$ecpay_invoice->Invoice_Url 	= $Invoice_Url ;
				$ecpay_invoice->MerchantID 	= $MerchantID ;
				$ecpay_invoice->HashKey 	= $HashKey ;
				$ecpay_invoice->HashIV 		= $HashIV ;
				
				// 3.寫入發票相關資訊
				
				// 取得商品資訊
				$aItems_Tmp	= array();
				$aItems		= array();
		                $aItems_Tmp = $oOrder_Obj->get_items();

		                global $woocommerce;
		                if ( version_compare( $woocommerce->version, '3.0', ">=" ) ) {
		                    foreach($aItems_Tmp as $key1 => $value1)
		                    {
		                        $aItems[$key1]['ItemName'] = $value1['name']; // 商品名稱 ItemName
		                        $aItems[$key1]['ItemCount'] = $value1['quantity']; // 數量 ItemCount
		                        $aItems[$key1]['ItemAmount'] = round($value1['total'] + $value1['total_tax']); // 小計 ItemAmount
		                        $aItems[$key1]['ItemPrice'] = $aItems[$key1]['ItemAmount'] / $aItems[$key1]['ItemCount'] ; // 單價 ItemPrice
		                    }


		                } else {
		                    foreach($aItems_Tmp as $key1 => $value1)
		                    {
		                        $aItems[$key1]['ItemName'] = $value1['name']; // 商品名稱 ItemName
		                        $aItems[$key1]['ItemCount'] = isset($value1['item_meta']['_quantity'][0]) ? $value1['item_meta']['_quantity'][0] : '' ; // 數量 ItemCount

		                        if(empty($aItems[$key1]['ItemCount']))
		                        {
		                        	$aItems[$key1]['ItemCount'] = isset($value1['item_meta']['_qty'][0]) ? $value1['item_meta']['_qty'][0] : '' ; // 數量 ItemCount
		                        }

		                        $aItems[$key1]['ItemAmount'] = round($value1['item_meta']['_line_total'][0] + $value1['item_meta']['_line_tax'][0]); // 小計 ItemAmount
		                        $aItems[$key1]['ItemPrice'] = $aItems[$key1]['ItemAmount'] / $aItems[$key1]['ItemCount'] ; // 單價 ItemPrice
		                    }
		                }

				foreach($aItems as $key2 => $value2)
		                {
					// 商品資訊
					array_push($ecpay_invoice->Send['Items'], array('ItemName' => $value2['ItemName'], 'ItemCount' => $value2['ItemCount'], 'ItemWord' => '批', 'ItemPrice' => $value2['ItemPrice'], 'ItemTaxType' => 1, 'ItemAmount' => $value2['ItemAmount']  )) ;
				}

				// 運費
				$nShipping_Total = $oOrder_Obj->get_total_shipping();

				if($nShipping_Total != 0)
				{
					array_push($ecpay_invoice->Send['Items'], array('ItemName' => '運費', 'ItemCount' => 1, 'ItemWord' => '式', 'ItemPrice' => $nShipping_Total, 'ItemTaxType' => 1, 'ItemAmount' => $nShipping_Total )) ;
				}

				
				// 判斷測試模式
				if($aConfig_Invoice['wc_ecpay_invoice_testmode'] == 'enable_testmode')
				{
					$RelateNumber = date('YmdHis') . $nOrder_Id . $nTotalSuccessTimes; 
					//$RelateNumber = 'ECPAY'. date('YmdHis') . rand(1000000000,2147483647) ; // 產生測試用自訂訂單編號 // debug mode

				}
				else
				{
					$RelateNumber = $nOrder_Id . $nTotalSuccessTimes ;
				}

				// 判斷是否信用卡後四碼欄位有值，如果有值則寫入備註中
				$nCard4no = get_post_meta($nOrder_Id, 'card4no', true); 	// 信用卡後四碼
				if(!empty($nCard4no))
				{
					$sInvoiceRemark .= $nCard4no ;
				}


				$ecpay_invoice->Send['RelateNumber'] 			= $RelateNumber ;
				$ecpay_invoice->Send['CustomerID'] 			= '' ;
				$ecpay_invoice->Send['CustomerIdentifier'] 		= $sCustomerIdentifier ;
				$ecpay_invoice->Send['CustomerName'] 			= $sOrder_User_Name ;
				$ecpay_invoice->Send['CustomerAddr'] 			= $sOrder_Address ;
				$ecpay_invoice->Send['CustomerPhone'] 			= $sOrder_Phone ;
				$ecpay_invoice->Send['CustomerEmail'] 			= $sOrder_Email ;
				$ecpay_invoice->Send['ClearanceMark'] 			= '' ;
				$ecpay_invoice->Send['Print'] 				= $nPrint ;
				$ecpay_invoice->Send['Donation'] 			= $nDonation ;
				$ecpay_invoice->Send['LoveCode'] 			= $LoveCode ;
				$ecpay_invoice->Send['CarruerType'] 			= $nCarruerType ;
				$ecpay_invoice->Send['CarruerNum'] 			= $nCarruerNum ;
				$ecpay_invoice->Send['TaxType'] 			= 1 ;
				$ecpay_invoice->Send['SalesAmount'] 			= $nOrder_Amount_Total ;
				$ecpay_invoice->Send['InvoiceRemark'] 			= $sInvoiceRemark ;	
				$ecpay_invoice->Send['InvType'] 			= '07';
				$ecpay_invoice->Send['vat'] 				= '' ;
				
				//var_dump($ecpay_invoice->Send);
				//exit;
				// 4.送出
				$aReturn_Info = $ecpay_invoice->Check_Out();
			}
			catch (Exception $e)
			{
				// 例外錯誤處理。
				$sMsg = $e->getMessage();
			}

			// 寫入發票回傳資訊
			$oOrder_Obj->add_order_note(print_r($aReturn_Info, true));

			if(!empty($sMsg))
			{
				$oOrder_Obj->add_order_note($sMsg);
			}

			if(isset($aReturn_Info['RtnCode']) && $aReturn_Info['RtnCode'] == 1)
			{
				$nOrder_Invoice_Status = 1 ;  // 發票已經開立
				
				if(empty($nTotalSuccessTimes))
				{
					$sOrder_Invoice_Field_Name 	= '_ecpay_invoice_status' ; 	// 欄位名稱 記錄狀態
					$sOrder_Invoice_Num_Field_Name 	= '_ecpay_invoice_number' ; 	// 欄位名稱 記錄發票號碼
				}
				else
				{
					$sOrder_Invoice_Field_Name 	= '_ecpay_invoice_status'.$nTotalSuccessTimes ; 	// 欄位 記錄狀態
					$sOrder_Invoice_Num_Field_Name 	= '_ecpay_invoice_number'.$nTotalSuccessTimes ; 	// 欄位名稱 記錄發票號碼
				}


				// 異動已經開立發票的狀態 1.已經開立 0.尚未開立
				update_post_meta($nOrder_Id, $sOrder_Invoice_Field_Name, $nOrder_Invoice_Status );

				// 寫入發票號碼
				update_post_meta($nOrder_Id, $sOrder_Invoice_Num_Field_Name, $aReturn_Info['InvoiceNumber'] );
			}
			
			if($sMode == 'manual')
			{
				return 'RelateNumber=>' . $RelateNumber . print_r($aReturn_Info, true) ;
			}
	        }
	        else
	        {
	     
	   //      	if($aOrder_Info['_ecpay_invoice_status'][0] == 1)
	   //      	{
	   //      		if($sMode == 'manual')
				// {
				// 	return '發票已經完成開立，請重新整理畫面' ;
				// }
				// else
				// {
				// 	$oOrder_Obj->add_order_note('發票已經完成開立，請重新整理畫面');
				// }
	   //      	}

	        	if($nOrder_Status != 'processing' )
	        	{
	        		if($sMode == 'manual')
				{
					return '僅允許狀態為處理中的訂單開立發票' ;
				}
				else
				{
					$oOrder_Obj->add_order_note('僅允許狀態為處理中的訂單開立發票');
				}
	        	}
	        }
	}

	// 自動開立 
	function ecpay_auto_invoice($nOrder_Id, $SimulatePaid = 0)
	{
		global $woocommerce, $post;

		// 判斷是否啟動自動開立
		$aConfig_Invoice = get_option('wc_ecpayinvoice_active_model') ;

		// 啟動則自動開立
		if($aConfig_Invoice['wc_ecpay_invoice_auto'] == 'auto')
		{
			// 判斷是否為模擬觸發
			if($SimulatePaid == 0)
			{
				// 非模擬觸發
				$this->gen_invoice($nOrder_Id, 'auto');
			}
			else
			{
				// 模擬觸發
				// 判斷是否在發票測試環境
				if($aConfig_Invoice['wc_ecpay_invoice_testmode'] == 'enable_testmode')
				{
					$this->gen_invoice($nOrder_Id, 'auto');
				}
			}
		}
	}


	// 作廢發票
	function issue_invalid_invoice($nOrder_Id) {
		
		global $woocommerce, $post;

		$oOrder_Obj 	= new WC_Order($nOrder_Id);
	        $nOrder_Status 	= $oOrder_Obj->get_status($nOrder_Id);

	       	$aOrder_Info 	= get_post_meta($nOrder_Id);

	       	// 付款成功最後的一次 第一次付款或沒有此欄位則設定為空值 wesley
	       	$nTotalSuccessTimes = ( isset($aOrder_Info['_total_success_times'][0]) && $aOrder_Info['_total_success_times'][0] == '' ) ? '' :  $aOrder_Info['_total_success_times'][0] ;

	        // 已經開立發票才允許(找出最後一次) wesley
	        $_ecpay_invoice_status = '_ecpay_invoice_status'.$nTotalSuccessTimes ;

	        if( isset($aOrder_Info[$_ecpay_invoice_status][0]) && $aOrder_Info[$_ecpay_invoice_status][0] == 1 )
	        {
	        	// 發票號碼
	        	$_ecpay_invoice_number = '_ecpay_invoice_number'.$nTotalSuccessTimes ;
	 		$sInvoice_Number	= get_post_meta($nOrder_Id, $_ecpay_invoice_number, true) ; 	

	 		// 取得發票介接參數設定
	 		$aConfig_Invoice 	= get_option('wc_ecpayinvoice_active_model') ;
	 		$MerchantID 		= $aConfig_Invoice['wc_ecpay_invoice_merchantid'] ;
	 		$HashKey 		= $aConfig_Invoice['wc_ecpay_invoice_hashkey'] ;
	 		$HashIV 		= $aConfig_Invoice['wc_ecpay_invoice_hashiv'] ;
	 		$Invoice_Url 		= '' ;

	 		$Invoice_Url 		= ($aConfig_Invoice['wc_ecpay_invoice_testmode'] == 'enable_testmode') ? 'https://einvoice-stage.ecpay.com.tw/Invoice/IssueInvalid'  : 'https://einvoice.ecpay.com.tw/Invoice/IssueInvalid' ;

	 		// 寫入發票資訊到備註中
	 		$sInvoice_Info = '' ;
	 		$sInvoice_Info .= ' 發票作廢 : ' . $sInvoice_Number . '<br />';
	 		
	 		// 寫入備註資訊
	 		if(!empty($sInvoice_Info))
	 		{
	 			$oOrder_Obj->add_order_note($sInvoice_Info);
	 		}


	 		// 呼叫SDK 作廢發票
	 		try
			{
				$sMsg = '' ;
				
				$ecpay_invoice = new EcpayInvoice ;
				
				// 2.寫入基本介接參數
				$ecpay_invoice->Invoice_Method 		= 'INVOICE_VOID' ;
				$ecpay_invoice->Invoice_Url 		= $Invoice_Url ;
				$ecpay_invoice->MerchantID 		= $MerchantID ;
				$ecpay_invoice->HashKey 		= $HashKey ;
				$ecpay_invoice->HashIV 			= $HashIV ;
				
				// 3.寫入發票相關資訊
				$ecpay_invoice->Send['InvoiceNumber'] 	= $sInvoice_Number;
				$ecpay_invoice->Send['Reason'] 		= '發票作廢';
				
				// 4.送出
				$aReturn_Info = $ecpay_invoice->Check_Out();

			}
			catch (Exception $e)
			{
				// 例外錯誤處理。
				$sMsg = $e->getMessage();
			}


			// 寫入發票回傳資訊
			$oOrder_Obj->add_order_note(print_r($aReturn_Info, true));

			if(!empty($sMsg))
			{
				$oOrder_Obj->add_order_note($sMsg);
			}

			if(isset($aReturn_Info['RtnCode']) && $aReturn_Info['RtnCode'] == 1)
			{
				$nOrder_Invoice_Status 		= 0 ; // 發票作廢

				if(empty($nTotalSuccessTimes))
				{
					$sOrder_Invoice_Field_Name 	= '_ecpay_invoice_status' ; 	// 欄位名稱 記錄狀態
					$sOrder_Invoice_Num_Field_Name 	= '_ecpay_invoice_number' ; 	// 欄位名稱 記錄發票號碼
				}
				else
				{
					$sOrder_Invoice_Field_Name 	= '_ecpay_invoice_status'.$nTotalSuccessTimes ; 	// 欄位 記錄狀態
					$sOrder_Invoice_Num_Field_Name 	= '_ecpay_invoice_number'.$nTotalSuccessTimes ; 	// 欄位名稱 記錄發票號碼
				}

				// 異動已經開立發票的狀態 1.已經開立 0.尚未開立
				update_post_meta($nOrder_Id, $sOrder_Invoice_Field_Name, $nOrder_Invoice_Status );

				// 清除發票號碼
				update_post_meta($nOrder_Id, $sOrder_Invoice_Num_Field_Name, '');

			}
			
			return 'RelateNumber=>' . $RelateNumber . print_r($aReturn_Info, true) ;
	        }
	        else
	        {
	        	return '發票已經完成作廢，請重新整理畫面' ;
	        }
	}
	

	/**
	 * Return the plugin action links.  This will only be called if the plugin
	 * is active.
	 *
	 * @since 1.0.0
	 * @param array $actions associative array of action names to anchor tags
	 * @return array associative array of plugin action links
	 */
	public function add_plugin_action_links( $actions ) {

		$custom_actions = array(
			'configure' => sprintf( '<a href="%s">%s</a>', admin_url( 'admin.php?page=wc-settings&tab=ecpayinvoice&section=config' ), __( '參數設定', 'woocommerce-ecpayinvoice' ) )
		);

		// add the links to the front of the actions list
		return array_merge( $custom_actions, $actions );
	}


	/** Helper methods ******************************************************/


	/**
	 * Main ECPayinvoice Instance, ensures only one instance is/can be loaded
	 *
	 * @since 2.3.0
	 * @see wc_ecpayinvoice()
	 * @return \WC_ECPayinvoice
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}


	/** Lifecycle methods ******************************************************/


	/**
	 * Run every time.  Used since the activation hook is not executed when updating a plugin
	 *
	 * @since 1.1.0
	 */
	private function install() {

		// get current version to check for upgrade
		$installed_version = get_option( 'wc_ecpayinvoice_version' );

		// install
		if ( ! $installed_version ) {

			// install default settings
		}

		// upgrade if installed version lower than plugin version
		if ( -1 === version_compare( $installed_version, self::VERSION ) ) {
			$this->upgrade( $installed_version );
		}
	}


	/**
	 * Perform any version-related changes.
	 *
	 * @since 1.1.0
	 * @param int $installed_version the currently installed version of the plugin
	 */
	private function upgrade( $installed_version ) {

		// update the installed version option
		update_option( 'wc_ecpayinvoice_version', self::VERSION );
	}


}


/**
 * Returns the One True Instance of ECPayinvoice
 *
 * @since 2.3.0
 * @return \WC_ECPayinvoice
 */
function wc_ecpayinvoice() {
	return WC_ECPayinvoice::instance();
}


/**
 * The WC_ECPayinvoice global object
 * @deprecated 2.3.0
 * @name $wc_ecpayinvoice
 * @global WC_ECPayinvoice $GLOBALS['wc_ecpayinvoice']
 */
$GLOBALS['wc_ecpayinvoice'] = wc_ecpayinvoice();



// 開立發票 AJAX

add_action( 'admin_footer', 'my_action_javascript_gen_invoice' ); // Write our JS below here
function my_action_javascript_gen_invoice() { 
	?>
		<script type="text/javascript">	
			function send_orderid_to_gen_invoice(nOrder_Id)
			{
				var data = {
					'action': 'my_action',
					'oid': nOrder_Id
				};

				jQuery.blockUI({ message: null }); 
				// since 2.8 ajaxurl is always defined in the admin header and points to admin-ajax.php
				jQuery.post(ajaxurl, data, function(response) {
					alert(response);
					location.reload();
				});
				
			}
		</script>
	<?php
}


add_action( 'wp_ajax_my_action', 'orderid_return' );
function orderid_return() {
	global $woocommerce, $post, $wpdb;
	$sReturn_Msg = '';

	$nOrder_Id = intval( $_POST['oid'] );

	if(!empty($nOrder_Id))
	{
		$sReturn_Msg = WC_ECPayinvoice::gen_invoice($nOrder_Id);
		echo $sReturn_Msg ;
	}
	else
	{
		echo '無法開立發票，參數傳遞錯誤。' ; 
	}

	wp_die(); // this is required to terminate immediately and return a proper response
}


// 作費發票 AJAX
add_action( 'admin_footer', 'my_action_javascript_issue_invalid' ); // Write our JS below here

function my_action_javascript_issue_invalid() { 
	?>
		<script type="text/javascript">	
			function send_orderid_to_issue_invalid(nOrder_Id)
			{
				
				if(confirm("確定要刪除此筆發票"))
				{
					var data = {
						'action': 'my_action2',
						'oid': nOrder_Id
					};

					jQuery.blockUI({ message: null }); 
					
					jQuery.post(ajaxurl, data, function(response) {
						alert(response);
						location.reload();
					});
				}
				
			}
		</script>
	<?php
}

add_action( 'wp_ajax_my_action2', 'orderid_return_issue_invalid' );
function orderid_return_issue_invalid() {
	global $woocommerce, $post, $wpdb;
	$sReturn_Msg = '';

	$nOrder_Id = intval( $_POST['oid'] );

	if(!empty($nOrder_Id))
	{
		$sReturn_Msg = WC_ECPayinvoice::issue_invalid_invoice($nOrder_Id);
		echo $sReturn_Msg ;
	}
	else
	{
		echo '無法開立發票，參數傳遞錯誤。' ; 
	}

	wp_die(); // this is required to terminate immediately and return a proper response
}


