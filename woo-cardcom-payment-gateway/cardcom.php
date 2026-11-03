<?php
/*
Plugin Name: CardCom Payment Gateway
Plugin URI: https://support.cardcom.solutions/hc/he/articles/360007128393-%D7%97%D7%99%D7%91%D7%95%D7%A8-%D7%94%D7%A1%D7%9C%D7%99%D7%A7%D7%94-%D7%9C%D7%97%D7%A0%D7%95%D7%AA-%D7%95%D7%95%D7%A8%D7%93%D7%A4%D7%A8%D7%A1-Wordpress-Woocommerce-Payment-WOO
Description: CardCom Payment gateway for Woocommerce
Version: 3.6.0.0
Changes: Security hardening release - saved cards only usable by their logged-in owner; payment results verified against the order (payment page, operation, amount, currency); subscription card changes require the owner; secrets removed from logs and e-mails; IPN processing serialised; new minimums PHP 8.2, WordPress 7.0, WooCommerce 11.1 with admin advisories for unpatched versions. Bug fixes: Capture Charge no longer stores an empty token from a failed first attempt (7577); changing the card on a subscription keeps it a subscription under HPOS/object cache, takes it off manual renewal and charges renewals to the new card (7610). Renewal orders store their deal and document numbers so the Cardcom refund/cancel actions work on them.
Author: CardCom
Author URI: http://www.cardcom.co.il
Requires at least: 7.0
Requires PHP: 8.2
Requires Plugins: woocommerce
WC requires at least: 11.1
WC tested up to: 11.2
*/

defined('ABSPATH') || exit;

add_action('plugins_loaded', 'woocommerce_cardcom_init', 0);

/**
 * Lowest patched release per PHP branch / WordPress / WooCommerce, for the admin advisory notice.
 * PHP: PHP2Shell CVE-2026-109618 / CVE-2026-109619 (2026-10-21, CISA KEV).
 * WordPress (per branch): wp2shell CVE-2026-63030 / CVE-2026-60137 (7.0.2), CVE-2026-65640 (7.0.4),
 *   CVE-2026-87902 (7.1.2 / 7.0.6, 2026-09-22).
 * WooCommerce: CVE-2026-48888 (11.1.0), CVE-2026-106608 (11.2.0).
 */
const CARDCOM_PATCHED_VERSIONS = array(
    'php' => array('8.2' => '8.2.35', '8.3' => '8.3.36', '8.4' => '8.4.27', '8.5' => '8.5.12'),
    'wp'  => array('7.0' => '7.0.6', '7.1' => '7.1.2'),
    'wc'  => '11.2.0',
);
/** Supported floors (also in the plugin header). */
const CARDCOM_MIN_VERSIONS = array('php' => '8.2', 'wp' => '7.0', 'wc' => '11.1');

/**
 * Requirements this site does not meet. Warning only: the gateway keeps working.
 *
 * @return string[]
 */
function cardcom_environment_problems()
{
    $problems = array();
    if (version_compare(PHP_VERSION, CARDCOM_MIN_VERSIONS['php'], '<')) {
        $problems[] = sprintf('PHP %s or newer is required (this site runs %s).', CARDCOM_MIN_VERSIONS['php'], PHP_VERSION);
    }
    $wp = get_bloginfo('version');
    if (version_compare($wp, CARDCOM_MIN_VERSIONS['wp'], '<')) {
        $problems[] = sprintf('WordPress %s or newer is required (this site runs %s).', CARDCOM_MIN_VERSIONS['wp'], $wp);
    }
    if (defined('WC_VERSION') && version_compare(WC_VERSION, CARDCOM_MIN_VERSIONS['wc'], '<')) {
        $problems[] = sprintf('WooCommerce %s or newer is required (this site runs %s).', CARDCOM_MIN_VERSIONS['wc'], WC_VERSION);
    }
    return $problems;
}

/** @return bool */
function cardcom_environment_ok()
{
    return cardcom_environment_problems() === array();
}

/**
 * Security advisories for unpatched platform versions.
 *
 * @param string|null $php defaults to PHP_VERSION
 * @param string|null $wp  defaults to the running WordPress version
 * @param string|null $wc  defaults to WC_VERSION
 * @return string[]
 */
function cardcom_platform_advisories($php = null, $wp = null, $wc = null)
{
    $php = $php === null ? PHP_VERSION : $php;
    $wp = $wp === null ? get_bloginfo('version') : $wp;
    $wc = $wc === null ? (defined('WC_VERSION') ? WC_VERSION : '') : $wc;
    $out = array();
    $branch = implode('.', array_slice(explode('.', $php), 0, 2));
    $php_patched = CARDCOM_PATCHED_VERSIONS['php'];
    if (isset($php_patched[$branch])) {
        if (version_compare($php, $php_patched[$branch], '<')) {
            $out[] = sprintf('PHP %s has critical remote code execution vulnerabilities (CVE-2026-109618, CVE-2026-109619). Ask your host to update to PHP %s or newer.', $php, $php_patched[$branch]);
        }
    } elseif (version_compare($branch, '8.2', '<')) {
        $out[] = sprintf('PHP %s no longer receives security fixes. Ask your host to move to PHP 8.2 or newer (8.3 or newer recommended).', $php);
    }
    if ($wp !== '') {
        $wp_patched = CARDCOM_PATCHED_VERSIONS['wp'];
        $wp_branch = implode('.', array_slice(explode('.', preg_replace('/[^0-9.].*$/', '', $wp)), 0, 2));
        $wp_target = null;
        if (isset($wp_patched[$wp_branch])) {
            $wp_target = version_compare($wp, $wp_patched[$wp_branch], '<') ? $wp_patched[$wp_branch] : null;
        } elseif (version_compare($wp_branch, (string)array_key_first($wp_patched), '<')) {
            $wp_target = end($wp_patched); // branch older than any we track: newest fix
        }
        if ($wp_target !== null) {
            $out[] = sprintf('WordPress %s is affected by critical security vulnerabilities. Update WordPress to %s or newer.', $wp, $wp_target);
        }
    }
    if ($wc !== '' && version_compare($wc, CARDCOM_PATCHED_VERSIONS['wc'], '<')) {
        $out[] = sprintf('WooCommerce %s has known security issues. Update WooCommerce to %s or newer.', $wc, CARDCOM_PATCHED_VERSIONS['wc']);
    }
    return $out;
}

/** Admin notice for unmet requirements and platform advisories (shop managers only). */
function cardcom_environment_notice()
{
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    $lines = array_unique(array_merge(cardcom_environment_problems(), cardcom_platform_advisories()));
    if (!$lines) {
        return;
    }
    echo '<div class="notice notice-error"><p><strong>' . esc_html__('CardCom Payment Gateway', 'cardcom') . '</strong></p><ul>';
    foreach ($lines as $line) {
        echo '<li>' . esc_html($line) . '</li>';
    }
    echo '</ul></div>';
}

/**
 * Declare compatibility with WooCommerce features.
 *
 * Registered at file load (before WooCommerce fires before_woocommerce_init):
 *  - custom_order_tables (HPOS - High-Performance Order Storage): order data is read/written via order CRUD.
 *  - cart_checkout_blocks: see WC_Gateway_Cardcom_Blocks_Support.
 */
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

/**
 * Load plugin textdomain.
 */
function cardcom_load_textdomain()
{ 

    load_plugin_textdomain('cardcom', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

add_action('init', 'cardcom_load_textdomain');


//main
function woocommerce_cardcom_init()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }
    add_action('admin_notices', 'cardcom_environment_notice');

    DEFINE('PLUGIN_DIRECTORY', plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__)) . '/');

    /**
     * Gateway class
     **/
    class WC_Gateway_Cardcom extends WC_Payment_Gateway
    {
        var $terminalnumber;
        var $username;
        var $operation;
        var $cerPCI;
        var $operationToPerform; //in case operation 2 but user didnt choose save account
        var $isML;
        static $trm;
        static $cvv_free_trm;
        static $must_cvv;
        static $user;
        static $api_password;
        static $send_api_password;
        static $CoinID;
        static $sendByEmail;
        static $language;
        static $InvVATFREE;
        static $IsActivateInvoiceForPaypal;
        static $SendToEmailInvoiceForPaypal;
        static $debug_logging;
        static $plugin = "WOO-3.6.0.0";
        static $CardComURL = 'https://secure.cardcom.solutions'; // Production URL

        //declaring properties because dynamic properties are no longer supported in PHP 8.2
        public string $url;
        public string $lang;
        public string $adminEmail;
        public string $currency;
        public string $invoice;
        public string $maxpayment;
        public bool $UseIframe;
        public string $OrderStatus;
        public string $failedUrl;
        public string $successUrl;
        public string $InvoiceVATFREE;
        public string $id_field;
        public string $last_lowprofile_error = '';

        function __construct( $args = [])
        {
            $this->id = 'cardcom';
            $this->method_title = __('CardCom', 'cardcom');
            $this->has_fields = false;
            $this->url = self::$CardComURL . "/external/LowProfileClearing2.aspx";
            $this->supports = array('tokenization', 'products', 'subscriptions',
                'subscription_cancellation',
                'subscription_suspension',
                'subscription_reactivation',
                'subscription_amount_changes',
                'subscription_date_changes',
                'subscription_payment_method_change',
                'subscription_payment_method_change_customer',
                'multiple_subscriptions');
            // Load the form fields
            $this->init_form_fields();

            // Load the settings.
            $this->init_settings();

            // Load plugin checkout icon; Currently the icon we have is obsolete and should not be used
            // $this->icon = PLUGIN_DIRECTORY.'images/cards.png';

            // Resolve the language shown on the Cardcom Low Profile page:
            // PolyLang current language, then WPML, then the saved "Payment Gateway Language" setting.
            global $sitepress;
            if ( function_exists( 'pll_current_language' ) ) {
                $this->lang = pll_current_language( 'slug' ) ?: 'en';
                $this->isML = true;
            } elseif ( function_exists( 'icl_object_id' ) && defined( 'ICL_LANGUAGE_CODE' ) && isset( $sitepress ) ) {
                $this->lang = ICL_LANGUAGE_CODE;
                $this->isML = true;
            } else {
                $this->lang = $this->settings['lang'] ?? 'en';
                $this->isML = false;
            }

            // Get setting values
            $this->title = $this->settings['title'];
            $this->description = $this->settings['description'];
            $this->enabled = $this->settings['enabled'];
            $this->terminalnumber = $this->settings['terminalnumber'];
            $this->adminEmail = $this->settings['adminEmail'] ?? '';
            $this->username = $this->settings['username'];
            $this->currency = $this->settings['currency'] ?? '0';
            if (isset($this->settings['CerPCI'])) {
                $this->cerPCI = $this->settings['CerPCI'];
            } else {
                $this->cerPCI = '2';
            }
            $this->operation = $this->settings['operation'];
            $op = $this->operation;
            if ($op !== '1' && $op !== '2' && $op !== '3' && $op !== '4' && $op !== '5' && $op !== '6') {
                // Always log configuration warnings
                error_log("Cardcom ::: Warning ::: Operation value not recognized, setting to default (Charge only)");
                $this->operation = '1';
            }
            $this->operationToPerform = $this->operation;
            $this->invoice = $this->settings['invoice'] ?? '1';
            $this->maxpayment = $this->settings['maxpayment'] ?? '1';
            $this->UseIframe = $this->settings['UseIframe'] ?? '0';
            $this->OrderStatus = $this->settings['OrderStatus'] ?? 'completed';
            $this->InvoiceVATFREE = $this->settings['InvoiceVATFREE'] ?? '2';
            // $this->failedUrl = $this->settings['failedUrl'];
            // $this->successUrl = $this->settings['successUrl'];
            $this->failedUrl = '';
            $this->successUrl = '';
            self::$trm = $this->settings['terminalnumber'];
            self::$cvv_free_trm = $this->settings['cvvFreeTerminal'];
            self::$must_cvv = $this->settings['must_cvv'];
            self::$user = $this->settings['username'];
            self::$api_password = isset( $this->settings['apipass'] ) ? $this->settings['apipass'] : '';
            self::$send_api_password = isset( $this->settings['send_api_password'] ) ? $this->settings['send_api_password'] : '0';
            self::$CoinID = $this->settings['currency'];
            if (isset($this->settings['SendByEmail'])) {
                self::$sendByEmail = $this->settings['SendByEmail'];
            } else {
                self::$sendByEmail = '1';
            }
            self::$language = $this->lang;
            self::$InvVATFREE = $this->settings['InvoiceVATFREE'];
            self::$IsActivateInvoiceForPaypal = $this->settings['IsActivateInvoiceForPaypal'];
            if (isset($this->settings['SendToEmailInvoiceForPaypal'])) {
                self::$SendToEmailInvoiceForPaypal = $this->settings['SendToEmailInvoiceForPaypal'];
            } else {
                self::$SendToEmailInvoiceForPaypal = "1";
            }
            if (isset($this->settings['debug_logging'])) {
                self::$debug_logging = $this->settings['debug_logging'];
            } else {
                self::$debug_logging = '0';
            }

            $this->id_field = $this->settings['id_field'] ?? 'hidden';

            if( isset($args['block']) ) {
                add_action('woocommerce_receipt_cardcom', array(&$this, 'receipt_page'));

            // Intercept subscription payment method change POST before WC_Form_Handler corrupts the type
            add_action( 'wp_loaded', array( &$this, 'intercept_change_payment_method' ), 10 );
                return;
            }

            add_action('woocommerce_api_wc_gateway_cardcom', array($this, 'check_ipn_response'));
            add_action('valid-cardcom-ipn-request', array(&$this, 'ipn_request'));
            add_action('valid-cardcom-successful-request', array(&$this, 'successful_request'));
            add_action('valid-cardcom-cancel-request', array(&$this, 'cancel_request'));
            add_action('valid-cardcom-failed-request', array(&$this, 'failed_request'));
            

            // Hooks
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

            // Add the classic-checkout filters whenever the ID field is shown (optional or required).
            if ( $this->enabled === 'yes' && $this->id_field !== 'hidden' ) {
                add_filter( 'woocommerce_billing_fields', array( $this, 'add_id_billing_field' ) );
                add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'save_id_billing_field' ) );
            }

            // Hook on order status events
            add_filter('woocommerce_payment_complete_order_status', array($this, 'change_payment_complete_order_status'), 10, 3);
            add_action('woocommerce_order_status_completed', array($this, 'cardcom_on_order_status_completed'), 10, 2);
            add_action('woocommerce_order_status_processing', array($this, 'cardcom_on_order_status_processing'), 10, 2);

            // Hook to add action on order action dropdown
            add_action( 'admin_notices', array( $this, 'remove_custom_notices'),99 );
            add_action( 'woocommerce_order_actions', array( $this, 'add_order_meta_box_actions' ) );
            add_action( 'woocommerce_order_action_'.$this->id . '_refund', array( $this, 'cardcom_on_order_status_refunded' ));
            add_action( 'woocommerce_order_action_'.$this->id . '_cancel', array( $this, 'cardcom_on_order_status_cancelled' ));
            add_action( 'admin_enqueue_scripts', array( $this,'cardcom_admin_assets') );
            
            // Add WC_Subscription hooks if site has the plugin extension
            if (self::HasWooSubPlugin()) {
                // ======================================== Payment and Renewal Actions ======================================== //
                // Commented this action because it is not needed
                //add_action('woocommerce_scheduled_subscription_payment', array($this, 'cardcom_scheduled_subscription_payment_alt'), 10);
                add_action('woocommerce_scheduled_subscription_payment_' . $this->id, array($this, 'cardcom_scheduled_subscription_payment'), 10, 2);
                add_action('woocommerce_subscription_payment_complete', array($this, 'cardcom_subscription_payment_complete'), 10);
                add_action('woocommerce_subscription_renewal_payment_complete', array($this, 'cardcom_subscription_renewal_payment_complete'), 10, 2);
                add_action('woocommerce_subscription_payment_failed', array($this, 'cardcom_subscription_payment_failed'), 10, 2);
                add_action('woocommerce_subscription_renewal_payment_failed', array($this, 'cardcom_subscription_renewal_payment_failed'), 10);
                // ===================================== Subscription Status Change Actions ==================================== //
                add_action('woocommerce_subscription_status_updated', array($this, 'cardcom_subscription_status_updated'), 10, 3);
                add_action('woocommerce_subscription_status_active', array($this, 'cardcom_subscription_status_active'), 10);
                add_action('woocommerce_subscription_status_cancelled', array($this, 'cardcom_subscription_status_cancelled'), 10);
                add_action('woocommerce_subscription_status_expired', array($this, 'cardcom_subscription_status_expired'), 10);
                add_action('woocommerce_subscription_status_on-hold', array($this, 'cardcom_subscription_status_on_hold'), 10);
            }
        }

        /**
         * Include the admin assets.
         */
        public function cardcom_admin_assets() {

            wp_enqueue_script( 'cardcom-admin-script', plugin_dir_url( __FILE__ ) . 'admin/cardcom.js',
            array('jquery'),
            WC()->version );

            wp_localize_script(
                        'cardcom-admin-script',
                        'cardcom_ajax_object',
                        array(
                            'ajax_url' => admin_url( 'admin-ajax.php' ),
                            'current_lang' => get_user_locale(get_current_user_id()),
                        )
                    );

        }//end admin_assets()


        public static function remove_custom_notices()
        {
            if(is_admin()){
                $adminnotice = new WC_Admin_Notices();

                $adminnotice->remove_notice('cardcom_on_order_status_refunded');
                $adminnotice->remove_notice('cardcom_on_order_status_cancelled');
                $adminnotice->remove_notice('cardcom_on_order_status_refunded_permission_denied');
                $adminnotice->remove_notice('cardcom_on_order_status_cancelled_permission_denied');
            }

        }

        public static function init()
        {
            //add_action( 'woocommerce_order_status_completed', array( get_called_class(), 'CreateinvoiceForPayPal' ) );
            //add_action( 'woocommerce_order_status_processing', array( get_called_class(), 'CreateinvoiceForPayPal' ) );
            // add_action( 'paypal_ipn_for_wordpress_payment_status_completed', array( get_called_class(), 'CreateinvoiceForPayPal' ) );
            add_action('valid-paypal-standard-ipn-request', array(get_called_class(), 'ValidatePaypalRequest')); // For "PayPal Standard" gateway
            //  add_action( 'woocommerce_paypal_express_checkout_valid_ipn_request', array(get_called_class(), 'CreateinvoiceForPayPal' ) ); // For "Paypal Express Checkout"
            add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ get_called_class(), 'plugin_action_links' ] );
            add_filter( 'is_protected_meta', array( get_called_class(), 'protect_cardcom_meta' ), 10, 3 );


        }

        /**
		 * Add plugin action links.
		 *
		 * @since 1.0.0
		 * @version 4.0.0
		 */
		public static function plugin_action_links( $links ) {
			$plugin_links = [
				'<a href="admin.php?page=wc-settings&tab=checkout&section=cardcom">' . esc_html__( 'Settings', 'cardcom' ) . '</a>',
			];
			return array_merge( $plugin_links, $links );
		}


        public function cardcom_on_order_status_refunded($order)
        {
            if ( ! $order || ! current_user_can( 'edit_shop_order', $order->get_id() ) ) {
                $msg = ! $order
                    ? __( 'Unable to process Cardcom refund action: order not found.', 'cardcom' )
                    : __( 'You do not have permission to run this Cardcom refund action.', 'cardcom' );

                $adminnotice = new WC_Admin_Notices();
                $adminnotice->add_custom_notice( 'cardcom_on_order_status_refunded_permission_denied', $msg );
                $adminnotice->output_custom_notices();
                return;
            }

            $log_title = "cardcom_on_order_status_refunded method";
            self::cardcom_log($log_title, "================== START ==================");
            self::cardcom_log($log_title, "Order Id : " . $order->get_id());
            $document_no = $order->get_meta('initial_document_no');
            $document_type = $order->get_meta('initial_document_type');

            if( !empty($document_no) && !empty($document_type) ){
                $bodyArray = [
                    "ApiName"        => self::$user,
                    "ApiPassword"    => self::$api_password,
                    "DocumentNumber" => $document_no,
                    "DocumentType"   => $document_type,
                ];
                
                
                $urlencoded = http_build_query($bodyArray);

                $data = wp_remote_post(self::$CardComURL . '/api/v11/Documents/CancelDoc',array(
                    'body'    => $urlencoded,
                    'timeout' => '7',
                    'redirection' => 0, // never follow redirects with credentials in the body
                    'httpversion' => '1.0',
                    'blocking' => true,
                    'headers' => array(),
                    'cookies' => array()
                ));

                $cancelledInfo = json_decode( wp_remote_retrieve_body($data) );
                
                // To do : update document type and no from rest api response
                if( !empty( $cancelledInfo->NewDocumentType ) && !empty( $cancelledInfo->NewDocumentNumber ) ){
                    $order->update_meta_data('cancelled_document_type', $cancelledInfo->NewDocumentType);
                    $order->update_meta_data('cancelled_document_no', $cancelledInfo->NewDocumentNumber);

                // Update order status
                    // $order->update_status("wc-cancelled", 'Cancelled', TRUE);

                    $order->add_order_note(__("Order was cancelled & Refund Invoice sent.", 'cardcom'));
                    $order->add_order_note(__("New cancelled invoice no :", 'cardcom') . $cancelledInfo->NewDocumentNumber);

                    $order->update_status('refunded', 'Order cancelled & Refund Invoice sent.');
                    
                }
                else{
                    $msg = 'Cardcom Api response=' . (is_object($cancelledInfo) && isset($cancelledInfo->Description) ? $cancelledInfo->Description : 'no response');
                    $adminnotice = new WC_Admin_Notices();
                    $adminnotice->add_custom_notice("cardcom_on_order_status_refunded", esc_html($msg));
                    $adminnotice->output_custom_notices();

                    $order->add_order_note(esc_html($msg));
                }
            }
            else{

                $msg = 'Required information : document_no & document_type not available in order for Cardcom Api.';

                $adminnotice = new WC_Admin_Notices();
                $adminnotice->add_custom_notice("cardcom_on_order_status_refunded",$msg);
                $adminnotice->output_custom_notices();        

                $order->add_order_note($msg);        
            }

        }

        public function cardcom_on_order_status_cancelled($order)
        {
            if ( ! $order || ! current_user_can( 'edit_shop_order', $order->get_id() ) ) {
                $msg = ! $order
                    ? __( 'Unable to process Cardcom cancel action: order not found.', 'cardcom' )
                    : __( 'You do not have permission to run this Cardcom cancel action.', 'cardcom' );

                $adminnotice = new WC_Admin_Notices();
                $adminnotice->add_custom_notice( 'cardcom_on_order_status_cancelled_permission_denied', $msg );
                $adminnotice->output_custom_notices();
                return;
            }

            $log_title = "cardcom_on_order_status_cancelled method";
            self::cardcom_log($log_title, "================== START ==================");
            self::cardcom_log($log_title, "Order Id : " . $order->get_id());
            $transaction_id = $order->get_meta( 'CardcomInternalDealNumber' );
            if ( empty( $transaction_id ) ) {
                // Renewal orders charged before 3.6.0.0 kept the deal number under this key only.
                $legacy = (string) $order->get_meta( 'cardcom_internal_deal_number' );
                $transaction_id = ctype_digit( $legacy ) && (int) $legacy > 0 ? $legacy : '';
            }

            if( !empty($transaction_id) ){
                $bodyArray = [
                    "ApiName"        => self::$user,
                    "ApiPassword"    => self::$api_password,
                    "TranzactionId" => $transaction_id,
                    "CancelOnly"   => true,
                ];
                
                
                $urlencoded = http_build_query($bodyArray);

                $data = wp_remote_post(self::$CardComURL . '/api/v11/Transactions/RefundByTranzactionId',array(
                    'body'    => $urlencoded,
                    'timeout' => '7',
                    'redirection' => 0, // never follow redirects with credentials in the body
                    'httpversion' => '1.0',
                    'blocking' => true,
                    'headers' => array(),
                    'cookies' => array()
                ));

                $cancelledInfo = json_decode( wp_remote_retrieve_body($data) );
                
                // To do : update document type and no from rest api response
                if( isset( $cancelledInfo->NewTranzactionId ) && !empty( $cancelledInfo->NewTranzactionId ) ){
                    $order->set_transaction_id($cancelledInfo->NewTranzactionId);
                    // update_post_meta((int)$order->get_id(), 'CardcomInternalDealNumber', $cancelledInfo->NewTranzactionId);
                    $order->update_meta_data( 'CardcomInternalDealNumber', $cancelledInfo->NewTranzactionId );
                    $order->save();

                // Update order status
                    // $order->update_status("wc-cancelled", 'Cancelled', TRUE);

                    $order->add_order_note(__("Order was cancelled.", 'cardcom'));
                    $order->add_order_note(__("New transaction no :", 'cardcom') . $cancelledInfo->NewTranzactionId);

                    $order->update_status('cancelled', 'Order cancelled.');
                    
                }
                else{
                    
                    $msg = 'Cardcom Api response=' . (is_object($cancelledInfo) && isset($cancelledInfo->Description) ? $cancelledInfo->Description : 'no response');
                    $adminnotice = new WC_Admin_Notices();
                    $adminnotice->add_custom_notice("cardcom_on_order_status_cancelled", esc_html($msg));
                    $adminnotice->output_custom_notices();

                    $order->add_order_note(esc_html($msg));
                }
            }
            else{
                $msg = 'Required information : transaction_id not available in order for Cardcom Api.';

                $adminnotice = new WC_Admin_Notices();
                $adminnotice->add_custom_notice("cardcom_on_order_status_cancelled",$msg);
                $adminnotice->output_custom_notices();        

                $order->add_order_note($msg);

            }

        }

        public function add_order_meta_box_actions( $actions ) {
            if('he_IL' === get_user_locale(get_current_user_id())){
                $actions[$this->id . '_cancel'] = __("ביטול עסקה בלבד", "cardcom" );
                $actions[$this->id . '_refund'] = __( "ביטול עסקה והפקת מסמך זיכוי", "cardcom" );
            }else{
                $actions[$this->id . '_cancel'] = __( 'Refund transaction only', "cardcom" );
                $actions[$this->id . '_refund'] = __( 'Refund transaction and generate a document', "cardcom" );
            }
            return $actions;
        }

        public static function ValidatePaypalRequest($posted)
        {
            $title_log = "ValidatePaypalRequest";
            self::cardcom_log($title_log, "Initiated");
            if (self::$IsActivateInvoiceForPaypal != '1') {
                self::cardcom_log($title_log, "The option IsActivateInvoiceForPaypal is not active");
                return;
            }
            $order = !empty($posted['custom']) ? self::get_paypal_order($posted['custom']) : false;

            if ($order) {
                // Lowercase returned variables.
                self::cardcom_log($title_log, "Order Id : " . $order->get_id());
                $posted['payment_status'] = strtolower($posted['payment_status']);
                if ('completed' === $posted['payment_status']) {
                    if ($order->has_status('cancelled')) {
                        self::cardcom_log($title_log, "PayPal status complete but order has status canceled");
                    }
                    $transaction_id = !empty($posted['txn_id']) ? wc_clean($posted['txn_id']) : '';
                    $order->payment_complete($transaction_id);
                    if (!empty($posted['mc_fee'])) {
                        // update_post_meta($order->get_id(), 'PayPal Transaction Fee', wc_clean($posted['mc_fee']));
                        $order->update_meta_data( 'PayPal Transaction Fee', wc_clean($posted['mc_fee']) );
                        $order->save();
                        self::cardcom_log($title_log, "Logged PayPal transaction fee in Order.");
                    }
                    self::CreateinvoiceForPayPal($order->get_id());
                }
            } else { // Log a case where we could not find the order object
                self::cardcom_log($title_log, "Could not find the order with the value 'custom' in posted object");
                if (isset($posted['custom'])) self::cardcom_log($title_log, "The 'custom' value was present (redacted)");
                return;
            }
        }

        public static function get_paypal_order($raw_custom)
        {
            $title_log = "get_paypal_order";
            self::cardcom_log($title_log, "Initiated");
            // We have the data in the correct format, so get the order.
            $custom = json_decode($raw_custom);
            $order_id = -1;
            $order_key = "";
            if ($custom && is_object($custom)) {
                $order_id = $custom->order_id;
                self::cardcom_log($title_log, "Order Id found : " . $order_id);
                $order_key = $custom->order_key;
                self::cardcom_log($title_log, "Order key found : " . self::mask_secret($order_key));
            } else {
                // Nothing was found.
                self::cardcom_log($title_log, 'Order ID and key were not found in "custom".');
                return false;
            }
            $order = wc_get_order($order_id);
            if (!$order) {
                self::cardcom_log($title_log, "We have an invalid order_id, probably because invoice_prefix has changed.");
                $order_id = wc_get_order_id_by_order_key($order_key);
                $order = wc_get_order($order_id);
            }
            if (!$order || $order->get_order_key() !== $order_key) {
                self::cardcom_log($title_log, 'Order Keys do not match.');
                return false;
            }
            return $order;
        }

        public static function CreateinvoiceForPayPal($order_id)
        {
            $log_title = "CreateinvoiceForPayPal";
            self::cardcom_log($log_title, "Initiated");
            self::cardcom_log($log_title, "Order Id : " . $order_id);
            if (self::$IsActivateInvoiceForPaypal != '1') {
                self::cardcom_log($log_title, "'IsActivateInvoiceForPaypal' option is not active/on");
                return;
            }
            $order = new WC_Order($order_id);
            // NOTE: do NOT clear InvoiceNumber/InvoiceType here -- the dedup check below
            // (get_meta + early return) relies on them persisting to avoid issuing a
            // duplicate PayPal invoice when this runs again (repeat IPN / status change).
            self::cardcom_log($log_title, "Payment has been received from " . $order->get_payment_method());
            if (strpos($order->get_payment_method(), 'paypal') !== false) {
                //PayPal Case
                $initParams = self::initInvoice($order_id);
                $initParams['InvoiceHead.CoinISOName'] = $order->get_currency();
                $initParams['InvoiceHead.SendByEmail'] = self::$SendToEmailInvoiceForPaypal;
                $initParams["Plugin"] = self::$plugin;
                //$initParams["InvoiceType"] = "1";

                $key_1_value = $order->get_meta('InvoiceNumber');
                $key_2_value = $order->get_meta('InvoiceType');
                if (!empty($key_1_value) && !empty($key_2_value)) {
                    error_log("Order has invoice: " . $key_1_value);
                    return;
                }
                // update_post_meta((int)$order_id, 'InvoiceNumber', 0);
                $order->update_meta_data( 'InvoiceNumber', 0 );
                $order->save();
                // update_post_meta((int)$order_id, 'InvoiceType', 0);
                $order->update_meta_data( 'InvoiceType', 0 );
                $order->save();
                $initParams["CustomPay.TransactionID"] = '32';
                $initParams["CustomPay.TranDate"] = date('d/m/Y');
                $initParams["CustomPay.Description"] = 'PayPal Payments';
                $initParams["CustomPay.Asmacta"] = $order->get_transaction_id();
                $initParams["CustomPay.Sum"] = number_format($order->get_total(), 2, '.', '');

                $urlencoded = http_build_query($initParams);
                $args = array('body' => $urlencoded,
                    'timeout' => '5',
                    'redirection' => 0, // never follow redirects with credentials in the body
                    'httpversion' => '1.0',
                    'blocking' => true,
                    'headers' => array(),
                    'cookies' => array());
                $response = wp_remote_post(self::$CardComURL . '/Interface/CreateInvoice.aspx', $args);
                $body = wp_remote_retrieve_body($response);
                $responseArray = array();
                parse_str($body, $responseArray);
                if (isset($responseArray['ResponseCode'])) {
                    if ($responseArray['ResponseCode'] == 0) {
                        if (isset($responseArray['InvoiceNumber'])) {
                            $invNumber = $responseArray['InvoiceNumber'];
                            $invType = $responseArray['InvoiceType'];
                            // update_post_meta((int)$order_id, 'InvoiceNumber', $invNumber);
                            $order->update_meta_data( 'InvoiceNumber', $invNumber );
                            $order->save();
                            // update_post_meta((int)$order_id, 'InvoiceType', $invType);
                            $order->update_meta_data( 'InvoiceType', $invType );
                            $order->save();
                            self::cardcom_log($log_title, "Updated Invoice meta data in order");
                        } else {
                            self::cardcom_log($log_title, "InvoiceNumber is not set");
                        }
                    } else {
                        self::cardcom_log($log_title, "Got unsuccessful Response Code : " . $responseArray['ResponseCode']);
                    }
                } else {
                    self::cardcom_log($log_title, "Response Code was not set");
                }
            } else {
                self::cardcom_log($log_title, "Payment was not received from PayPal, so will not continue process");
            }
        }

        //region Hooks about order's status

        /**
         * Change payment complete order status to completed for COD orders.
         *
         * @since  3.2.0.0
         * @param  string $status Current order status.
         * @param  int $order_id Order ID.
         * @param  WC_Order|false $order Order object.
         * @return string
         */
        public function change_payment_complete_order_status($status, $order_id = 0, $order = false)
        {
            $log_title = "change_payment_complete_order_status method";
            self::cardcom_log($log_title, "Initiated");
            self::cardcom_log($log_title, "Order Id: " . $order_id);
            self::cardcom_log($log_title, "Status: " . $status);
            if ($this->id === $order->get_payment_method()) {
                $status = $this->OrderStatus;
            }
            return $status;
        }

        /**
         * @param $order_id int
         * @param $order WC_Order
         */
        public function cardcom_on_order_status_completed($order_id, $order)
        {
            $log_title = "cardcom_on_order_status_completed method";
            self::cardcom_log($log_title, "================== START ==================");
            self::cardcom_log($log_title, "Order Id : " . $order_id);
            $captured = $order->get_meta('cardcom_charge_captured');
            if (isset($captured)) {
                if ($captured === 'no') {
                    $this->order_capture_payment($order);
                } else if ($captured === 'yes') {
                    self::cardcom_log($log_title, "Charge was already done on this order");
                }
            }
        }

        /**
         * @param $order_id int
         * @param $order WC_Order
         */
        public function cardcom_on_order_status_processing($order_id, $order)
        {
            $log_title = "cardcom_on_order_status_progress method";
            self::cardcom_log($log_title, "================== START ==================");
            self::cardcom_log($log_title, "Order Id : " . $order_id);
            $captured = $order->get_meta('cardcom_charge_captured');
            if (isset($captured)) {
                if ($captured === 'no') {
                    $this->order_capture_payment($order);
                } else if ($captured === 'yes') {
                    self::cardcom_log($log_title, "Charge was already done on this order");
                }
            }
        }
        //endregion

        /**
         * @param WC_Order $order order to charge
         */
        public function order_capture_payment($order)
        {
            $log_title = "order_capture_payment";
            $order_id = $order->get_id();
            self::cardcom_log($log_title, "================== START ==================");
            self::cardcom_log($log_title, "Order Id : " . $order_id);
            $order->add_order_note(__("Capture Charge: charging", 'cardcom'));
            // Identify a Cardcom Capture-Charge order by the 'cardcom_charge_captured' flag, which is
            // written ONLY by Cardcom's own op-6 checkout flow (IsLowProfileCodeDealOneOK / process_payment).
            // Do NOT gate on get_payment_method() here: the order's stored _payment_method can be blank or a
            // display title (e.g. after a bulk import/edit via WP All Import / WP Sheet Editor), which used to
            // divert genuine Cardcom orders to the "not paid via Cardcom" branch -- skipping the charge while
            // WooCommerce still moved the order to completed (completed-but-uncharged).
            $captured = $order->get_meta('cardcom_charge_captured');
            if (self::IsStringSet($captured)) {
                // Get meta data about the order (i.e. metadata that only is contained in Cardcom orders)
                /**
                 * === Capture Charge if ===
                 * - There's a charge Id
                 * - There's a "capture" data is set
                 * - The "capture" data wasn't already charge
                 */
                if (self::IsStringSet($captured) && $captured == 'no') {
                    // Never send an empty token to the server. Orders captured before the
                    // IsLowProfileCodeDealOneOK fix can hold an empty 'cardcom_token_val' from a
                    // failed first attempt; fail loudly so the merchant can act instead of
                    // getting an opaque server error.
                    $tokenToCharge = $order->get_meta('cardcom_token_val');
                    if (!self::IsStringSet($tokenToCharge)) {
                        self::cardcom_log($log_title, "No token stored on order " . $order_id . " - capture not sent");
                        $order->update_status('failed', __("Capture Charge - No token stored on order, charge not sent", 'cardcom'));
                        return;
                    }
                    if ($this->invoice == '1') {
                        $body = self::initInvoice($order->get_id(), self::$cvv_free_trm);
                    } else {
                        $body = self::initTerminal($order->get_id(), self::$cvv_free_trm);
                    }
                    $body['CustomeFields.Field24'] = 'Capture Updated Price';
                    $body['CustomeFields.Field25'] = "order_id:" . $order->get_id();
                    $body['TokenToCharge.APILevel'] = '10';
                    $body["TokenToCharge.SumToBill"] = $body['SumToBill']; // Copy value from "initTerminal"/"initInvoice"
                    $body['TokenToCharge.Token'] = $tokenToCharge;
                    $body['TokenToCharge.NumOfPayments'] = $order->get_meta('cardcom_NumOfPayments');
                    $body['TokenToCharge.ApprovalNumber'] = $order->get_meta('cardcom_Approval_Num');
                    $body['TokenToCharge.CardOwnerName'] = $order->get_meta('cardcom_CardOwnerName');
                    $body['TokenToCharge.CardOwnerEmail'] = $order->get_meta('cardcom_CardOwnerEmail');
                    $body['TokenToCharge.IdentityNumber'] = $order->get_meta('cardcom_CardOwnerID');
                    $tokef = $order->get_meta('cardcom_Tokef');
                    $body['TokenToCharge.CardValidityMonth'] = substr($tokef, 0, 2);
                    $body['TokenToCharge.CardValidityYear'] = substr($tokef, 2, 2);
                    // The CC stards for "Capture-Charge"
                    $UniqAsmachta = "CC" . $order_id . $this->GetCurrentURL();
                    if (strlen($UniqAsmachta) > 50) {
                        $UniqAsmachta = substr($UniqAsmachta, 0, 50);
                    }
                    self::cardcom_log($log_title, "Unique Asmachta : " . $UniqAsmachta);
                    $body['TokenToCharge.UniqAsmachta'] = $UniqAsmachta;
                    $body['TokenToCharge.UniqAsmachtaReturnOriginal'] = "true";
                    $urlencoded = http_build_query($this->senitize($body));
                    $urlencoded = $this->append_api_password($urlencoded);
                    $args = array('body' => $urlencoded,
                        'timeout' => '7',
                        'redirection' => 0, // never follow redirects with credentials in the body
                        'httpversion' => '1.0',
                        'blocking' => true,
                        'headers' => array(),
                        'cookies' => array());
                    $response = $this->cardcom_post(self::$CardComURL . '/interface/ChargeToken.aspx', $args);
                    $wp_body = wp_remote_retrieve_body($response);
                    if (is_wp_error($wp_body)) {
                        $order->update_status('failed', "WP Error" . "\n\r " . $response->get_error_message() . ' | ');
                    } else {
                        $exp = array();
                        parse_str($wp_body, $exp);
                        $responseCode = self::try_parse_int($exp['ResponseCode']);
                        $responseDescription = $exp['Description'];
                        if (self::is_token_charge_paid($exp)) { // Charging succeeded (608: it already succeeded earlier)
                            if (self::is_duplicate_deal_response($exp)) {
                                self::note_duplicate_deal($order, $log_title);
                            } else {
                                $order->add_order_note(__("Capture Charge - Charging completed successfully", 'cardcom'));
                            }

                            if (isset($exp['InternalDealNumber'])) {
                                $InternalDealNumber = $exp['InternalDealNumber'];
                                $order->add_order_note(__("Deal Number :", 'cardcom') . $InternalDealNumber);
                                $order->update_meta_data('CardcomInternalDealNumber', $InternalDealNumber);
                                $order->update_meta_data('Cardcom Payment ID', $InternalDealNumber);
                                $order->set_transaction_id($InternalDealNumber);
                            }

							//get the invoice number in J5 capture charge
							if( isset( $exp['InvoiceResponse_InvoiceNumber'] ) ){
                                $order->update_meta_data('initial_document_no', $exp['InvoiceResponse_InvoiceNumber'] );
                                $order->add_order_note(__("document_no :", 'cardcom') . $exp['InvoiceResponse_InvoiceNumber']);
                            }

							//get the invoice type in J5 capture charge
                            if( isset( $exp['InvoiceResponse_InvoiceType'] ) ){
                                $order->update_meta_data('initial_document_type', $exp['InvoiceResponse_InvoiceType'] );
                                $order->add_order_note(__("document_type :", 'cardcom') . $exp['InvoiceResponse_InvoiceType']);
                            }

                            $order->update_meta_data('cardcom_charge_captured', 'yes');
                            $order->save();
                        } else { // An error occurred
                            $order->update_status('failed', __("Capture Charge - Charging failed, please check error", 'cardcom'));
                            $order->add_order_note("Error " . $responseCode . ' : ' . $responseDescription);
                        }
                        $order->save();
                    }
                }
            } else {
                // No Cardcom capture flag on this order -> nothing to capture. order_capture_payment() is
                // only invoked from the completed/processing handlers, which already require the flag, so
                // this branch is effectively unreachable; log it rather than mislead with an order note.
                self::cardcom_log($log_title, "No Capture-Charge flag on order " . $order_id . " - nothing to capture.");
            }
            $order->save();
        }

        public static function initTerminal($order_id, $OverTerminal = "")
        {
            $order = wc_get_order($order_id);
            if (!isset($order) || !$order) {
                $order = new WC_Order($order_id);
            }
            $params = array();
            $SumToBill = number_format($order->get_total(), 2, '.', '');
            $params["terminalnumber"] = self::$trm;
            if ($OverTerminal != "") {
                $params["terminalnumber"] = $OverTerminal;
            }

            $params["username"] = self::$user;
            $params["CodePage"] = "65001";
            $params["SumToBill"] = number_format($SumToBill, 2, '.', '');
            $params["Languge"] = self::$language;
            $params["CoinISOName"] = $order->get_currency();
            return $params;
        }

        public static function initInvoice($order_id, $OverTerminal = "")
        {
            $order = wc_get_order($order_id);
            if (!isset($order) || !$order) {
                $order = new WC_Order($order_id);
            }
            $params = array();
            $SumToBill = "";
            if ($order->get_total() > 0) {
                $SumToBill = number_format($order->get_total(), 2, '.', '');
            } else {
                $SumToBill = "0.01";
            }
            $params["terminalnumber"] = self::$trm;
            if (self::IsStringSet($OverTerminal)) {
                $params["terminalnumber"] = $OverTerminal;
            }
            $params["username"] = self::$user;
            $params["CodePage"] = "65001";
            $params["SumToBill"] = number_format($SumToBill, 2, '.', '');
            $params["Languge"] = self::$language;
            $params["CoinISOName"] = $order->get_currency();
            $compName = self::get_clean_string($order->get_billing_company());
            $lastName = self::get_clean_string($order->get_billing_last_name());
            $firstName = self::get_clean_string($order->get_billing_first_name());
            $customerName = $fullName = $firstName . " " . $lastName;
            if ($compName != '') {
                $customerName = $compName;
            }
            $params['InvoiceHead.CustName'] = $customerName;
            $params['InvoiceHead.CustAddresLine1'] = self::get_clean_string($order->get_billing_address_1());

            try{
                $params['InvoiceHead.CustCity'] = self::get_clean_string( apply_filters( 'cardcom_parameter_billing_city', $order->get_billing_city(), $order ) );
            }catch(Exception $e){
                $params['InvoiceHead.CustCity'] = self::get_clean_string($order->get_billing_city());
            }

            $params['InvoiceHead.CustAddresLine2'] = self::get_clean_string($order->get_billing_address_2());
            $zip = wc_format_postcode($order->get_shipping_postcode(), $order->get_shipping_country());
            if (!empty($zip)) {
                $params['InvoiceHead.CustAddresLine2'] .= __('Postcode / ZIP', 'woocommerce') . ': ' . self::get_clean_string($zip);
            }
            $params['InvoiceHead.CustMobilePH'] = substr(strip_tags(preg_replace("/&#\d*;/", " ", $order->get_billing_phone())), 0, 200);
            if (strtolower(self::$language) == 'he' || strtolower(self::$language) == 'en') {
                $params['InvoiceHead.Language'] = self::$language;
            } else {
                $params['InvoiceHead.Language'] = 'en';
            }
            $params['InvoiceHead.Email'] = $order->get_billing_email();
            if (self::$sendByEmail === '1') {
                $params['InvoiceHead.SendByEmail'] = 'true';
            } else {
                $params['InvoiceHead.SendByEmail'] = 'false';
            }
            if ($order->get_billing_country() != 'IL' && self::$InvVATFREE == 4) {
                $params['InvoiceHead.ExtIsVatFree'] = 'true';
            } else {
                $params['InvoiceHead.ExtIsVatFree'] = self::$InvVATFREE == '1' ? 'true' : 'false';
            }
            if (strtolower(self::$language) == 'he') {
                $params['InvoiceHead.Comments'] = 'מספר הזמנה: ' . $order->get_id() . " שם: " . $fullName;
            } else {
                $params['InvoiceHead.Comments'] = 'Order ID: ' . $order->get_id() . " Name: " . $fullName;
            }
            $ItemsCount = 0;
            $AddToString = "";
            $TotalLineCost = 0;
            $ItemShipping = $order->get_shipping_total() + $order->get_shipping_tax();
            // ============= Regardless of version: Set Shipping details in invoice ============= //
            // ------- Set item/products to invoice ------- //
            foreach ($order->get_items(array('line_item', 'fee')) as $item_id => $item) {
                $itemName = substr(strip_tags(preg_replace("/&#\d*;/", " ", $item->get_name())), 0, 200);
                if ('fee' === $item['type']) {
                    $item_line_total = $item['line_total'];
                    $TotalLineCost += $item_line_total;
                    $ItemsCount++;
                } else {
                    $product = $item->get_product();
                    $item_line_total = number_format($order->get_item_subtotal($item, true), 2, '.', '');
                    $SKU = '';
                    try {
                        if($product) {
                            $SKU = $product->get_sku();
                            $product_variation_id = $item['variation_id'];
                            // Check if product has variation.
                            if ($product_variation_id) {
                                $product = new WC_Product($item['variation_id']);
                            } else {
                                $product = new WC_Product($item['product_id']);
                            }
                            $SKU = $product->get_sku();
                        }
                    } catch (Exception $ex) {
                        error_log('Line 263 get SKU' . $ex->getMessage());
                    }
                    if (self::$InvVATFREE == '3') {
                        $params['InvoiceLines' . $AddToString . '.IsVatFree'] = (bool)$product->is_taxable() == false ? 'true' : 'false';
                        $item_line_total = number_format($order->get_item_subtotal($item, $product->is_taxable()), 2, '.', '');
                    }
                    $params['InvoiceLines' . $AddToString . '.Quantity'] = $item->get_quantity();
                    $params['InvoiceLines' . $AddToString . '.ProductID'] = $SKU;
                    $TotalLineCost += ($item_line_total * $item->get_quantity());
                    $ItemsCount++;
                }
                $params['InvoiceLines' . $AddToString . '.Description'] = $itemName;
                $params['InvoiceLines' . $AddToString . '.Price'] = $item_line_total;
                $AddToString = $ItemsCount;
            }
            // ------- Set Shipping description (if there is one) ------- //
            if ($ItemShipping != 0) {
                $ShippingDesk = substr(strip_tags(preg_replace("/&#\d*;/", " ", $order->get_shipping_method())), 0, 200);
            }
            // ------- Set Discount amount total ------- //
            $order_discount = $order->get_discount_total();
            $order_discount += $order->get_discount_tax();

            // ============= Set Shipping details in invoice ============= //
            if ($ItemShipping != 0) {
                $params['InvoiceLines' . $AddToString . '.Description'] = $ShippingDesk;
                $params['InvoiceLines' . $AddToString . '.Price'] = $ItemShipping;
                $params['InvoiceLines' . $AddToString . '.Quantity'] = 1;
                $params['InvoiceLines' . $AddToString . '.ProductID'] = "Shipping";
                $TotalLineCost += $ItemShipping;
                $ItemsCount++;
                $AddToString = $ItemsCount;
            }
            // ============= Set Coupon discount details in invoice ============= //
            if ($order_discount > 0) {
                $coupon_codes = $order->get_coupon_codes();
                if (!empty($coupon_codes)) {
                    $params['InvoiceLines' . $AddToString . '.Description'] = __("Coupon code", "woocommerce") . ": " . implode(", ", $coupon_codes);
                } else {
                    $params['InvoiceLines' . $AddToString . '.Description'] = "Discount";
                }
                $params['InvoiceLines' . $AddToString . '.Price'] = -1 * $order_discount;
                $params['InvoiceLines' . $AddToString . '.Quantity'] = 1;
                $TotalLineCost -= $order_discount;
                $ItemsCount++;
                $AddToString = $ItemsCount;
            }
            // ============= Set invoice balance in case needed ============= //
            // ======= Note! Shouldn't usually be set, but jus in case ======= //
            if (number_format($SumToBill - $TotalLineCost, 2, '.', '') != 0) {
                if (strtolower(self::$language) == 'he') {
                    $params['InvoiceLines' . $AddToString . '.Description'] = "שורת איזון עבור חשבונית בלבד";
                } else {
                    $params['InvoiceLines' . $AddToString . '.Description'] = "Balance row for invoice";
                }
                $params['InvoiceLines' . $AddToString . '.Price'] = number_format($SumToBill - $TotalLineCost, 2, '.', '');
                $params['InvoiceLines' . $AddToString . '.Quantity'] = '1';
                $params['InvoiceLines' . $AddToString . '.ProductID'] = 'Diff';
                $ItemsCount++;
                $AddToString = $ItemsCount;
            }
            // ID number (ת.ז / ח.פ) -> InvoiceHead.CompID
            // Resolve the value from every place it can live:
            //  - Classic checkout: save_id_billing_field() writes the canonical "_cardcom_id_number".
            //  - Blocks checkout: WooCommerce auto-saves "address" additional fields to
            //    "_wc_billing/<field-id>" / "_wc_shipping/<field-id>" (field id "cardcom/id-number").
            $id_number = $order->get_meta( '_cardcom_id_number' );
            if ( empty( $id_number ) ) {
                $id_number = $order->get_meta( '_wc_billing/cardcom/id-number' );
            }
            if ( empty( $id_number ) ) {
                $id_number = $order->get_meta( '_wc_shipping/cardcom/id-number' );
            }
            if ( ! empty( $id_number ) ) {
                $params['InvoiceHead.CompID'] = $id_number;
            }

            $params = apply_filters('cardcom_init_invoice_params', $params, $order);
            return $params;
        }

        //fix shipping by Or
        public static function get_shipping_method_fixed($order)
        {

            $labels = array();

            // Backwards compat < 2.1 - get shipping title stored in meta
            if ($order->shipping_method_title) {

                $labels[] = $order->shipping_method_title;
            } else {

                // 2.1+ get line items for shipping
                $shipping_methods = $order->get_shipping_methods();

                foreach ($shipping_methods as $shipping) {
                    $labels[] = $shipping['name'];
                }
            }

            return implode(',', $labels);
        }

        /**
         * Initialize Gateway Settings Form Fields
         * admin panel
         */
        function init_form_fields()
        {
            $br = '<br />';
            $nbsp = '&nbsp;';
            $this->form_fields = array(
                'title' => array(
                    'title' => __('Title', 'cardcom'),
                    'type' => 'text',
                    'description' => __('The title which the user sees during the checkout', 'cardcom'),
                    'default' => __('Cardcom', 'cardcom')
                ),
                'enabled' => array(
                    'title' => __('Enable/Disable', 'cardcom'),
                    'description' => __('Enable Cardcom', 'cardcom'),
                    'type' => 'select',
                    'options' => array('yes' => 'Yes', 'no' => 'No'),
                    'default' => 'yes'
                ),
                'description' => array(
                    'title' => __('Description', 'cardcom'),
                    'type' => 'text',
                    'description' => __('The description which the user sees during the checkout.', 'cardcom'),
                    'default' => 'Pay with Cardcom.'
                ),
                'operation' => array(
                    'title' => __('Operation', 'cardcom'),
                    'label' => __('Operation', 'cardcom'),
                    'type' => 'select',
                    'options' => array(
                        '1' => 'Charge Only',
                        '2' => 'Charge and save TOKEN',
                        '3' => 'Save Token',
                        '4' => 'Suspended Deal J2',
                        '5' => 'Suspended Deal J5 (Obsolete, use "Capture Charge")',
                        '6' => 'Capture Charge (J5 + Requires Token module)'
                    ),
                    'description' =>
                    __(" - Charge Only - As stated", 'cardcom') . $br .
                    __(" - Charge and Save TOKEN - Saves token on order and optionally allows user to save token as saved payment method", 'cardcom') . $br .
                    __(" - Save token (only) - Will only save payment method but not charge", 'cardcom') . $br .
                    __(" - Suspended Deal J2 - Check deal Validity Only", 'cardcom') . $br .
                    __(' - Suspended Deal J5 - Capture a sum to later charge (Delayed deal). The charging is done in Cardcom', 'cardcom') . $br .
                    __(" - Capture Charge - (Requires Cardcom's Token module + J5 authorization via acquirer)", 'cardcom') . $br .$nbsp.
                    __("     The payment page will \"Capture\" a sum to later charge.", 'cardcom') . $br .$nbsp.
                    __("     The charging will occur only once the order's status is changes to processing/completed.", 'cardcom') . $br .
                    __("For more questions, contact Cardcom support", 'cardcom') . $br,
                    'default' => '1'
                ),
                'CerPCI' => array(
                    'title' => __('PCI certification', 'cardcom'),
                    'label' => __('PCI certification', 'cardcom'),
                    'type' => 'select',
                    'description' => __("Check this if your website is PCI compliant, and credit card numbers can be passed through your servers.<br />If you are not sure, keep this unchecked.<br />This feature currently doesn't work with Checkout blocks. If you use it, keep this unchecked.", 'cardcom'),
                    'options' => array('1' => 'Yes', '2' => 'No'),
                    'default' => '2',
                ),
                'SendByEmail' => array(
                    'title' => __('Send By Email', 'cardcom'),
                    'type' => 'select',
                    'options' => array('0' => 'No', '1' => 'Yes'),
                    'description' => __('Send Invoice via Email', 'cardcom'),
                    'default' => '1'
                ),
                'invoice' => array(
                    'title' => __('Invoice', 'cardcom'),
                    'label' => __('Invoice', 'cardcom'),
                    'type' => 'select',
                    'options' => array('1' => 'Yes', '2' => 'Display only'),
                    'description' => __("Select Yes only if account has document module", 'cardcom'),
                    'default' => '1'
                ),
                'terminalnumber' => array(
                    'title' => __('Terminal Number', 'cardcom'),
                    'type' => 'text',
                    'description' => __('The company\' Terminal Number. To test plugin, input "1000"', 'cardcom'),
                    'default' => '999'
                ),
                'must_cvv' => array(
                    'title' => __('Must CVV', 'cardcom'),
                    'label' => __('Must CVV', 'cardcom'),
                    'type' => 'select',
                    'options' => array('0' => 'No', '1' => 'Yes'),
                    'description' => '',
                    'default' => '0'
                ),
                'cvvFreeTerminal' => array(
                    'title' => __('CVV free Terminal Number', 'cardcom'),
                    'type' => 'text',
                    'description' => __('CVV free Terminal', 'cardcom'),
                    'default' => ''
                ),
                'username' => array(
                    'title' => __('API User Name', 'cardcom'),
                    'type' => 'text',
                    'description' => __('The company API User Name. To test API, input "CardTest1994"', 'cardcom'),
                    'default' => 'CardTest1994'
                ),
                'apipass' => array(
                    'title' => __('API User Password', 'cardcom'),
                    'type' => 'password',
                    'description' => __('Required for cancel/refund API to function', 'cardcom'),
                    'default' => ''
                ),
                'send_api_password' => array(
                    'title' => __('Send API password with token charges', 'cardcom'),
                    'type' => 'select',
                    'options' => array('0' => 'No', '1' => 'Yes'),
                    'description' => __('Recommended. Saved-card charges, Capture Charge and subscription renewals will authenticate with the API User Password. Enable only after confirming the password is correct, because a wrong password makes those charges fail.', 'cardcom'),
                    'default' => '0'
                ),
                'maxpayment' => array(
                    'title' => __('Max Payment', 'cardcom'),
                    'type' => 'text',
                    'description' => __('Limit the amount of payments', 'cardcom'),
                    'default' => '1'
                ),
                'currency_disabled' => array(
                    'title' => __('Currency', 'cardcom'),
                    'type' => 'text',
                    'description' => __('Currency: 0 - Auto Detect,  1 - NIS , 2 - USD , else ISO Currency', 'cardcom'),
                    'custom_attributes' => array(
                        'disabled' => 'disabled',
                        ),
                ),
                //The real value used for the displyed disabled currency field
                'currency' => array(
                    'type' => 'hidden',
                    'default' => '0'
                ),
                'lang' => array(
                    'title' => __('Payment Gateway Language', 'cardcom'),
                    'type' => 'text',
                    'description' => __("The language that will be displayed in cardcom's payment gateway page", 'cardcom'),
                    'default' => 'en'
                ),
                'adminEmail' => array(
                    'title' => __('Admin Email', 'cardcom'),
                    'type' => 'text',
                    'description' => __('Admin Email', 'cardcom'),
                    'default' => ''
                ),
                'debug_logging' => array(
                    'title' => __('Debug Logging', 'cardcom'),
                    'label' => __('Enable Debug Logging', 'cardcom'),
                    'type' => 'select',
                    'options' => array('0' => 'No', '1' => 'Yes'),
                    'description' => __('Enable detailed logging for debugging purposes. Warning: This generates many log entries per transaction. Only enable when troubleshooting issues.', 'cardcom'),
                    'default' => '0'
                ),
                'UseIframe' => array(
                    'title' => __('Use Iframe', 'cardcom'),
                    'label' => __('Use Iframe', 'cardcom'),
                    'type' => 'select',
                    'options' => array('1' => 'Yes', '0' => 'No'),
                    'description' => '',
                    'default' => '0'
                ),
                'InvoiceVATFREE' => array(
                    'title' => __('invoice VAT free', 'cardcom'),
                    'label' => __('invoice VAT free', 'cardcom'),
                    'type' => 'select',
                    'options' => array('1' => 'Invoice VAT free', '2' => 'Invoice will include Vat', '3' => 'Invoice include Tax per product', '4' => 'Invoice include VAT by country'),
                    'description' => __('For third option  "Tax per product" please see <a href="http://kb.cardcom.co.il/article/AA-00359">help</a>', 'cardcom'),
                    'default' => '2'
                ),
                'OrderStatus' => array(
                    'title' => __('Order Status', 'cardcom'),
                    'label' => __('Order Status', 'cardcom'),
                    'type' => 'select',
                    'options' => array('processing' => 'processing', 'completed' => 'completed', 'on-hold' => 'on-hold'),
                    'description' => __("The order's status after a successful deal was made (must be set to on-hold when the operation is set to Capture Charge)", 'cardcom'),
                    'default' => 'completed'
                ),
         /*       'failedUrl' => array(
                    'title' => __('failed Url', 'cardcom'),
                    'type' => 'text',
                    'description' => __('Optional: This page is displayed after deal failed', 'cardcom'),
                    'default' => ''
                ),
                'successUrl' => array(
                    'title' => __('success Url', 'cardcom'),
                    'type' => 'text',
                    'description' => __('Optional: This page is displayed after deal succeeded', 'cardcom'),
                    'default' => ''
                ), */
                'IsActivateInvoiceForPaypal' => array(
                    'title' => __('Invoice for Paypal', 'cardcom'),
                    'label' => __('Invoice for Paypal', 'cardcom'),
                    'type' => 'select',
                    'description' => __('Activate invoice creation for Paypal', 'cardcom'),
                    'options' => array('1' => 'Yes', '2' => 'No'),
                    'default' => '2'
                ),
                'SendToEmailInvoiceForPaypal' => array(
                    'title' => __('Invoice for Paypal - Send to email', 'cardcom'),
                    'label' => __('Invoice for Paypal - Send to email'),
                    'type' => 'select',
                    'description' => __('Send Paypal Invoice to Email', 'cardcom'),
                    'options' => array('1' => 'Yes', '0' => 'No'),
                    'default' => '1'
                ),
                'id_field' => array(
                    'title'       => __( 'ID Number Field (Tax / Company ID)', 'cardcom' ),
                    'label'       => __( 'ID Number Field', 'cardcom' ),
                    'type'        => 'select',
                    'options'     => array(
                        'hidden'   => __( 'Hidden', 'cardcom' ),
                        'optional' => __( 'Optional', 'cardcom' ),
                        'required' => __( 'Required', 'cardcom' ),
                    ),
                    'description' => __( 'Add an ID number field (personal Tax ID or business Company ID) to checkout, and choose whether it\'s optional or required. The number appears on the customer\'s invoice.', 'cardcom' ),
                    'default'     => 'hidden',
                ),
            );
}

        /**
         * Admin Panel Options
         * - Options for bits like 'title' and availability on a country-by-country basis
         */

        function admin_options()
        {
            ?>
            <h3><?php _e('CardCom', 'cardcom'); ?></h3>
            <table class="form-table">
                <?php $this->generate_settings_html(); ?>
            </table><!--/.form-table-->
            <?php
        }

        /**
         * Check if this gateway is enabled and available in the user's country
         */
        function is_available()
        {
            if ($this->enabled == "yes") :
                return true;
            endif;

            return false;
        }

        //region Process Payment

        /**
         * @param int $order_id
         * @return array
         */
        function process_payment($order_id)
        {
            // =========================================================== //
            // ========== Prepare local variables to check with ========== //
            // =========================================================== //
            $log_title = "process_payment Method";
            self::cardcom_log($log_title, "Initiated");
            self::cardcom_log($log_title, "Order Id: " . $order_id);
            global $woocommerce;
            // This line of code gets an existing order or creates a new one.
            $order = wc_get_order($order_id);
            if (!isset($order) || !$order) {
                $order = new WC_Order($order_id);
            }
            self::cardcom_log($log_title, "Order total: " . $order->get_total());

            // ========== Detect Subscription Payment Method Change ========== //
            if ( self::HasWooSubPlugin()
                 && isset( $_GET['change_payment_method'] )
                 && function_exists( 'wcs_is_subscription' )
                 && wcs_is_subscription( $order_id )
            ) {
                self::cardcom_log($log_title, "Detected subscription payment method change for subscription: " . $order_id);
                return $this->process_change_subscription_payment_method( $order );
            }
            $this->operationToPerform = $this->operation;
            $isPCI = $this->cerPCI === '1';
            $paymentTokenValue = $this->get_post('wc-cardcom-payment-token'); // Selected Saved-Payment-method/Token by user
            if (!is_user_logged_in()) {
                // Guests have no saved payment methods; never honour a posted token id for them.
                $paymentTokenValue = null;
            }
            $savePaymentMethod = $this->get_post('wc-cardcom-new-payment-method'); // Checkbox if user wishes to save payment method.
            $savePaymentMethod = isset($savePaymentMethod) && $savePaymentMethod === 'true';
            $didUserSelectedSavedToken = isset($paymentTokenValue) && $paymentTokenValue !== 'new';
            // ========== Set Meta Data if: Must save token on user OR user wishes to save token as saved payment method ========== //
            // ========== The 2 above can only occur if user didn't select any saved-payment-method/token ========== //
            if ($didUserSelectedSavedToken === false && ($this->must_save_token_on_user($order) || $savePaymentMethod)) {
                $order->add_meta_data("save_token_on_user", 'true');
                $order->save_meta_data();
                self::cardcom_log($log_title, "Saving Token on User");
            }
            // ========== Split the flow by operation ========== //
            // ================================================= //
            switch ($this->operation) {
                case '1': // Charge only
                {
                    self::cardcom_log($log_title, "Operation: Charge Only");
                        // ====== Check where the merchant wants the CardHolder to insert card info. ====== //
                    return $this->navigate_process_payment($order, $paymentTokenValue);
                    break;
                }
                case '2': // Charge + Create Token
                {
                    /* ====== Charge with saved Payment ====== */
                    if (isset($paymentTokenValue) && $paymentTokenValue !== 'new') {
                        self::cardcom_log($log_title, "Using saved payment method (user's token)");
                        return $this->pay_via_direct_api($order, $paymentTokenValue);
                    } /* ====== Charge and create new token ====== */ else {
                        self::cardcom_log($log_title, "Creating a new token and charging");
                        return $this->navigate_process_payment($order, $paymentTokenValue);
                    }
                    break;
                }
                /* Options Here give the user to:
                 - Pay via save payment method
                 - Save Credit info as a new saved payment method */
                case '6': // Capture Charge
                case '3': // Create token (only)
                {
                        // ====== Set defaults if Capture Charge ====== //
                    if ($this->operation === '6') {
                        self::cardcom_log($log_title, "Operation: Capture Charge");
                            // Always set to Charge and save token.
                        $this->operationToPerform = '3';
                            // Add meta data to order for the Capture Charge to work
                        $order->add_meta_data('_set_Capture_Charge', '1');
                        $order->save_meta_data();
                    } else {
                        self::cardcom_log($log_title, "Operation: Create Token Only");
                    }
                    /* ====== Use user's saved Payment token ====== */
                    if (isset($paymentTokenValue) && $paymentTokenValue !== 'new') {
                        self::cardcom_log($log_title, "Using saved payment method (user's token)");
                        return $this->pay_via_direct_api($order, $paymentTokenValue);
                    } /* ====== Charge and create new token ====== */ else {
                        self::cardcom_log($log_title, "Creating a new token");
                        return $this->navigate_process_payment($order, $paymentTokenValue);
                    }
                    break;
                }
                case '4': // Suspended Deal J2
                case '5': // Suspended Deal J5 (Custom operation)
                {
                    return $this->pay_via_LowProfile($order);
                    break;
                }
            }
        }

        /**
         * Intercept the change_payment_method form POST and redirect directly to Cardcom.
         * Runs on wp_loaded at priority 10, BEFORE WC_Form_Handler::pay_action (priority 20).
         */
        function intercept_change_payment_method()
        {
            if ( $_SERVER['REQUEST_METHOD'] !== 'POST' || ! isset( $_GET['change_payment_method'] ) ) {
                return;
            }
            $subscription_id = absint( $_GET['change_payment_method'] );
            if ( $subscription_id <= 0 ) return;

            $selected_gateway = isset( $_POST['payment_method'] ) ? sanitize_text_field( $_POST['payment_method'] ) : '';
            if ( $selected_gateway !== $this->id ) return;

            if ( ! function_exists( 'wcs_get_subscription' ) ) return;
            $subscription = wcs_get_subscription( $subscription_id );
            if ( ! $subscription ) return;

            $log_title = "intercept_change_payment_method";
            // Only the subscription owner, from the WooCommerce Subscriptions change-payment form, may
            // start a card change. Without this anyone could attach a card to any subscription.
            if ( ! $this->can_change_subscription_payment_method( $subscription_id ) ) {
                self::cardcom_log($log_title, "Rejected payment method change for subscription " . $subscription_id . ": not authorised");
                return;
            }
            self::cardcom_log($log_title, "Intercepting POST for subscription: " . $subscription_id);

            // Flag the subscription through order CRUD on the proper WC_Subscription object.
            $subscription->update_meta_data( '_cardcom_changing_payment_method', 'yes' );
            $subscription->save();

            // GetRedirectURL loads the subscription via wc_get_order, so it is saved as a subscription.
            // No direct DB type fix here: it bypassed the object cache and left a stale shop_order copy (bug 7610).
            $originalOperation = $this->operationToPerform;
            $this->operationToPerform = '3';
            $redirect_url = $this->GetRedirectURL( $subscription_id );
            $this->operationToPerform = $originalOperation;

            self::cardcom_log($log_title, "Redirecting to Cardcom (LowProfile page)");
            wp_redirect( $redirect_url );
            exit();
        }

        /**
         * True when the current user owns the subscription and posted a valid
         * WooCommerce Subscriptions change-payment-method nonce.
         *
         * @param int $subscription_id
         * @return bool
         */
        private function can_change_subscription_payment_method( $subscription_id )
        {
            if ( ! is_user_logged_in() ) {
                return false;
            }
            if ( ! current_user_can( 'edit_shop_subscription_payment_method', $subscription_id ) ) {
                return false;
            }
            $nonce = isset( $_POST['_wcsnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wcsnonce'] ) ) : '';
            return $nonce !== '' && (bool) wp_verify_nonce( $nonce, 'wcs_change_payment_method' );
        }

        /**
         * Handle subscription payment method change.
         * Forces a token-only operation (Operation 3) to collect new card details
         * without charging the customer. Works with both iframe and redirect modes.
         *
         * @param $subscription WC_Order|WC_Subscription The subscription to update payment method for
         * @return array result array with redirect URL
         */
        function process_change_subscription_payment_method( $subscription )
        {
            $log_title = "process_change_subscription_payment_method";
            $subscription_id = $subscription->get_id();
            self::cardcom_log($log_title, "Initiated for subscription: " . $subscription_id);

            // Mark this subscription as undergoing a payment method change
            // Get proper WC_Subscription object to prevent type corruption on save
            $subscription = wcs_get_subscription( $subscription_id );
            if ( ! $subscription ) {
                self::cardcom_log($log_title, "ERROR: Could not load subscription object for ID: " . $subscription_id);
                return array( 'result' => 'failure' );
            }
            $subscription->update_meta_data( '_cardcom_changing_payment_method', 'yes' );
            $subscription->save();

            // Save the original operation and force token-only (Operation 3)
            $originalOperation = $this->operationToPerform;
            $this->operationToPerform = '3';

            // Build the LowProfile redirect URL (always use LowProfile for payment method change,
            // regardless of PCI setting — we never charge via direct API here)
            $result = array(
                'result'   => 'success',
                'redirect' => $this->GetRedirectURL( $subscription_id )
            );

            // Restore original operation
            $this->operationToPerform = $originalOperation;

            self::cardcom_log($log_title, "Redirecting to Cardcom for token collection");
            return $result;
        }

        /** Duplicate code turned into a method.
         *  This Navigates where merchant wants the user to insert Credit Card info
         *  This method is not relevant charging old tokens and Operations 4 + 5 as their always directed to LP page
         *
         * @param $order WC_Order
         * @param $paymentTokenValue
         * @return array
         */
        function navigate_process_payment($order, $paymentTokenValue)
        {
            $isPCI = $this->cerPCI === '1';
            // ====== Check where the merchant wants the CardHolder to insert card info. ====== //
            if ($isPCI) {
                return $this->pay_via_direct_api($order, $paymentTokenValue);
            } else {
                return $this->pay_via_LowProfile($order);
            }
        }

        /** Process payment in by redirecting the Cardholder to LowProfile page to insert Card info and charge
         * - This can either re-direct to the LowProfilePage or open an iframe on the WordPress site
         * - Depends on the settings the Merchant set
         * @param $order WC_Order
         * @return array that redirects to the payment gateway page
         */
        function pay_via_LowProfile($order)
        {
            $order_id = $order->get_id();
            if ($this->UseIframe == 1) /* === Open in iframe === */ {
                // The LowProfile operation is resolved server-side in receipt_page(); never carry it in the URL.
                $arr_params = array('order-pay' => $order_id);
                               
                return array(
                    'result' => 'success',
                    'redirect' => add_query_arg($arr_params, add_query_arg('key', $order->get_order_key(), $order->get_checkout_payment_url(true))));


            } else /* === Re-direct to LowProfile Page === */ {
                $this->last_lowprofile_error = '';
                $redirect = $this->GetRedirectURL($order_id);
                if ($this->last_lowprofile_error !== '') {
                    // 'failure' makes classic and block checkout show the message instead of a silent bounce.
                    return array('result' => 'failure', 'message' => $this->last_lowprofile_error);
                }
                return array(
                    'result' => 'success',
                    'redirect' => $redirect
                );
            }
        }

        /** Process payment via directly sending the necessary data to Cardcom API
         * The card info can be given by:
         * - direct input from  (i.e. Checkout page has PCI on, so Card fields are displayed)
         * - Via sending token, i.e. saved payment method
         *
         * @param $order WC_Order
         * @param $paymentTokenValue
         * @return array
         */
        function pay_via_direct_api($order, $paymentTokenValue)
        {
            $order_id = $order->get_id();
            
            if ($this->charge_token($paymentTokenValue, $order_id)) {
                // ----  From this point the Order has completed the process successfully ----
                // Remove Cart Manually (This is to prevent "הזמנה כפולה")
                WC()->cart->empty_cart();
                $redirectTo = self::string_is_set($this->successUrl) ? $this->successUrl : $this->get_return_url($order);
                if ($this->operation != '6') $order->payment_complete();
                return array(
                    'result' => 'success',
                    'redirect' => $redirectTo);
            } else {
                $redirectTo = self::string_is_set($this->failedUrl) ? $this->failedUrl : $this->get_return_url($order);
                $message = '';
                foreach( wc_get_notices('error') as $i => $error ){
                    $message = $error['notice'];           
                }
            
                return array(
                    'result' => 'fail',
                    'redirect' => $redirectTo,
                    'message'  => $message 
                );
            }
        }

        //endregion

        public static function GetCurrency($order, $currency)
        {

            if ($currency != 0)
                return $currency;

            // if woo graeter then 3.0 use get_currency
            $cur = $order->get_currency();


            if ($cur == "ILS")
                return 1;
            else if ($cur == "NIS")
                return 1;
            else if ($cur == "AUD")
                return 36;
            else if ($cur == "USD")
                return 2;
            else if ($cur == "CAD")
                return 124;
            else if ($cur == "DKK")
                return 208;
            else if ($cur == "JPY")
                return 392;
            else if ($cur == "CHF")
                return 756;
            else if ($cur == "GBP")
                return 826;
            else if ($cur == "USD")
                return 2;
            else if ($cur == "EUR")
                return 978;
            else if ($cur == "RUB")
                return 643;
            else if ($cur == "SEK")
                return 752;
            else if ($cur == "NOK")
                return 578;
            return $cur;
        }

        /***
         * @param $order_id
         * @return string
         *
         * Gets order parameter to sent to cardcom
         */
        function GetRedirectURL($order_id)
        {
            $log_title = "GetRedirectURL";
            self::cardcom_log($log_title, "Order Id: " . $order_id);
            global $woocommerce;
            // =============================================================== //
            // ======================= Prepare Request ======================= //
            // =============================================================== //
            // wc_get_order returns a WC_Subscription for subscription ids. Saving a subscription through
            // a plain WC_Order turns it into shop_order under HPOS (bug 7610).
            $order = wc_get_order($order_id);
            if (!$order) {
                $order = new WC_Order($order_id);
            }
            $lastName = substr(strip_tags(preg_replace("/&#\d*;/", " ", $order->get_billing_last_name())), 0, 200);
            $firstName = substr(strip_tags(preg_replace("/&#\d*;/", " ", $order->get_billing_first_name())), 0, 200);
            $email = $order->get_billing_email();
            $params = array();
            // Reset order-level Cardcom flags before re-initiating the deal.
            // These are order meta (written via $order->update_meta_data), so clear
            // them through order CRUD -- wc_delete_order_item_meta() targets order
            // line-item meta by item id and would never clear them (HPOS or not).
            $order->delete_meta_data('CardcomInternalDealNumber');
            $order->delete_meta_data('IsIpnRecieved');
            $order->delete_meta_data('InvoiceNumber');
            $order->delete_meta_data('InvoiceType');
            $order->save();
            $params = self::initInvoice($order_id); if ($this->operationToPerform == '3') { foreach (array_keys($params) as $k) { if (strpos($k, 'Invoice') === 0) unset($params[$k]); } }

            $params["APILevel"] = "9";
            $params["CardOwnerName"] = $firstName . " " . $lastName;
            $params["CardOwnerEmail"] = $email;
            $params["Plugin"] = self::$plugin;
            $params['CustomFields.Field25'] = 'WooCommerce Deal ' . "order_id : " . $order_id;
            // https://github.com/UnifiedPaymentSolutions/woocommerce-payment-gateway-everypay/blob/master/includes/class-wc-gateway-everypay.php
            // Redirect
            // Carry the per-order key (a WooCommerce-issued secret) on every callback URL.
            // check_ipn_response() validates it before acting, so unauthenticated callers
            // cannot drive state transitions for orders they don't own (CVE-2025-57976).
            $order_key_param = '&key=' . rawurlencode($order->get_order_key());
            if (strpos(home_url(), '?') !== false) {

                $params["ErrorRedirectUrl"] = untrailingslashit(home_url()) . '&wc-api=WC_Gateway_Cardcom&' . ('cardcomListener=cardcom_failed&order_id=' . $order_id) . $order_key_param;
                $params["IndicatorUrl"] = untrailingslashit(home_url()) . '&wc-api=WC_Gateway_Cardcom&' . ('cardcomListener=cardcom_IPN&order_id=' . $order_id) . $order_key_param;
                $params["SuccessRedirectUrl"] = untrailingslashit(home_url()) . '&wc-api=WC_Gateway_Cardcom&' . ('cardcomListener=cardcom_successful&order_id=' . $order_id) . $order_key_param;
                $params["CancelUrl"] = untrailingslashit(home_url()) . '&wc-api=WC_Gateway_Cardcom&' . ('cardcomListener=cardcom_cancel&order_id=' . $order_id) . $order_key_param;

            } else {
                $params["ErrorRedirectUrl"] = untrailingslashit(home_url()) . '?wc-api=WC_Gateway_Cardcom&' . ('cardcomListener=cardcom_failed&order_id=' . $order_id) . $order_key_param;
                $params["IndicatorUrl"] = untrailingslashit(home_url()) . '?wc-api=WC_Gateway_Cardcom&' . ('cardcomListener=cardcom_IPN&order_id=' . $order_id) . $order_key_param;
                $params["SuccessRedirectUrl"] = untrailingslashit(home_url()) . '?wc-api=WC_Gateway_Cardcom&' . ('cardcomListener=cardcom_successful&order_id=' . $order_id) . $order_key_param;
                $params["CancelUrl"] = untrailingslashit(home_url()) . '?wc-api=WC_Gateway_Cardcom&' . ('cardcomListener=cardcom_cancel&order_id=' . $order_id) . $order_key_param;
            }
            $params["CancelType"] = "2";
            $params["ProductName"] = "Order Id:" . $order_id;
            $params["ReturnValue"] = $order_id;
            // ============= Set params for Operations 4 and 5 ============= //
            if ($this->operation == '4' || $this->operation == '5') // Req Params for Suspend Deal
            {
                $this->operationToPerform = '4';
                if ($this->terminalnumber == 1000 || $this->operation == '4') {
                    $params['SuspendedDealJValidateType'] = "2";
                } else {
                    $params['SuspendedDealJValidateType'] = "5";
                }

                $params['SuspendedDealGroup'] = "1";

            }
            if ($order->get_total() > 0 && !empty($this->maxpayment) && $this->maxpayment >= "1") {
                $params['MaxNumOfPayments'] = $this->maxpayment;
            }
            // ============= Set params for Operation "Capture Charge" ============= //
            if ($this->operation == '6') {
                $params['CreateTokenJValidateType'] = '5';
                $params["CustomFields.Field24"] = "Capture Charge Deal";
            }
            self::cardcom_log($log_title, "operationToPerform: " . $this->operationToPerform);
            $params["Operation"] = $this->operationToPerform;
            // Remember what we asked Cardcom for, so the IPN result can be checked against it.
            $order->update_meta_data('_cardcom_lp_operation', (string)$this->operationToPerform);
            $order->save();
            $params["ClientIP"] = $this->GetClientIP();
            if ($this->operationToPerform == '3') { /* token-only: skip InvoiceHeadOperation */ } elseif ($this->invoice == '1' && $this->operation != '3' && $this->operation != '6') {
                $params['InvoiceHeadOperation'] = "1"; // Create Invoice
            } else {
                $params['InvoiceHeadOperation'] = "2"; // Show Only
            }
            $params = apply_filters('cardcom_redirect_url_params', $params, $order_id);
            $urlencoded = http_build_query($this->senitize($params));
            $args = array('body' => $urlencoded,
                'timeout' => '5',
                'redirection' => 0, // never follow redirects with credentials in the body
                'httpversion' => '1.0',
                'blocking' => true,
                'headers' => array(),
                'cookies' => array());
            // ============================================================ //
            // ======================= Get Response ======================= //
            // ============================================================ //
            $response = $this->cardcom_post(self::$CardComURL . '/BillGoldLowProfile.aspx', $args);
            if (is_wp_error($response)) {
                $this->last_lowprofile_error = __('The payment page could not be opened. Please try again or contact the store.', 'cardcom');
                wc_add_notice($this->last_lowprofile_error, 'error');
                return wc_get_checkout_url();
            }
            $body = wp_remote_retrieve_body($response);
            $exp = explode(';', $body);
            $data = array();
            $IsOk = true;
            if ($exp[0] == "0") {
                $IsOk = true;
                $data['profile'] = $exp[1];
                // update_post_meta((int)$order_id, 'Profile', $data['profile']);
                $order->update_meta_data( 'Profile',  $data['profile']);
                // Bind the IPN result to this exact LowProfile: code, amount and currency we sent.
                // WooCommerce reuses a pending order when the cart changes, so an older, cheaper
                // LowProfile for the same order id must not be able to pay the updated order.
                $order->update_meta_data('_cardcom_lp_code', (string)$data['profile']);
                $order->update_meta_data('_cardcom_lp_sum', (string)$params['SumToBill']);
                $order->update_meta_data('_cardcom_lp_currency', (string)$params['CoinISOName']);
                // Every page issued for this order, so an earlier tab / reloaded page with the same amount
                // still completes the order (review I2). Capped to the latest 10.
                $issued = json_decode((string)$order->get_meta('_cardcom_lp_codes'), true);
                if (!is_array($issued)) {
                    $issued = array();
                }
                unset($issued[(string)$data['profile']]);
                $issued[(string)$data['profile']] = array(
                    'sum' => (string)$params['SumToBill'],
                    'cur' => (string)$params['CoinISOName'],
                    'op'  => (string)$params['Operation'],
                );
                if (count($issued) > 10) {
                    $issued = array_slice($issued, -10, 10, true);
                }
                $order->update_meta_data('_cardcom_lp_codes', wp_json_encode($issued));
                $order->save();
            } else {
                $IsOk = false;
                $this->HandleError($exp[0], $body, $urlencoded);
            }
            $requestVars = array();
            $requestVars["terminalnumber"] = self::$trm;
            $requestVars["Rcode"] = $exp[0];
            $requestVars["lowprofilecode"] = $exp[1];
            if ($IsOk) {
                return $this->url . "?" . http_build_query($this->senitize($requestVars));
            } else {
                $this->last_lowprofile_error = sprintf(__('The payment page could not be opened (Cardcom code %s). Please try again or contact the store.', 'cardcom'), self::get_clean_string($exp[0]));
                wc_add_notice($this->last_lowprofile_error, 'error');
                return wc_get_checkout_url();
            }
        }

        //Handle Post to Cardcom
        function cardcom_post($url, $args)
        {
            // 1st try
            $response = wp_remote_post($url, $args); // SERVER CALL
            // Try 3 more times if fails
            if (is_wp_error($response)) {
                $counter = 3;
                while ($counter > 0) {
                    $response = wp_remote_post($url, $args); // SERVER CALL
                    $counter--;
                    // ======= Success ======= //
                    if (is_wp_error($response) == false) {
                        break;
                    }
                    // ======= Fails (Write error message ONCE if after the other 3 tries ALSO failed) ======= //
                    if ($counter == 0) {
                        // Always log critical API connection failures
                        error_log("Cardcom ::: post failed ::: Url : " . $url);
                        $error = $response->get_error_message();
                        $this->HandleError('999', $error, $args);
                    }
                }
            }
            return $response;
        }

        /**
         * True when a parsed ChargeToken.aspx response means the customer has been charged:
         * either a fresh success (ResponseCode 0) or Cardcom's duplicate-deal guard (608).
         * All token-charge callers (checkout token charge, capture charge, scheduled renewals)
         * must use this so they treat both outcomes the same way.
         */
        /**
         * Stores the deal number (as CardcomInternalDealNumber and the transaction id) and, when an invoice
         * was created, the document number/type from a paid ChargeToken.aspx response.
         *
         * @param WC_Order $order
         * @param array    $responseArray parse_str() of the ChargeToken response
         * @return string the deal number, or '' when Cardcom returned none
         */
        private static function store_token_charge_references($order, $responseArray)
        {
            $deal = isset($responseArray['InternalDealNumber']) ? (string)$responseArray['InternalDealNumber'] : '';
            if (!ctype_digit($deal) || (int)$deal <= 0) {
                $deal = '';
            }
            if ($deal !== '') {
                $order->update_meta_data('CardcomInternalDealNumber', $deal);
                $order->set_transaction_id($deal);
            }
            $doc_no = isset($responseArray['InvoiceResponse_InvoiceNumber']) ? (string)$responseArray['InvoiceResponse_InvoiceNumber'] : '';
            $doc_type = isset($responseArray['InvoiceResponse_InvoiceType']) ? (string)$responseArray['InvoiceResponse_InvoiceType'] : '';
            if (ctype_digit($doc_no) && (int)$doc_no > 0 && $doc_type !== '') {
                $order->update_meta_data('initial_document_no', $doc_no);
                $order->update_meta_data('initial_document_type', $doc_type);
            }
            $order->save();
            return $deal;
        }

        private static function is_token_charge_paid($responseArray)
        {
            return isset($responseArray['ResponseCode'])
                && ($responseArray['ResponseCode'] == '0' || self::is_duplicate_deal_response($responseArray));
        }

        /**
         * True when a parsed ChargeToken.aspx response is Cardcom's duplicate-deal guard
         * (ResponseCode 608) for a reused TokenToCharge.UniqAsmachta. Cardcom records a
         * UniqAsmachta only for successful deals and looks it up on the same terminal over the
         * last 6 months, so a 608 means a charge with this exact UniqAsmachta already succeeded
         * (typically a retry after a lost/timed-out response). Each caller builds its
         * UniqAsmachta from its own order id, so the earlier charge was for this same order.
         * UniqAsmachtaReturnOriginal would return the original deal instead, but Cardcom ignores
         * it when an invoice is created (invoice=1), so in that case no deal number is available.
         */
        private static function is_duplicate_deal_response($responseArray)
        {
            return isset($responseArray['ResponseCode']) && $responseArray['ResponseCode'] == '608';
        }

        /**
         * Logs a 608 duplicate-deal response and adds the standard order note for it.
         *
         * @param WC_Order $order
         * @param string $log_title
         */
        private static function note_duplicate_deal($order, $log_title)
        {
            self::cardcom_log($log_title, "Cardcom reported 608 (duplicate deal) for order " . $order->get_id() . " - treating as already paid");
            $order->add_order_note(__("Cardcom reported this charge as already completed (608 - duplicate deal), so the customer was not charged again and the payment is treated as successful. The deal number is not available here; please verify the deal in Cardcom.", 'cardcom'));
        }

        function HandleError($Error, $msg, $info)
        {
            // Never mail or log the request body: it carries customer PII, the API user name and,
            // in PCI mode, card data. Report only the code and Cardcom's (redacted) response text.
            $response = self::redact_string(substr((string)$msg, 0, 500));
            if ($this->adminEmail != '') {
                wp_mail($this->adminEmail, 'Cardcom payment gateway something went wrong',
                    "Wordpress Transaction Failed!\n" .
                    "Terminal Number: " . $this->terminalnumber . "\n" .
                    "Error Code: " . $Error . "\n" .
                    "Cardcom Response: " . $response . "\n" .
                    "Please contact Cardcom support with this information"
                );
            }
            // Always log critical errors regardless of debug setting
            $details = self::error_context($info);
            error_log("Cardcom ::: Handle Error ::: Terminal Number: " . $this->terminalnumber .
                " | Error Code: " . $Error . " | Cardcom Response: " . $response .
                " | Request (redacted): " . (is_array($details) ? wp_json_encode($details) : $details));
        }

        /**
         * Keys whose values must never reach logs or e-mails (compared case-insensitively, '.' == '_').
         */
        private static $redact_keys = array(
            'key', 'order_key', 'token', 'tokentocharge_token', 'cardcom_token_val', 'cardcomtoken', 'cardnumber',
            'tokentocharge_cardnumber', 'cvv2', 'tokentocharge_cvv2', 'cardownerid', 'identitynumber',
            'tokentocharge_identitynumber', 'apipassword', 'apipass', 'userpassword', 'tokentocharge_userpassword',
            'username', 'email', 'cardowneremail', 'invoicehead_email', 'tokentocharge_cardowneremail',
            'cardownerphone', 'tokentocharge_cardownerphone', 'invoicehead_custmobileph', 'cardcom-card-number',
            'cardcom-card-cvc', 'cardcom-citizen-id', 'cardcom_id_number', 'invoicehead_compid', 'custom',
            'cardownername', 'tokentocharge_cardownername', 'invoicehead_custname', 'invoicehead_custaddresline1',
            'invoicehead_custaddresline2', 'invoicehead_custcity', 'invoicehead_comments', 'extshvaparams_cardownername',
            'errorredirecturl', 'indicatorurl', 'successredirecturl', 'cancelurl', 'cardholderidentitynumber',
            'extshvaparams_cardtoken', 'extshvaparams_cardholderidentitynumber',
        );

        /**
         * Returns a copy of $data with secrets replaced by '[redacted]' and lowprofilecode reduced to its last 4.
         *
         * @param array $data
         * @return array
         */
        public static function redact($data)
        {
            if (!is_array($data)) {
                return $data;
            }
            $out = array();
            foreach ($data as $k => $v) {
                $norm = str_replace('.', '_', strtolower((string)$k));
                if (is_array($v)) {
                    $out[$k] = self::redact($v);
                } elseif (in_array($norm, self::$redact_keys, true)) {
                    $out[$k] = '[redacted]';
                } elseif ($norm === 'lowprofilecode' || $norm === 'profile' || $norm === '_cardcom_lp_code') {
                    $out[$k] = self::mask_secret($v);
                } else {
                    $out[$k] = $v;
                }
            }
            return $out;
        }

        /**
         * Non-sensitive request fields worth keeping in an error log line.
         *
         * @param mixed $info array or urlencoded string
         * @return array
         */
        private static function error_context($info)
        {
            $arr = array();
            if (is_array($info)) {
                $arr = isset($info['body']) && is_string($info['body']) ? array() : $info;
                if (isset($info['body']) && is_string($info['body'])) parse_str($info['body'], $arr);
            } elseif (is_string($info)) {
                parse_str($info, $arr);
            }
            $keep = array('terminalnumber', 'SumToBill', 'TokenToCharge_SumToBill', 'Operation', 'ReturnValue', 'CoinISOName', 'Plugin');
            return array_intersect_key($arr, array_flip($keep));
        }

        /**
         * Redacts a query-string-like string (a=b&c=d); other strings are returned unchanged.
         *
         * @param string $s
         * @return string
         */
        public static function redact_string($s)
        {
            if (strpos($s, '=') === false) {
                return $s;
            }
            $parsed = array();
            parse_str($s, $parsed);
            return http_build_query(self::redact($parsed));
        }

        /**
         * '...' + the last 4 characters, enough for support to match a log line to a deal.
         *
         * @param mixed $v
         * @return string
         */
        public static function mask_secret($v)
        {
            $v = (string)$v;
            return strlen($v) <= 4 ? '****' : '...' . substr($v, -4);
        }

        /**
         * Hide Cardcom token / card meta from the order "Custom Fields" box. Hooked on is_protected_meta.
         *
         * @param bool   $protected
         * @param string $meta_key
         * @param string $meta_type
         * @return bool
         */
        public static function protect_cardcom_meta($protected, $meta_key, $meta_type = '')
        {
            $exact = array('CardcomToken', 'CardcomTokenId', 'CardcomToken_expiry_year', 'CardcomToken_expiry_month',
                'CardcomTokenExDate', 'cardcom_token_val', 'cardcom_Tokef', 'cardcom_Approval_Num', 'cardcom_CardOwnerID',
                'cardcom_CardOwnerEmail', 'cardcom_CardOwnerName', 'cardcom_NumOfPayments', 'Profile');
            $exact = array_merge($exact, array('cc_number', 'cc_holdername', 'cc_numofpayments', 'cc_firstpayment', 'cc_paymenttype',
                'cc_total', 'cc_cardtype', 'cc_Sulac', 'cc_Mutag', 'cc_Tokef'));
            if (in_array($meta_key, $exact, true)) {
                return true;
            }
            return $protected;
        }

        /**
         * True only when a LowProfile indicator result belongs to the LowProfile this plugin last issued
         * for the order: same code, same operation, same amount and currency.
         *
         * Amount: ExtShvaParams.Sum36 is the card-processor sum in agorot (minor units). It is absent when no
         * card transaction ran; that is only acceptable for token-only (3) and suspended (4) operations.
         * Currency: CoinId is CardCom's numeric coin id; compared when both sides are numeric.
         * Orders created before 3.6.0.0 have no _cardcom_lp_* meta; they keep the old checks plus an
         * amount check against the order total.
         *
         * @param array    $response parse_str() of the indicator response
         * @param WC_Order $order
         * @param string   $lpc      LowProfile code the IPN claims
         * @param string   $reason   Set to the first mismatching field
         * @return bool
         */
        private static function lowprofile_result_matches_order($response, $order, $lpc, &$reason = '')
        {
            $actual_op = isset($response['Operation']) ? (string)$response['Operation'] : '';
            $issued = json_decode((string)$order->get_meta('_cardcom_lp_codes'), true);
            if (!is_array($issued)) {
                $issued = array();
            }
            $latest = (string)$order->get_meta('_cardcom_lp_code');
            if (!$issued && $latest !== '') {
                // Pages issued by an earlier 3.6 build: one code with separate meta.
                $issued[$latest] = array('sum' => (string)$order->get_meta('_cardcom_lp_sum'),
                    'cur' => (string)$order->get_meta('_cardcom_lp_currency'), 'op' => (string)$order->get_meta('_cardcom_lp_operation'));
            }

            if (!$issued) {
                // Legacy: page created before this version. Old checks plus the processor amount.
                $total = (float)$order->get_total();
                $expected_agorot = (int)round(($total > 0 ? $total : 0.01) * 100);
                if (isset($response['ExtShvaParams_Sum36']) && $response['ExtShvaParams_Sum36'] !== '') {
                    if ($actual_op !== '3' && (int)$response['ExtShvaParams_Sum36'] !== $expected_agorot) {
                        $reason = 'amount mismatch';
                        return false;
                    }
                } elseif ($actual_op !== '3' && $actual_op !== '4') {
                    $reason = 'amount missing';
                    return false;
                }
                return true;
            }

            // 1. The code must be one this plugin issued for this order.
            $entry = null;
            foreach ($issued as $code => $e) {
                if (hash_equals((string)$code, (string)$lpc)) {
                    $entry = $e;
                    break;
                }
            }
            if ($entry === null) {
                $reason = 'payment page mismatch';
                return false;
            }
            // 2. Same operation we asked for (blocks token-only / validate-only pages for a charge).
            if (isset($entry['op']) && $entry['op'] !== '' && $actual_op !== (string)$entry['op']) {
                $reason = 'operation mismatch';
                return false;
            }
            // 3. The page's amount and currency must still be what the order bills now (cart may have changed).
            $op = (isset($entry['op']) && $entry['op'] !== '') ? (string)$entry['op'] : $actual_op;
            if ($op !== '3') {
                $now = self::initInvoice($order->get_id());
                $now_sum = isset($now['SumToBill']) ? number_format((float)$now['SumToBill'], 2, '.', '') : '';
                if (number_format((float)$entry['sum'], 2, '.', '') !== $now_sum) {
                    $reason = 'amount mismatch';
                    return false;
                }
            }
            if (isset($entry['cur']) && strtoupper((string)$entry['cur']) !== strtoupper((string)$order->get_currency())) {
                $reason = 'currency mismatch';
                return false;
            }
            if (isset($response['CoinId']) && $response['CoinId'] !== '') {
                $expected_coin = self::GetCurrency_from_iso((string)$entry['cur']);
                if ($expected_coin !== null && (string)$expected_coin !== (string)$response['CoinId']) {
                    $reason = 'currency mismatch';
                    return false;
                }
            }
            // 4. Processor amount (agorot): informational until verified live for every payment type.
            if ($op !== '3' && isset($response['ExtShvaParams_Sum36']) && $response['ExtShvaParams_Sum36'] !== ''
                && (int)$response['ExtShvaParams_Sum36'] !== (int)round(((float)$entry['sum']) * 100)) {
                $order->add_order_note(sprintf(__('Cardcom reported a processor amount of %s (agorot) for a payment page of %s. Please verify the deal in Cardcom.', 'cardcom'),
                    (int)$response['ExtShvaParams_Sum36'], $entry['sum']));
            }
            return true;
        }

        /**
         * CardCom numeric coin id for an ISO currency code, or null when the plugin has no mapping.
         *
         * @param string $iso
         * @return int|null
         */
        private static function GetCurrency_from_iso($iso)
        {
            $map = array('ILS' => 1, 'NIS' => 1, 'USD' => 2, 'AUD' => 36, 'CAD' => 124, 'DKK' => 208, 'JPY' => 392,
                'CHF' => 756, 'GBP' => 826, 'EUR' => 978, 'RUB' => 643, 'SEK' => 752, 'NOK' => 578);
            $iso = strtoupper((string)$iso);
            return isset($map[$iso]) ? $map[$iso] : null;
        }

        /**
         * The LowProfile Operation for an order, decided from the gateway settings only.
         *
         * @param WC_Order|null $order
         * @return string
         */
        private function resolve_lowprofile_operation($order)
        {
            if ($order && $order->get_meta('_cardcom_changing_payment_method') === 'yes') {
                return '3';
            }
            switch ((string)$this->operation) {
                case '2':
                    return '2';
                case '3':
                case '6':
                    return '3';
                case '4':
                case '5':
                    return '4';
                default:
                    return '1';
            }
        }

        /**
         * Send the shopper to $url. Inside the Cardcom iframe the top window must navigate, so a small script
         * is printed with the URL JSON-encoded (no quote/script break-out); otherwise a normal HTTP redirect.
         * Always ends the request.
         *
         * @param string $url
         */
        protected function redirect_top($url)
        {
            $url = esc_url_raw((string)$url);
            if ($url === '') {
                $url = home_url('/');
            }
            if ($this->UseIframe == 1) {
                echo '<script>window.top.location.href = ' . wp_json_encode($url) . ';</script>';
            } else {
                // wp_redirect (not wp_safe_redirect): these URLs are built server-side and the thank-you URL
                // may legitimately be filtered to another host by funnel plugins.
                wp_redirect($url);
            }
            $this->end_request();
        }

        /** Ends the request; separate so tests can intercept it. */
        protected function end_request()
        {
            exit();
        }

        function generate_cardcom_form($order_id)
        {
            $this->last_lowprofile_error = '';
            $URL = $this->GetRedirectURL($order_id);
            if ($this->last_lowprofile_error !== '') {
                return '<p class="woocommerce-error">' . esc_html($this->last_lowprofile_error) . '</p>';
            }
            $formstring = '<iframe allow="payment" width="100%" height="1000" frameborder="0" src="' . esc_url($URL) . '" ></iframe>';
            return $formstring;
        }

        function receipt_page($order)
        {
            // Never take the operation from the request: a shopper could turn a charge into a
            // token-only (no charge) page and still get the order marked paid.
            $order_obj = wc_get_order($order);
            $this->operationToPerform = $order_obj ? $this->resolve_lowprofile_operation($order_obj) : $this->resolve_lowprofile_operation(null);
            echo $this->generate_cardcom_form($order);
        }

        /**
         * Authenticate an incoming Cardcom listener request against the order it targets.
         *
         * The listener endpoint (woocommerce_api_wc_gateway_cardcom) is unauthenticated by
         * design, so the request must prove it relates to the order it claims to act on.
         * Every callback URL we hand to Cardcom carries the order's key (a per-order secret
         * issued by WooCommerce); we require that key here. Without it, an unauthenticated
         * caller could drive state transitions on arbitrary orders (CVE-2025-57976,
         * Broken Access Control / CWE-862). A missing key is rejected exactly like a wrong one.
         *
         * @return bool True when the request carries the correct order key for its order_id.
         */
        function cardcom_is_valid_listener_request()
        {
            $order_id  = isset($_REQUEST['order_id']) ? absint($_REQUEST['order_id']) : 0;
            $order_key = isset($_REQUEST['key']) ? sanitize_text_field( wp_unslash( $_REQUEST['key'] ) ) : '';

            if ( $order_id <= 0 ) {
                return false;
            }

            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                return false;
            }

            if ( $order_key === '' ) {
                return false;
            }

            // hash_equals guards against timing side-channels on the key comparison.
            return hash_equals( (string) $order->get_order_key(), $order_key );
        }

        function check_ipn_response()
        {
            $listener = isset($_GET['cardcomListener']) ? sanitize_text_field( wp_unslash( $_GET['cardcomListener'] ) ) : '';

            // Authorize every callback before doing any work (CVE-2025-57976): each must prove
            // ownership of the target order via its per-order key. cardcom_IPN, cardcom_cancel and
            // cardcom_failed can mutate order/subscription state. cardcom_successful redirects to
            // the order-received URL, which itself carries the order key -- left unchecked it would
            // hand that key to anyone who supplies an order_id. The IPN is additionally validated
            // server-to-server via the lowprofilecode in IsLowProfileCodeDealOneOK.
            if ( ! $this->cardcom_is_valid_listener_request() ) {
                self::cardcom_log( 'check_ipn_response', 'Rejected ' . $listener . ' request: missing or invalid order key' );
                if ( $listener === 'cardcom_successful' ) {
                    // Soft-fail: a real shopper whose &key was dropped by a cache/CDN/security plugin
                    // still lands somewhere sensible, without exposing any order data. Empty the cart
                    // as successful_request() would, so they don't pay for the same items twice.
                    // This only touches the caller's own session cart.
                    if ( WC()->cart ) {
                        WC()->cart->empty_cart();
                    }
                    $redirectTo = home_url( '/' );
                    $this->redirect_top( $redirectTo );
                }
                status_header( 403 );
                wp_die( esc_html__( 'Invalid request.', 'cardcom' ), '', array( 'response' => 403 ) );
            }

            // Verbose request dump -- only when Debug Logging is enabled.
            self::cardcom_log_wc( 'cardcom-log', $_REQUEST );

            if (isset($_GET['cardcomListener']) && $_GET['cardcomListener'] == 'cardcom_IPN'):
                @ob_clean();
                $_POST = stripslashes_deep($_REQUEST);
                header('HTTP/1.1 200 OK');
                header('User-Agent: Cardcom');
                do_action("valid-cardcom-ipn-request", $_REQUEST);
            endif;

            if (isset($_GET['cardcomListener']) && $_GET['cardcomListener'] == 'cardcom_successful'):
                @ob_clean();
                $_POST = stripslashes_deep($_REQUEST);
                header('HTTP/1.1 200 OK');
                header('User-Agent: Cardcom');
                do_action("valid-cardcom-successful-request", $_REQUEST);
            endif;

            if (isset($_GET['cardcomListener']) && $_GET['cardcomListener'] == 'cardcom_cancel'):
                @ob_clean();
                $_GET = stripslashes_deep($_REQUEST);
                header('HTTP/1.1 200 OK');
                header('User-Agent: Cardcom');
                do_action("valid-cardcom-cancel-request", $_REQUEST);
            endif;


            if (isset($_GET['cardcomListener']) && $_GET['cardcomListener'] == 'cardcom_failed'):
                @ob_clean();
                $_GET = stripslashes_deep($_REQUEST);
                header('HTTP/1.1 200 OK');
                header('User-Agent: Cardcom');
                do_action("valid-cardcom-failed-request", $_REQUEST);
            endif;

        }

        function cancel_request($get)
        {

            $order_id = intval($get["order_id"]);
            global $woocommerce;

            $order = wc_get_order($order_id);
            if (!$order) {
                $order = new WC_Order($order_id);
            }

            if (!empty($order_id)) {

                // ========== Protect subscription from cancellation during payment method change ========== //
                if ( function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $order_id ) ) {
                    $order = wcs_get_subscription( $order_id );
                self::cardcom_log("cancel_request", "User cancelled during subscription payment method change — redirecting safely to subscription page");

                    // Clean up the change payment method flag
                    $order->delete_meta_data( '_cardcom_changing_payment_method' );
                    $order->add_order_note( __( 'Customer cancelled the payment method update on the Cardcom page.', 'cardcom' ) );
                    $order->save();

                    $subscriptionUrl = $order->get_view_order_url();
                    $this->redirect_top($subscriptionUrl);
                }

                $cancelUrl = $order->get_cancel_order_url_raw(); // raw: get_cancel_order_url() is HTML-escaped (&amp;) and breaks the redirect
                $this->redirect_top($cancelUrl);
            }

        }

        function failed_request($get)
        {
            // ========== Protect subscription from being treated as failed order ========== //
            $order_id = isset($get["order_id"]) ? intval($get["order_id"]) : 0;
            if ( $order_id > 0 && function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $order_id ) ) {
                self::cardcom_log("failed_request", "Failed during subscription payment method change — redirecting safely to subscription page");
                $subscription = wcs_get_subscription($order_id);

                // Clean up the change payment method flag
                $subscription->delete_meta_data( '_cardcom_changing_payment_method' );
                $subscription->add_order_note( __( 'Payment method update failed on the Cardcom page.', 'cardcom' ) );
                $subscription->save();

                $subscriptionUrl = $subscription->get_view_order_url();
                $this->redirect_top($subscriptionUrl);
            }

            if ($this->failedUrl != '') {
                $this->redirect_top($this->failedUrl);
            } else
            $this->cancel_request($get);

        }

        //http://ipnadress/wp?wc-api=WC_Gateway_Cardcom&cardcomListener=cardcom_IPN&order_id=158&terminalnumber=1000&lowprofilecode=d7aa9b2d-e97f-4c13-8f66-2131dd252618&Operation=2&OperationResponse=5116&OperationResponseText=NOTOK
        function ipn_request($posted)
        {
            $log_title = "ipn_request";
            self::cardcom_log($log_title, "Initiated");

            $lowprofilecode = $posted["lowprofilecode"];
            $orderid = htmlentities($posted["order_id"]);
            self::cardcom_log($log_title, "LowProfile Code : " . self::mask_secret($lowprofilecode));
            self::cardcom_log($log_title, "Order Id : " . $orderid);

            // One IPN per order at a time: Cardcom retries and the shopper's redirect can arrive together,
            // and both would otherwise pass the IsIpnRecieved check (duplicate tokens / payment_complete).
            $order_id_int = (int) $orderid;
            if ( ! self::acquire_order_lock( $order_id_int ) ) {
                self::cardcom_log($log_title, "Another IPN is processing order " . $order_id_int . " - skipped");
                return true;
            }
            try {
                // Re-read after acquiring the lock so a just-finished IPN is seen.
                $order       = wc_get_order( $order_id_int );
                $key_1_value = $order ? $order->get_meta( 'IsIpnRecieved' ) : '';
                if (!empty($key_1_value) && $key_1_value == 'true') {
                    return;
                }

                return $this->updateOrder($lowprofilecode, $orderid);
            } finally {
                self::release_order_lock( $order_id_int );
            }

        }

        /**
         * MySQL lock names are server-wide; scope them to this site (DB name + table prefix) so sites sharing a
         * database server do not block each other's IPNs. 48 chars (< 64 limit).
         *
         * @param int $order_id
         * @return string
         */
        public static function order_lock_name($order_id)
        {
            global $wpdb;
            $prefix = (isset($wpdb) && is_object($wpdb) && isset($wpdb->prefix)) ? $wpdb->prefix : '';
            return 'cardcom_' . sha1((defined('DB_NAME') ? DB_NAME : '') . '|' . $prefix . '|' . (int)$order_id);
        }

        /**
         * Non-blocking per-order MySQL named lock.
         *
         * @param int $order_id
         * @return bool true when this request now holds the lock
         */
        private static function acquire_order_lock($order_id)
        {
            global $wpdb;
            if (!isset($wpdb) || !is_object($wpdb)) {
                return true;
            }
            // '0' = another request holds it. NULL = GET_LOCK failed/unsupported: proceed rather than drop the IPN.
            return (string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', self::order_lock_name($order_id))) !== '0';
        }

        /**
         * @param int $order_id
         */
        private static function release_order_lock($order_id)
        {
            global $wpdb;
            if (isset($wpdb) && is_object($wpdb)) {
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::order_lock_name($order_id)));
            }
        }

        function updateOrder($lowprofilecode, $orderid)
        {
            $order = wc_get_order($orderid);
            if (!$order) {
                $order = new WC_Order($orderid);
            }

            // ========== Handle subscription payment method change ========== //
            $is_changing_payment_method = $order->get_meta( '_cardcom_changing_payment_method' );
            if ( $is_changing_payment_method === 'yes'
                 && function_exists( 'wcs_is_subscription' )
                 && wcs_is_subscription( $orderid )
            ) {
                return $this->handle_subscription_token_update( $lowprofilecode, $orderid, $order );
            }

            if ($this->IsLowProfileCodeDealOneOK($lowprofilecode, $this->terminalnumber, $this->username, $order) == '0') {

                if (!empty($orderid)) {

                    // update_post_meta((int)$orderid, 'CardcomInternalDealNumber', $this->InternalDealNumberPro);
                    $order->update_meta_data( 'CardcomInternalDealNumber', $this->InternalDealNumberPro );
                    $order->save();


                    // update_post_meta((int)$orderid, 'initial_document_no', $this->DocumentNumber);
                    $order->update_meta_data( 'initial_document_no', $this->DocumentNumber );
                    $order->save();

                    // update_post_meta((int)$orderid, 'initial_document_type', $this->DocumentType);
                    $order->update_meta_data( 'initial_document_type', $this->DocumentType );
                    $order->save();

                    $isSetToCaptureCharge = $order->get_meta('_set_Capture_Charge');
                    if (isset($isSetToCaptureCharge) && $isSetToCaptureCharge == '1') {
                        $order->delete_meta_data('_set_Capture_Charge');
						$order->add_order_note(__('A capture charge deal is set. Cardcom Deal Number:' . $this->InternalDealNumberPro, 'cardcom'));
						 $order->save();
					}
					else {
                       // $order->payment_complete();
                        if ($this->OrderStatus == 'processing' || $this->OrderStatus == 'completed' || $this->OrderStatus == 'on-hold') {
                            $order->payment_complete();
							$order->add_order_note(__('Payment Successfully Completed! Cardcom Deal Number:' . $this->InternalDealNumberPro, 'cardcom'));
							 $order->save();
                        }
                    }
                    //update_post_meta((int)$orderid, 'IsIpnRecieved', 'true');
                    $order->update_meta_data( 'IsIpnRecieved', 'true' );
                    $order->save();
                    return true;
                }
            } else {

                if (!empty($orderid)) {
                    if ($order->get_status() == "completed" ||
                        $order->get_status() == "on-hold" ||
                        $order->get_status() == "processing") {
                        return true;
                }
                $order->add_order_note(__('Attempt Payment Fail', 'woocommerce'));
                $order->update_status("failed");
                return false;
            }
        }
    }

    /**
     * Handle IPN callback for subscription payment method change.
     * Validates the LowProfile response, extracts the new token, and saves it
     * on the subscription — without calling payment_complete().
     *
     * @param string $lowprofilecode The LowProfile code from Cardcom
     * @param int $orderid The subscription ID
     * @param WC_Order $subscription The subscription object
     * @return bool
     */
    function handle_subscription_token_update( $lowprofilecode, $orderid, $subscription )
    {
        $log_title = "handle_subscription_token_update";
        self::cardcom_log($log_title, "Initiated for subscription: " . $orderid);

            // Re-load as proper WC_Subscription to prevent type corruption on save
            $subscription = wcs_get_subscription( $orderid );
            if ( ! $subscription ) {
                self::cardcom_log($log_title, "ERROR: Could not load subscription object for ID: " . $orderid);
                return false;
            }

        // Validate the LowProfile response with Cardcom
        if ( $this->IsLowProfileCodeDealOneOK( $lowprofilecode, $this->terminalnumber, $this->username, $subscription ) == '0' ) {
            self::cardcom_log($log_title, "LowProfile validation succeeded — token should be saved by IsLowProfileCodeDealOneOK");

            // Clean up the change payment method flag
            $subscription->delete_meta_data( '_cardcom_changing_payment_method' );
            $subscription->update_meta_data( 'IsIpnRecieved', 'true' );
            $subscription->add_order_note( __( 'Payment method updated successfully via Cardcom.', 'cardcom' ) );
            $subscription->save();

            // We exit before WooCommerce Subscriptions' own change-payment handler runs, so set the
            // payment method here. This also takes the subscription off manual renewal (bug 7610).
            if ( class_exists( 'WC_Subscriptions_Change_Payment_Gateway' ) ) {
                WC_Subscriptions_Change_Payment_Gateway::update_payment_method( $subscription, $this->id );
                $subscription->save();
                self::cardcom_log($log_title, "Payment method set to " . $this->id . ", requires manual renewal: " . ( $subscription->is_manual() ? 'yes' : 'no' ));
            }

            self::cardcom_log($log_title, "Subscription payment method updated successfully");
            return true;
        } else {
            self::cardcom_log($log_title, "LowProfile validation failed for subscription: " . $orderid);

            // Clean up the flag but don't change subscription status
            $subscription->delete_meta_data( '_cardcom_changing_payment_method' );
            $subscription->add_order_note( __( 'Payment method update failed — Cardcom validation error.', 'cardcom' ) );
            $subscription->save();

            return false;
        }
    }

    function successful_request($posted)
    {

        $orderid = htmlentities($posted["order_id"]);
        $order = wc_get_order($orderid);
        if (!$order) {
            $order = new WC_Order($orderid);
        }
        if (!empty($orderid)) {

            // ========== Handle subscription payment method change redirect ========== //
            if ( function_exists( 'wcs_is_subscription' ) && wcs_is_subscription( $orderid ) ) {
                $sub = wcs_get_subscription( $orderid );
                $redirectTo = $sub ? $sub->get_view_order_url() : home_url('/my-account/');
                self::cardcom_log("successful_request", "Subscription payment method change — redirecting to subscription page: " . $redirectTo);

                $this->redirect_top($redirectTo);
                return true;
            }

            WC()->cart->empty_cart();
            if ($this->successUrl != '') {
                $redirectTo = $this->successUrl;
            } else {
                $redirectTo = $this->get_return_url($order);

            }

            $this->redirect_top($redirectTo);
            return true;
        }
        wp_redirect("/");
        return false;
    }

    protected $InternalDealNumberPro;
    protected $DealResponePro;
    protected $DocumentNumber;
    protected $DocumentType;

        /**
         * @param $lpc
         * @param $terminal
         * @param $username
         * @param $order WC_Order
         * @return string
         *  Validate low profile code
         */
        function IsLowProfileCodeDealOneOK($lpc, $terminal, $username, $order)
        {
            $log_title = "IsLowProfileCodeDealOneOK";
            self::cardcom_log("$log_title", "Start");
            $order_id = $order->get_id();
            $isSetToCaptureCharge = $order->get_meta('_set_Capture_Charge');
            $isCreateToken = self::get_boolean_like($order->get_meta("save_token_on_user"));
            if ($isCreateToken) {
                self::cardcom_log($log_title, "Note! Token will be saved on user");
                $order->delete_meta_data("save_token_on_user");
            }
            $isSetToCaptureCharge = isset($isSetToCaptureCharge) && $isSetToCaptureCharge === '1' ? true : false;
            $vars = array(
                'TerminalNumber' => $terminal,
                'LowProfileCode' => $lpc,
                'UserName' => $username
            );
            # encode information
            $urlencoded = http_build_query($this->senitize($vars));
            $args = array('body' => $urlencoded,
                'timeout' => '5',
                'redirection' => 0, // never follow redirects with credentials in the body
                'httpversion' => '1.0',
                'blocking' => true,
                'headers' => array(),
                'cookies' => array());
            $response = $this->cardcom_post(self::$CardComURL . '/Interface/BillGoldGetLowProfileIndicator.aspx', $args);
            if (is_wp_error( $response)) {
                $error_message = $response->get_error_message();
                error_log( 'Cardcom API error: ' . $error_message );
            
                // Send an email to the admin if configured
                if ( !empty( $this->adminEmail ) ) {
                    wp_mail(
                        $this->adminEmail,
                        __('CardCom Payment Gateway Error: API Unreachable', 'cardcom'),
                        sprintf(
                            __("An error occurred while trying to connect to the CardCom API endpoint '/Interface/BillGoldGetLowProfileIndicator.aspx'.\n\nError: %s\n\nOrder ID: %s\n\nPlease check the connection and contact CardCom support if the issue persists.", 'cardcom'),
                            $error_message,
                            $order->get_id()
                        )
                    );
                }
            
                // Get user's preferred language for error message
                $user_message = '';
                $user_title = '';
                
                if ($this->lang === 'he') {
                    $user_message = 'אנו מתנצלים, אך קיימת בעיה זמנית בעיבוד התשלום. יש לנסות שוב בעוד כמה דקות או ליצור קשר עם החנות לקבלת סיוע.';
                    $user_title = 'שגיאה בעיבוד תשלום';
                } else {
                    $user_message = __('We\'re sorry, but there\'s a temporary issue processing your payment. Please try again in a few minutes or contact the store for assistance.', 'cardcom');
                    $user_title = __('Payment Processing Error', 'cardcom');
                }
                
                status_header(500);
                wp_die( $user_message, $user_title, array( 'response' => 500 ) );
            }
            $body = wp_remote_retrieve_body($response);
            $responseArray = array();
            $returnvalue = '1';
            parse_str($body, $responseArray);
            self::cardcom_log($log_title, "ResponseCode : " . $responseArray['ResponseCode']);
            self::cardcom_log($log_title, "DealResponse : " . $responseArray['DealResponse']);
            self::cardcom_log($log_title, "Response Description : " . $responseArray['Description']);
            self::cardcom_log_wc( 'cardcom-response', $responseArray );
            $this->InternalDealNumberPro = 0;
            $this->DealResponePro = -1;
            if (isset($responseArray['InternalDealNumber'])) {
                $this->InternalDealNumberPro = $responseArray['InternalDealNumber'];
            }
            if (isset($responseArray['InvoiceType'])) {
                $this->DocumentType = $responseArray['InvoiceType'];
            }
            if (isset($responseArray['InvoiceNumber'])) {
                $this->DocumentNumber = $responseArray['InvoiceNumber'];
            }
            if (isset($responseArray['DealResponse'])) #  OK!
            {
                $this->DealResponePro = $responseArray['DealResponse'];
            } else if (isset($responseArray['SuspendedDealResponseCode'])) #  Suspend Deal
            {
                $this->DealResponePro = $responseArray['SuspendedDealResponseCode'];
            }


            if (isset($responseArray['OperationResponse'])
                && $responseArray['OperationResponse'] == '0'
                && isset($responseArray['ReturnValue'])
                && $responseArray['ReturnValue'] == $order_id) #  Normal Deal
            {
                $mismatch = '';
                if (self::lowprofile_result_matches_order($responseArray, $order, (string)$lpc, $mismatch)) {
                    $returnvalue = '0';
                } else {
                    self::cardcom_log($log_title, "LowProfile result rejected for order " . $order_id . ": " . $mismatch);
                    $order->add_order_note(sprintf(__('Cardcom payment result rejected (%s). The customer may have paid an outdated payment page; please verify the deal in Cardcom before shipping.', 'cardcom'), $mismatch));
                }
            }
            if ($returnvalue == '0') {
                try {
                    $LPD_Operation = $responseArray['Operation'];
                    self::cardcom_log($log_title, "Response Operation is " . $LPD_Operation);
                    if ($LPD_Operation === '2' || $LPD_Operation === '3') {
                        $token = $this->create_cardcom_token($responseArray, $order_id);
                        self::cardcom_log($log_title, "Order's Token " . $token->get_id());
                        if ($order->get_meta('_cardcom_changing_payment_method') === 'yes') {
                            // Card change: renewals read the first Cardcom token on the subscription,
                            // so the old one must go or the new card is never used (bug 7610).
                            $this->detach_cardcom_tokens_from_order($order);
                        }
                        $this->save_token_in_order($token, $order);
                        $this->save_token_in_order_v2($token, $order);
                        if ($isCreateToken) {
                            $user_token = $this->create_cardcom_token($responseArray, $order_id);
                            self::cardcom_log($log_title, "User's Token " . $user_token->get_id());
                            $this->save_token_for_user($user_token, $order);
                        }
                        if ($LPD_Operation == '3') {
                            // add_post_meta($order_id, 'CardcomTokenExDate', $responseArray['TokenExDate']);
                            $order->update_meta_data( 'CardcomTokenExDate', $responseArray['TokenExDate'] );
                            $order->save();
                        }
                    }
                } catch (Exception $ex) {
                    error_log($ex->getMessage());
                }
                // http://kb.cardcom.co.il/article/AA-00241/0

                // add_post_meta($order_id, 'Payment Gateway', 'CardCom');
                $order->update_meta_data( 'Payment Gateway', 'CardCom' );
                $order->save();
                // add_post_meta($order_id, 'initial_document_type', $responseArray['InvoiceType']);
                $order->update_meta_data( 'initial_document_type', $responseArray['InvoiceType'] ?? '' );
                $order->save();
                // add_post_meta($order_id, 'initial_document_no', $responseArray['InvoiceNumber']);
                $order->update_meta_data( 'initial_document_no', $responseArray['InvoiceNumber'] ?? '' );
                $order->save();
                // add_post_meta($order_id, 'cc_number', $responseArray['ExtShvaParams_CardNumber5']);
                $order->update_meta_data( 'cc_number', $responseArray['ExtShvaParams_CardNumber5'] );
                $order->save();
                // add_post_meta($order_id, 'cc_holdername', $responseArray['ExtShvaParams_CardOwnerName']);
                $order->update_meta_data( 'cc_holdername', $responseArray['ExtShvaParams_CardOwnerName'] );
                $order->save();
                // add_post_meta($order_id, 'cc_numofpayments', 1 + $responseArray['ExtShvaParams_NumberOfPayments94']);
                $order->update_meta_data( 'cc_numofpayments', (1 + $responseArray['ExtShvaParams_NumberOfPayments94']) );
                $order->save();

                if (1 + $responseArray['ExtShvaParams_NumberOfPayments94'] == 1) {
                    // add_post_meta($order_id, 'cc_firstpayment', $responseArray['ExtShvaParams_Sum36']);
                    $order->update_meta_data( 'cc_firstpayment', $responseArray['ExtShvaParams_Sum36'] );
                    $order->save();
                    // add_post_meta($order_id, 'cc_paymenttype', '1');
                    $order->update_meta_data( 'cc_paymenttype', '1' );
                    $order->save();
                } else {
                    // add_post_meta($order_id, 'cc_firstpayment', $responseArray['ExtShvaParams_FirstPaymentSum78']);
                    $order->update_meta_data( 'cc_firstpayment', $responseArray['ExtShvaParams_FirstPaymentSum78'] );
                    $order->save();
                    // add_post_meta($order_id, 'cc_paymenttype', '2');
                    $order->update_meta_data( 'cc_paymenttype', '2' );
                    $order->save();
                }
                // add_post_meta($order_id, 'cc_total', $responseArray['ExtShvaParams_Sum36']);
                $order->update_meta_data( 'cc_total', $responseArray['ExtShvaParams_Sum36'] );
                $order->save();
                // add_post_meta($order_id, 'cc_cardtype', $responseArray['ExtShvaParams_Sulac25']);
                $order->update_meta_data( 'cc_cardtype', $responseArray['ExtShvaParams_Sulac25'] );
                $order->save();
                // add_post_meta($order_id, 'cc_Sulac', $responseArray['ExtShvaParams_Sulac25']);
                $order->update_meta_data( 'cc_Sulac', $responseArray['ExtShvaParams_Sulac25'] );
                $order->save();
                // add_post_meta($order_id, 'cc_Mutag', $responseArray['ExtShvaParams_Mutag24']);
                $order->update_meta_data( 'cc_Mutag', $responseArray['ExtShvaParams_Mutag24'] );
                $order->save();
                // add_post_meta($order_id, 'cc_Tokef', $responseArray['ExtShvaParams_Tokef30']);
                $order->update_meta_data( 'cc_Tokef', $responseArray['ExtShvaParams_Tokef30'] );
                $order->save();

                // update_post_meta((int)$order_id, 'Profile', $lpc);
                $order->update_meta_data( 'Profile', $lpc );
                $order->save();

            }
            // Only persist Capture-Charge data for a successful LowProfile result. A failed attempt
            // (e.g. an unsupported card brand, then a retry with another card) fires its own IPN with
            // no Token in the response; writing it here used to store an empty 'cardcom_token_val'
            // that get_meta() kept returning even after the successful retry appended the real one.
            // update_meta_data() replaces the single value instead of appending a duplicate.
            if ($isSetToCaptureCharge && $returnvalue == '0') {
                self::cardcom_log("$log_title", "Saving data to Capture Charge the updated price");
                $order->update_meta_data('cardcom_charge_captured', 'no');
                $order->update_meta_data('cardcom_token_val', isset($responseArray["Token"]) ? $responseArray["Token"] : '');
                // Fixing issue with TokenApproval getting '+' chars for some odd reasons (probably from the SERVER)

                if( isset( $responseArray["TokenApprovalNumber"] ) && !empty( $responseArray["TokenApprovalNumber"] ) ){
                    $ApprovalNumber = preg_replace("/[^0-9]/", "", $responseArray["TokenApprovalNumber"]);
                    $order->update_meta_data('cardcom_Approval_Num', $ApprovalNumber);
                }

                $order->update_meta_data('cardcom_NumOfPayments', $responseArray["NumOfPayments"]);
                $tokenExpireMonth = str_pad((string)$responseArray["CardValidityMonth"], 2, '0', STR_PAD_LEFT);
                $tokenExpireYear = str_pad((string)$responseArray["CardValidityYear"], 4, '0', STR_PAD_LEFT);
                $order->update_meta_data('cardcom_Tokef', $tokenExpireMonth . substr($tokenExpireYear, 2, 2));
                $order->update_meta_data('cardcom_CardOwnerName', $responseArray["CardOwnerName"]);
                $order->update_meta_data('cardcom_CardOwnerEmail', $responseArray["CardOwnerEmail"]);
                $order->update_meta_data('cardcom_CardOwnerID', $responseArray["CardOwnerID"]);
                $order->save_meta_data();
                $order->update_status("on-hold", "On hold for capture charge-2");
                $order->save();
            } elseif ($isSetToCaptureCharge) {
                self::cardcom_log("$log_title", "LowProfile result is not OK - Capture Charge data not saved");
            }
            do_action('cardcom_IsLowProfileCodeDealOneOK', $returnvalue, $responseArray, $order_id);
            return $returnvalue;
        }

        //region Functions for Rendering payment fields In checkout page

        /** Payment form on checkout page */
        function payment_fields()
        {
            $RenderCVV = false;
            // -------------- Load saved payment methods (i.e. Token) -------------- //
            if ($this->supports('tokenization') && is_checkout() && $this->operation_allows_to_pay_via_tokens()) {
                $this->cardcom_checkout_script();
                $this->saved_payment_methods();
                if ($this->allows_to_optionally_save_tokens()) {
                    $this->save_payment_method_checkbox();
                }
                $RenderCVV = true;
            }
            // ------- PCI fields Render Credit Card fields for the user to input ------- //
            self::cardcom_log('shanicc', $RenderCVV);
            if ($this->cerPCI == '1' && ($this->does_operation_compatible_with_PCI_fields())) {
                $this->cardcom_CreditCard_fields();
                $RenderCVV = true;
            }
            // -------------- Render CVV Field -------------- //
            if (self::$must_cvv == '1' && $RenderCVV) {
                $this->cardcom_token_validation_form();
            }
            // -------------- Render Description if set -------------- //
            if ($this->description) : ?><p><?php echo wp_kses_post($this->description); ?></p> <?php endif; ?>
            <?php
        }

        /** Render Javascript scripts */
        function cardcom_checkout_script()
        {
            wp_enqueue_script('cardcom_chackout_script',
                plugins_url('/woo-cardcom-payment-gateway/frontend/cardcom.js'),
                array('jquery'),
                WC()->version);
        }

        /** Render CVV Field in checkout page */
        function cardcom_token_validation_form()
        {
            $alwaysDisplayCVV = $this->cerPCI == '1' ? true : false;
            printf('<p class="form-row ' . ($alwaysDisplayCVV ? "" : "payment_method_cardcom_validation") . '">
                <label for="%1$s-card-cvc">' . esc_html__('Security Digits (CVV)', 'cardcom') . ' <span class="required">*</span></label>
                <input id="%1$s-card-cvc" name="%1$s-card-cvc"  class="input-text " 
                inputmode="numeric" autocomplete="off" autocorrect="no" autocapitalize="no" spellcheck="no" 
                type="tel" maxlength="4" placeholder="' . esc_attr__('CVC', 'woocommerce') . '" style="width:150px" />
                </p>',
                esc_attr($this->id));
        }

        /** Render Credit-Card Fields in checkout page */
        function cardcom_CreditCard_fields()
        {
            // ==================== Render CC Number field ==================== //
            printf('
                <p class="form-row wc-payment-form">
                <label for="cardcom-card-number">' . esc_html__("Credit Card Number", "cardcom") . '<span class="required">*</span></label>
                <input id="cardcom-card-number" name="cardcom-card-number" type="text" 
                class="input-text" maxlength="20" autocomplete="off" style="width:200px"/>
                </p>
                ', esc_attr($this->id));

            // ==================== Expire Date + Citizen Id ==================== //
                ?>
                <label for="cardcom-expire-date"><?php _e("Expiration date", "cardcom") ?><span
                    class="required">*</span></label><br>
                    <div style="display: flex; flex-direction: row" class="wc-payment-form">
                        <!-- ==================== Render Expire Date ==================== -->
                        <div style="margin: 0 0.11em 0 0.11em;">
                            <select name="cardcom-expire-month" id="cardcom-expire-month"
                            class="woocommerce-select woocommerce-cc-month input-text">
                            <option value=""><?php _e('Month', 'cardcom') ?></option>
                            <?php for ($i = 1; $i <= 12; $i++) {
                                printf('<option value="%u">%s</option>', $i, $i);
                            } ?>
                        </select>
                    </div>
                    <div style="margin: 0 0.11em 0 0.11em;">
                        <select name="cardcom-expire-year" id="cardcom-expire-year"
                        class="woocommerce-select woocommerce-cc-year input-text">
                        <option value=""><?php _e('Year', 'cardcom') ?></option>
                        <?php for ($i = date('y'); $i <= date('y') + 15; $i++) {
                            printf('<option value="20%u">%u</option>', $i, $i);
                        } ?>
                    </select>
                </div>
            </div>

            <!-- ==================== Id Field (Only render if site is in Hebrew) ==================== -->
            <?php if (get_locale() === 'he_IL'): ?>
                <div style="display: block; margin-right: 0.5em; margin-left: 0.5em;">
                    <label for="cardcom-citizen-id">תעודת זהות<span class="required">*</span></label><br>
                    <input id="cardcom-citizen-id" name="cardcom-citizen-id" type="text" class="input-text"
                    maxlength="10" autocomplete="off" style="width:150px"/>
                </div>
            <?php endif; ?>

            <!----------------------  Render num of payments if needed ---------------------->
            <?php
            // ============== Don't render if max payment is equal or below 1 ============== //
            if ($this->maxpayment <= 1 || self::get_order_total() <= 0) return;
            // Else .....
            // ==================== Render Number of Payments (If max payment is above 1) ==================== //
            ?>
            <p class="form-row wc-payment-form">
                <label for="cardcom-num-payments"><?php _e("Number of Payments", "cardcom") ?><span
                    class="required">*</span></label>
                    <select name="cardcom-num-payments" id="cardcom-num-payments"
                    class="woocommerce-select input-text" style="width: 60px;">
                    <?php for ($i = 1; $i <= $this->maxpayment; $i++) {
                        printf('<option value="%u">%s</option>', $i, $i);
                    } ?>
                </select>
            </p>
            <?php
        }
        //endregion

        /** TOKENIZATION */
        function charge_token($paymentTokenValue, $order_id, $cvv = '')
        {
            // =============================================================== //
            // ======================= Prepare Request ======================= //
            // =============================================================== //
            $log_title = "charge_token";
            self::cardcom_log($log_title, "Initialed");
            $order = new WC_Order($order_id);
            $numOfPayments = $this->get_post("cardcom-num-payments");
            $save_token_for_user_bool = $order->get_meta("save_token_on_user");
            $save_token_for_user_bool = $save_token_for_user_bool !== null && $save_token_for_user_bool === 'true';
            if ($save_token_for_user_bool) {
                $order->delete_meta_data("save_token_on_user");
            }
            $params = array();
            
            if ($this->invoice == '1') {
                self::cardcom_log($log_title, "initInvoice method used");
                $params = self::initInvoice($order_id, self::$cvv_free_trm);
            } else {
                self::cardcom_log($log_title, "initTerminal method used");
                $params = self::initTerminal($order_id, self::$cvv_free_trm);
            }
            
            $params['TokenToCharge.APILevel'] = '10';
            $coin = self::GetCurrency($order, self::$CoinID);
            $cvv = $this->get_post("cardcom-card-cvc");
            if ($cvv == null) {
                $cvv = '';
            }
            // ============= If PCI certification is checked AND new Payment is selected ============= //
            if ($this->cerPCI == '1') {
                $params['TokenToCharge.CardNumber'] = $this->get_post("cardcom-card-number");
                $params['TokenToCharge.CardValidityMonth'] = $this->get_post("cardcom-expire-month");
                $params['TokenToCharge.CardValidityYear'] = $this->get_post("cardcom-expire-year");
                $params['TokenToCharge.IdentityNumber'] = $this->get_post("cardcom-citizen-id");
                if ($save_token_for_user_bool) {
                    $params['TokenToCharge.IsCreateToken'] = "true";
                }
                // Save token only
                if ($this->operation === '3') {
                    $params['TokenToCharge.JParameter'] = "2";
                    $params['CustomeFields.Field24'] = "Save Token Only";
                }
                
            }
            // ============= If user selected saved payment (i.e. Token)  ============= //
            if ($paymentTokenValue !== null && $paymentTokenValue !== 'new') {
                // Only the logged-in owner may charge a saved Cardcom token. Order-level tokens are stored
                // without a user (user_id 0), so a bare "owner == current user" check let guests charge
                // any stored card by its (sequential) token id.
                $token = $this->get_owned_cardcom_token($paymentTokenValue);
                if ($token === null) {
                    self::cardcom_log($log_title, "Rejected saved token: not owned by the current user");
                    wc_add_notice(__('The selected payment method is not available.', 'cardcom'), 'error');
                    return false;
                }
                $params['TokenToCharge.Token'] = $token->get_token();
                $params['TokenToCharge.CardValidityMonth'] = $token->get_expiry_month();
                $params['TokenToCharge.CardValidityYear'] = $token->get_expiry_year();
            }
            // ============= If user selects Capture Charge (change this to operation) ============= //
            if ($this->operation == '6') {
                self::cardcom_log($log_title, "Setting J5 for operation Capture Charge");
                $params['TokenToCharge.JParameter'] = "5";
                $params['CustomeFields.Field24'] = "Capture Charge Deal";
            }
            // ============= Set on check deal Only J2, if it's operation 3 ============= //
            if ($this->operation == '3') {
                self::cardcom_log($log_title, "Setting J2 for operation Save Token (only)");
                $params['TokenToCharge.JParameter'] = "5";
                $params['CustomeFields.Field24'] = "Save Token Only";
            }
            // ============= Input the common fields ============= //
            $params['TokenToCharge.Salt'] = ''; #User ID or a Cost var.
            $params['TokenToCharge.SumToBill'] = number_format($order->get_total(), 2, '.', '');
            $coin = self::GetCurrency($order, self::$CoinID);
            $params["TokenToCharge.CardOwnerName"] = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
            $params["TokenToCharge.CardOwnerEmail"] = $order->get_billing_email();
            $params["TokenToCharge.CardOwnerPhone"] = $order->get_billing_phone();
            $params["TokenToCharge.CoinISOName"] = $order->get_currency();
            $UniqAsmachta = $order_id . $this->GetCurrentURL();
            if (strlen($UniqAsmachta) > 50) {
                $UniqAsmachta = substr($UniqAsmachta, 0, 50);
            }
            self::cardcom_log($log_title, "Unique Asmachta : " . $UniqAsmachta);
            $params['TokenToCharge.UniqAsmachta'] = $UniqAsmachta;
            $params['TokenToCharge.UniqAsmachtaReturnOriginal'] = "true";
            $params['TokenToCharge.CVV2'] = $cvv;
            $params['TokenToCharge.NumOfPayments'] = $numOfPayments === null ? '1' : $numOfPayments;
            $params['CustomeFields.Field25'] = "order_id : " . $order_id . ' ' . "Token Charge";
            $urlencoded = http_build_query($this->senitize($params));
            $urlencoded = $this->append_api_password($urlencoded);
            $args = array('body' => $urlencoded,
                'timeout' => '10',
                'redirection' => 0, // never follow redirects with credentials in the body
                'httpversion' => '1.0',
                'blocking' => true,
                'headers' => array(),
                'cookies' => array());
            // =============================================================== //
            // =================== Get Response and handle =================== //
            // =============================================================== //
            $response = $this->cardcom_post(self::$CardComURL . '/interface/ChargeToken.aspx', $args);
            
            $body = wp_remote_retrieve_body($response);
            $responseArray = array();
            parse_str($body, $responseArray);
            self::cardcom_log_wc( 'cardcom-log-2024', $responseArray );
            $this->InternalDealNumberPro = 0;
            
            if( isset( $responseArray['InvoiceResponse_InvoiceNumber'] ) ){
                $order->update_meta_data('initial_document_no', $responseArray['InvoiceResponse_InvoiceNumber'] );
                $order->add_order_note(__("document_no :", 'cardcom') . $responseArray['InvoiceResponse_InvoiceNumber']);
            }

                            
            if( isset( $responseArray['InvoiceResponse_InvoiceType'] ) ){
                    $order->update_meta_data('initial_document_type', $responseArray['InvoiceResponse_InvoiceType'] );
                    $order->add_order_note(__("document_type :", 'cardcom') . $responseArray['InvoiceResponse_InvoiceType']);
            }

            $respCode = isset($responseArray['ResponseCode']) ? $responseArray['ResponseCode'] : null;
            $respDesc = isset($responseArray['Description']) ? $responseArray['Description'] : null;
            // ================================== //
            // ==== Direct charge SUCCEEDED ===== //
            // ================================== //
            if (self::is_token_charge_paid($responseArray)) {
                self::cardcom_log($log_title, "SUCCEEDED with response " . $respCode);
                if (self::is_duplicate_deal_response($responseArray)) {
                    self::note_duplicate_deal($order, $log_title);
                }
                // Todo: I have no idea what's the point of saving on the class the Internal-Deal Number
                if (isset($responseArray['InternalDealNumber'])) {
                    $this->InternalDealNumberPro = $responseArray['InternalDealNumber'];
                } else {
                    $this->InternalDealNumberPro = "9";
                }
                // add_post_meta($order_id, 'Payment Gateway', 'CardCom');
                $order->update_meta_data( 'Payment Gateway', 'CardCom' );
                $order->save();
                // update_post_meta((int)$order_id, 'CardcomInternalDealNumber', $this->InternalDealNumberPro);
                
                $order->update_meta_data( 'CardcomInternalDealNumber', $this->InternalDealNumberPro );
                $order->save();

                $ccNumber = "";
                if (isset($responseArray['CardNumStart'])) $ccNumber .= $responseArray['CardNumStart'];
                $ccNumber .= "*****";
                if (isset($responseArray['CardNumEnd'])) $ccNumber .= $responseArray['CardNumEnd'];
                // add_post_meta($order_id, 'cc_number', $ccNumber);
                $order->update_meta_data( 'cc_number', $ccNumber );
                $order->save();
                // add_post_meta($order_id, 'cc_holdername', $params["TokenToCharge.CardOwnerName"]);
                $order->update_meta_data( 'cc_holdername', $params["TokenToCharge.CardOwnerName"] );
                $order->save();
                // add_post_meta($order_id, 'cc_numofpayments', $params['TokenToCharge.NumOfPayments']);
                $order->update_meta_data( 'cc_numofpayments', $params['TokenToCharge.NumOfPayments'] );
                $order->save();
                // add_post_meta($order_id, 'cc_total', $params['TokenToCharge.SumToBill']);
                $order->update_meta_data( 'cc_total', $params['TokenToCharge.SumToBill'] );
                $order->save();
                
                if (isset($responseArray['Sulac25'])) {
                    // add_post_meta($order_id, 'cc_cardtype', $responseArray['Sulac25']);
                    $order->update_meta_data( 'cc_cardtype', $responseArray['Sulac25'] );
                    $order->save();
                }
                
                if (isset($responseArray['Sulac_25'])) {
                    // add_post_meta($order_id, 'cc_Sulac', $responseArray['Sulac_25']);
                    $order->update_meta_data( 'cc_Sulac',$responseArray['Sulac_25'] );
                    $order->save();
                }
                
                if (isset($responseArray['Mutag_24'])){ 
                    // add_post_meta($order_id, 'cc_Mutag', $responseArray['Mutag_24']);
                    $order->update_meta_data( 'cc_Mutag', $responseArray['Mutag_24']  );
                    $order->save();
                }
                
                if (isset($responseArray['Tokef_30'])){ 
                    // add_post_meta($order_id, 'cc_Tokef', $responseArray['Tokef_30']);
                    $order->update_meta_data( 'cc_Tokef', $responseArray['Tokef_30'] );
                    $order->save();
                }
                // ==================== Save old Token on order ==================== //
                if ($paymentTokenValue !== null && $paymentTokenValue !== "new") {
                    self::cardcom_log($log_title, "Save old Token on order");
                    $clone_token = $this->duplicate_cardcom_token($token);
                    $this->save_token_in_order($clone_token, $order);
                    $this->save_token_in_order_v2($clone_token, $order);
                } else if (isset($responseArray["Token"])) {
                    self::cardcom_log($log_title, "Save new token on order");
                    // ==================== Save new Token on order ==================== //
                    $responseArray["ReturnValue"] = $order_id;
                    $responseArray['ExtShvaParams_Tokef30'] = $responseArray["Tokef_30"];
                    $responseArray['ExtShvaParams_Mutag24'] = $responseArray["Mutag_24"];
                    $responseArray['ExtShvaParams_CardNumber5'] = $responseArray["CardNumEnd"];
                    $responseArray['Token'] = $responseArray["Token"];
                    $token = $this->create_cardcom_token($responseArray, $order_id);
                    self::cardcom_log($log_title, "Order's Token " . $token->get_id());
                    $this->save_token_in_order($token, $order);
                    $this->save_token_in_order_v2($token, $order);
                    if ($save_token_for_user_bool) {
                        $user_token = $this->create_cardcom_token($responseArray, $order_id);
                        self::cardcom_log($log_title, "User's Token " . $user_token->get_id());
                        $this->save_token_for_user($user_token, $order);
                    }
                } else {
                    self::cardcom_log($log_title, "No token was found for order " . $order_id);
                }
                // ================ If Capture-Charge Deal, set more meta data on order ================ //
                if ($this->operation == '6') {
                    // update_meta_data() so a retried checkout replaces the capture data instead of
                    // appending duplicates (get_meta() returns the first stored value).
                    $order->update_meta_data('cardcom_charge_captured', 'no');
                    $order->update_meta_data('cardcom_token_val', isset($responseArray["Token"]) ? $responseArray["Token"] : '');
                    // Fixing issue with TokenApproval getting '+' chars for some odd reasons (probably from the SERVER)
                    if( isset( $responseArray["ApprovalNumber"] ) && !empty( $responseArray["ApprovalNumber"] ) ){
                        $ApprovalNumber = preg_replace("/[^0-9]/", "", $responseArray["ApprovalNumber"]);
                        $order->update_meta_data('cardcom_Approval_Num', $ApprovalNumber);
                    }
                    $order->update_meta_data('cardcom_NumOfPayments', $numOfPayments === null ? '1' : $numOfPayments);
                    $order->update_meta_data('cardcom_Tokef', $responseArray["Tokef_30"]);
                    $FullName = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
                    $order->update_meta_data('cardcom_CardOwnerName', $FullName);
                    $order->update_meta_data('cardcom_CardOwnerEmail', $order->get_billing_email());
                    $CardOwnerID = $this->get_post("cardcom-citizen-id");
                    if (isset($CardOwnerID)) {
                        $order->update_meta_data('cardcom_CardOwnerID', $CardOwnerID);
                    }
                    $order->save_meta_data();
                    $order->update_status("on-hold", __('Capture Charge - Deal captured to charge later', 'cardcom'));
                } else {
                    $order->add_order_note(__('Charge via Token (saved payment method)', 'cardcom'));
                    $order->add_order_note(__('Charged Successfully! Cardcom Deal Number:' . $this->InternalDealNumberPro, 'cardcom'));
                }
                return true;
            }
            // ================================== //
            // ====== Direct charge FAILED ====== //
            // ================================== //
            else {
                $descErrorPrefix = __("Failed billing attempt : ", 'cardcom');
                self::cardcom_log("charge_token Response",
                    isset($respCode) ? "'ResponseCode' was not 0 or 608. It was " . $respCode : "'ResponseCode' Not found");
                if (isset($respDesc)) {
                    wc_add_notice($respDesc, 'error');
                    $order->add_order_note($descErrorPrefix . ' ' . $respDesc);
                } else {
                    wc_add_notice($respCode . ': ' . __("An Unexpected Error Occurred, please try again later", 'cardcom'), 'error');
                    $order->add_order_note($descErrorPrefix . ' ' . __("Error Code") . ' ' . $respCode);
                }
                return false;
            }
        }

        /**
         * Appends TokenToCharge.UserPassword to an already-encoded ChargeToken body when the merchant opted in.
         * Added after senitize() so the password is sent exactly as configured (senitize strips tags / truncates).
         *
         * @param string $urlencoded
         * @return string
         */
        private function append_api_password($urlencoded)
        {
            if (self::$send_api_password === '1' && self::IsStringSet(self::$api_password)) {
                $urlencoded .= '&' . http_build_query(array('TokenToCharge.UserPassword' => self::$api_password));
            }
            return $urlencoded;
        }

        /**
         * Returns the saved Cardcom token only when it belongs to the logged-in user.
         *
         * @param mixed $token_id Token id as posted by the checkout.
         * @return WC_Payment_Token|null
         */
        private function get_owned_cardcom_token($token_id)
        {
            $token_id = absint($token_id);
            if ($token_id <= 0) {
                return null;
            }
            $token = WC_Payment_Tokens::get($token_id);
            if (!$token || $token->get_gateway_id() !== $this->id) {
                return null;
            }
            $owner = (int)$token->get_user_id();
            if ($owner <= 0 || $owner !== (int)get_current_user_id()) {
                return null;
            }
            return $token;
        }

        function GetCurrentURL()
        {
            // The site's own host, not the (client-controlled) Host header: this feeds UniqAsmachta,
            // Cardcom's duplicate-charge guard.
            $host = wp_parse_url(home_url(), PHP_URL_HOST);
            return $host ? (string)$host : '';
        }

        function GetClientIP()
        {
            // Proxy headers are client-controlled; trust them only when the merchant says a proxy/CDN sets them.
            if (!apply_filters('cardcom_trust_proxy_headers', false)) {
                return isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
            }
            if (class_exists('WC_Geolocation')) {
                return sanitize_text_field(WC_Geolocation::get_ip_address());
            }
            if (!empty($_SERVER['HTTP_CLIENT_IP']))   //check ip from share internet
            {
                $ip = $_SERVER['HTTP_CLIENT_IP'];
            } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR']))   //to check ip is pass from proxy
            {
                $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
            } else {
                $ip = $_SERVER['REMOTE_ADDR'];
            }
            return $ip;
        }

        public static function IsStringSet($str)
        {
            if (!isset($str)) return false;
            $trimString = trim($str);
            if (trim($trimString) === '') return false;
            return true;
        }

        /**
         * @param $name string
         * @return string|null
         */
        private function get_post($name)
        {
            if (isset($_POST[$name]))
                return wc_clean(wp_unslash($_POST[$name]));
            return null;
        }

        /**
         * @param $params array
         * @return array
         */
        function senitize($params)
        {
            foreach ($params as &$p) {
                $p = substr(strip_tags(preg_replace("/&#x\d*;/", " ", $p)), 0, 200);
            }
            return $params;
        }

        //region Token Related Functions

        /** Creates token (if Cardcom response contains RAW token values to create with)
         * @param $responseArray
         * Data Required: "ExtShvaParams_Tokef30", "ExtShvaParams_Mutag24", "Token", "ExtShvaParams_CardNumber5"
         * @return WC_Payment_Token_CC|null
         */
        function create_cardcom_token($responseArray, $order_id = null)
        {
            $log_title = "create_cardcom_token";
            self::cardcom_log($log_title, "Initiated");
            $exDate = str_split($responseArray['ExtShvaParams_Tokef30'], 2);
            if (!empty($exDate)) {
                $ExYear = 2000 + (int)$exDate[1];
                $ExMonth = $exDate[0];
                if (!empty($ExYear) && !empty($ExMonth)) {
                    $brandId = $responseArray['ExtShvaParams_Mutag24'];
                    switch ($brandId) {
                        case 0:
                        $brand = 'other';
                        break;
                        case 1:
                        $brand = 'mastercard';
                        break;
                        case 2:
                        $brand = 'visa';
                        break;
                        default:
                        $brand = $brandId;
                        break;
                    }
                    $token = new WC_Payment_Token_CC();
                    $token->set_gateway_id($this->id);
                    $token->set_token($responseArray['Token']);
                    // Note: User ID assignment is handled separately via save_token_for_user() when needed
                    
                    $token->set_last4($responseArray['ExtShvaParams_CardNumber5']);
                    $token->set_expiry_year($ExYear);
                    $token->set_expiry_month($ExMonth);
                    $token->set_card_type($brand);
                    $token->save();
                    self::cardcom_log($log_title, "Saved token successfully");
                    return $token;
                } else {
                    self::cardcom_log($log_title, "Could not parse successfully");
                }
            } else {
                self::cardcom_log($log_title, "ExtShvaParams_Tokef30 is empty");
            }
            self::cardcom_log($log_title, "Did not save successfully");
            return null;
        }

        /**
         * @param $token WC_Payment_Token
         * @return WC_Payment_Token_CC|null
         */
        function duplicate_cardcom_token($token)
        {
            $log_title = "duplicate_cardcom_token";
            self::cardcom_log($log_title, "Initialed");
            self::cardcom_log($log_title, "Old Token Id : " . $token->get_id());
            $token_CC = new WC_Payment_Token_CC($token->get_id());
            self::cardcom_log($log_title, "Old Token_CC Id : " . $token_CC->get_id());
            $clone_token = new WC_Payment_Token_CC();
            $clone_token->set_gateway_id($this->id);
            $clone_token->set_expiry_month($token_CC->get_expiry_month());
            $clone_token->set_expiry_year($token_CC->get_expiry_year());
            $clone_token->set_last4($token_CC->get_last4());
            $clone_token->set_token($token_CC->get_token());
            $clone_token->set_card_type($token_CC->get_card_type());
            $clone_token->save();
            self::cardcom_log($log_title, "Clone Token Id :" . $clone_token->get_id());
            return $clone_token;
        }

        /** Old function
         * Saves the new Token as a payment method for the Cardholder to use
         * Also, saved the new token on the order the token was created on
         * @param $responseArray
         */
        function process_token($responseArray)
        {
            self::cardcom_log("Process Token", "this method was called");
            $order = new WC_Order($responseArray['ReturnValue']);
            $saveTokenAsPaymentMethod = $order->get_meta('save_Payment_Method');
            $order->delete_meta_data('save_Payment_Method');
            $user_id = $order->get_user_id();
            $exDate = str_split($responseArray['ExtShvaParams_Tokef30'], 2);
            if (!empty($exDate)) {
                $ExYaer = 2000 + (int)$exDate[1];
                $ExMonth = $exDate[0];
                if (!empty($ExYaer) && !empty($ExMonth)) {
                    self::cardcom_log("process_token", "Saving token to user and order");
                    $brandId = $responseArray['ExtShvaParams_Mutag24'];
                    switch ($brandId) {
                        case 0:
                        $brand = 'other';
                        break;
                        case 1:
                        $brand = 'mastercard';
                        break;
                        case 2:
                        $brand = 'visa';
                        break;
                        default:
                        $brand = $brandId;
                        break;
                    }
                    $token = new WC_Payment_Token_CC();
                    $token->set_gateway_id($this->id);
                    $token->set_token($responseArray['Token']);
                    $token->set_last4($responseArray['ExtShvaParams_CardNumber5']);
                    $token->set_expiry_year($ExYaer);
                    $token->set_expiry_month($ExMonth);
                    $token->set_card_type($brand);
                    $token->set_user_id($user_id);
                    $token->save();
                    $this->save_token_in_order($token, $order);
                }
            }
        }

        /** Saving token using via adding meta data
         * @param $token WC_Payment_Token_CC
         * @param $order WC_Order
         */
        public function save_token_in_order($token, $order)
        {
            $log_title = "save_token_in_order";
            self::cardcom_log($log_title, "Initiated");
            self::cardcom_log($log_title, "Token Id " . $token->get_id());
            self::cardcom_log($log_title, "Order Id " . $order->get_id());
            $order_id = $order->get_id();
            if ($token->get_id() > 0) {
                // add_post_meta($order_id, 'CardcomToken', $token->get_token(), true);
                $order->update_meta_data( 'CardcomToken', $token->get_token() );
                $order->save();
                // add_post_meta($order_id, 'CardcomTokenId', $token->get_id(), true);
                $order->update_meta_data( 'CardcomTokenId', $token->get_id() );
                $order->save();
                // add_post_meta($order_id, 'CardcomToken_expiry_year', $token->get_expiry_year(), true);
                $order->update_meta_data( 'CardcomToken_expiry_year', $token->get_expiry_year() );
                $order->save();
                // add_post_meta($order_id, 'CardcomToken_expiry_month', $token->get_expiry_month(), true);
                $order->update_meta_data( 'CardcomToken_expiry_month', $token->get_expiry_month() );
                $order->save();
                self::cardcom_log($log_title, "Saved token in Order : " . $order_id);
            } else {
                self::cardcom_log($log_title, "Could not save toke in Order : " . $order_id . " With Token Id " . $token->get_id());
            }
        }

        /**
         * Saves token using a built in method on the order that allows to store Token without the making the order dirty with meta data
         * @param $token WC_Payment_Token_CC
         * @param $order WC_Order
         * @param $override bool override old cardcom token
         */
        public function save_token_in_order_v2($token, $order, $override = false)
        {
            $log_title = "save_token_in_order_v2";
            self::cardcom_log($log_title, "Initiated");
            self::cardcom_log($log_title, "Token Id " . $token->get_id());
            self::cardcom_log($log_title, "Order Id " . $order->get_id());
            $order_id = $order->get_id();
            // 1st, Check that Token is valid by checking the Id
            if ($token->get_id() > 0) {
                // 2nd, Check that token wasn't already inserted inside the same order, to avoid duplicate tokens in order
                $possible_old_token_in_order = $this->get_cardcom_token_from_order($order);
                if ($possible_old_token_in_order === null) {
                    $order->add_payment_token($token);
                    $order->save();
                    self::cardcom_log($log_title, "Saved token in order successfully");
                } else if ($override) {
                    self::cardcom_log($log_title, "overriding old cardcom-token with new");
                    WC_Payment_Tokens::delete($possible_old_token_in_order->get_id());
                    $order->add_payment_token($token);
                    $order->save();
                    self::cardcom_log($log_title, "Saved token in order successfully");
                } else {
                    self::cardcom_log($log_title, "No need to save since Order has a cardcom token already");
                }
            } else {
                self::cardcom_log($log_title, "Could not save toke in Order : " . $order_id . " With Token Id " . $token->get_id());
            }
        }

        /**
         * Removes Cardcom tokens from the order's token list without deleting the tokens,
         * since the parent order and earlier renewals may still reference them.
         * @param $order WC_Order
         */
        public function detach_cardcom_tokens_from_order($order)
        {
            $log_title = "detach_cardcom_tokens_from_order";
            $token_ids = $order->get_payment_tokens();
            $kept = array();
            foreach ($token_ids as $token_id) {
                $token = WC_Payment_Tokens::get($token_id);
                if ($token && $token->get_gateway_id() === $this->id) {
                    self::cardcom_log($log_title, "Detached token " . $token_id . " from order " . $order->get_id());
                    continue;
                }
                $kept[] = $token_id;
            }
            if (count($kept) !== count($token_ids)) {
                $order->get_data_store()->update_payment_token_ids($order, $kept);
            }
        }

        /**
         * @param $order WC_Order
         * @param $token WC_Payment_Token_CC
         */
        function save_token_for_user($token, $order)
        {
            $log_title = "save_token_for_user";
            self::cardcom_log($log_title, "Initiated");
            $user_id = $order->get_user_id();
            $token->set_user_id($user_id);
            $token->save();
            self::cardcom_log($log_title, "Save new payment method for user");
        }

        /**
         * We stand on a princible that only ONE cardcom-token can be saved on an order. So this functions gets that token safely
         * @param $order WC_Order
         * @return WC_Payment_Token | null
         */
        public function get_cardcom_token_from_order($order)
        {
            $log_title = "get_token_from_order";
            self::cardcom_log($log_title, "Initiated");
            $cardcom_token = null;
            // ================ 1st, Get the tokens available in the order object ================ //
            $token_id_arr = $order->get_payment_tokens();
            self::cardcom_log($log_title, "Token Id Arr Count ::: " . sizeof($token_id_arr));
            if (sizeof($token_id_arr) <= 0) {
                self::cardcom_log($log_title, "Order has no tokens");
                return null;
            }
            foreach ($token_id_arr as $token_id) {
                self::cardcom_log($log_title, "(Loop) Token Id ::: " . $token_id);
                $token = WC_Payment_Tokens::get($token_id);
                if (isset($token)) {
                    if ($token->get_gateway_id() === $this->id) {
                        self::cardcom_log($log_title, "Token Found");
                        $cardcom_token = $token;
                        return $cardcom_token;
                    } else {
                        self::cardcom_log($log_title, "Token foreign gateway_id ::: " . $token->get_gateway_id());
                    }
                }
            }
            if (isset($cardcom_token) === false) self::cardcom_log($log_title, "Could not find Cardcom token in order");
            return $cardcom_token;
        }
        //endregion

        //region Subscription Methods Section

        //region Payment and Renewal Actions
        /**
         * hook action: Scheduled subscription payment for the gateway only.
         * Note! The renewal order is a "child" related to "parent" subscription product
         *
         * @param $renewal_total float The amount to charge.
         * @param $renewal_order WC_Order A WC_Order object created to record the renewal payment.
         */
        public function cardcom_scheduled_subscription_payment($renewal_total, $renewal_order)
        {
            // --------------------------------------------- //
            // ------------ Set Local variables ------------ //
            // --------------------------------------------- //
            $log_title = "scheduled_subscription_payment_gateway_specific";
            $payment_date_time = date("Y-m-d l H:i");
            $msg_prefix = __("Cardcom", "cardcom") . ": ";
            $renewal_order_id = $renewal_order->get_id();
            WC_Gateway_Cardcom::cardcom_log($log_title, "Local variables SET");
            try {
                self::cardcom_log($log_title, "renewal total : " . $renewal_total);
                self::cardcom_log($log_title, "renewal order Id property: " . $renewal_order_id);
                $customer_id = $renewal_order->get_user_id();
                $compName = self::get_clean_string($renewal_order->get_billing_company());
                $lastName = self::get_clean_string($renewal_order->get_billing_last_name());
                $firstName = self::get_clean_string($renewal_order->get_billing_first_name());
                $customerName = $firstName . " " . $lastName;
                if ($compName != '') {
                    $customerName = $compName;
                }
                // ======================================================================= //
                // =============== Get Cardcom's saved payment method (Token) ============ //
                // ======================================================================= //
                $billingToken = $this->get_cardcom_token_from_order($renewal_order);
                // ------------ If not found, report to merchant ------------ //
                if (isset($billingToken) === false) {
                    $renewal_order->add_order_note($msg_prefix . __("Could not find payment token for subscription ", 'cardcom'));
                    WC_Subscriptions_Manager::process_subscription_payment_failure_on_order($renewal_order);
                    $renewal_order->save();
                    return;
                }
                // ======================================================================= //
                // =========================== Prepare Request =========================== //
                // ======================================================================= //
                self::cardcom_log($log_title, "Prepare Request");
                $request = array();
                // =========================== Set Invoice Fields =========================== //
                if ($this->invoice == '1') {
                    self::cardcom_log($log_title, "initInvoice method used");
                    $request = self::initInvoice($renewal_order_id, self::$cvv_free_trm);
                } else {
                    self::cardcom_log($log_title, "initTerminal method used");
                    $request = self::initTerminal($renewal_order_id, self::$cvv_free_trm);
                }
                $UniqAsmachta = "renewal_order" . $renewal_order_id . $this->GetCurrentURL();
                if (strlen($UniqAsmachta) > 50) {
                    $UniqAsmachta = substr($UniqAsmachta, 0, 50);
                }
                $request['TokenToCharge.UniqAsmachta'] = $UniqAsmachta;
                // Ignored by Cardcom when an invoice is created (invoice=1) -- it answers 608 instead,
                // which is_token_charge_paid() below also treats as paid.
                $request['TokenToCharge.UniqAsmachtaReturnOriginal'] = "true";
                $request['CustomeFields.Field1'] = 'Scheduled payment Deal';
                $request['CustomeFields.Field2'] = "renewal_order_id:" . $renewal_order_id;
                $request['CustomeFields.Field3'] = "renewal_total:" . $renewal_total;
                $request['TokenToCharge.Token'] = $billingToken->get_token();
                $request['TokenToCharge.CardValidityMonth'] = $billingToken->get_expiry_month();
                $request['TokenToCharge.CardValidityYear'] = $billingToken->get_expiry_year();
                $request['TokenToCharge.APILevel'] = '10';
                $request["TokenToCharge.SumToBill"] = number_format($renewal_total, 2, '.', '');
                $request["TokenToCharge.CardOwnerName"] = $customerName;
                $request["TokenToCharge.CardOwnerEmail"] = self::get_clean_string($renewal_order->get_billing_email());
                $request["TokenToCharge.CardOwnerPhone"] = self::get_clean_string($renewal_order->get_billing_phone());
                $urlencoded = http_build_query($this->senitize($request));
                $urlencoded = $this->append_api_password($urlencoded);
                $args = array('body' => $urlencoded,
                    'timeout' => '10',
                    'redirection' => 0, // never follow redirects with credentials in the body
                    'httpversion' => '1.0',
                    'blocking' => true,
                    'headers' => array(),
                    'cookies' => array());
                WC_Gateway_Cardcom::cardcom_log($log_title, "Request SET");
                // ======================================================================= //
                // ======================= Send and Handle Response ====================== //
                // ======================================================================= //
                $response = $this->cardcom_post(self::$CardComURL . '/interface/ChargeToken.aspx', $args);
                WC_Gateway_Cardcom::cardcom_log($log_title, "GOT Response");
                $body = wp_remote_retrieve_body($response);
                $responseArray = array();
                parse_str($body, $responseArray);
                $cardcom_internal_deal_number = isset($responseArray['InternalDealNumber']) ? $responseArray['InternalDealNumber'] : 'N/A';
                $respCode = isset($responseArray['ResponseCode']) ? $responseArray['ResponseCode'] : null;
                WC_Gateway_Cardcom::cardcom_log($log_title, "respCode " . $respCode);
                WC_Gateway_Cardcom::cardcom_log($log_title, "Cardcom Internal Deal Number " . $cardcom_internal_deal_number);
                $renewal_order->add_order_note(__("Cardcom internal deal number ", 'cardcom') . $cardcom_internal_deal_number);
                $renewal_order->add_meta_data("cardcom_internal_deal_number", $cardcom_internal_deal_number);
                $renewal_order->save_meta_data();
                if (self::is_token_charge_paid($responseArray)) {
                    self::cardcom_log($log_title, "Deal Succeed with: " . $renewal_order_id);
                    if (self::is_duplicate_deal_response($responseArray)) {
                        self::note_duplicate_deal($renewal_order, $log_title);
                    }
                    $renewal_order->add_order_note($msg_prefix . __("Scheduled payment for Renewal Order", "cardcom")
                        . " # " . $renewal_order_id . " ✅ " . $payment_date_time);
                    // Keep the renewal's own deal / document numbers so the Cardcom refund and cancel-document
                    // order actions work on renewal orders (each renewal is a separate Cardcom transaction).
                    $renewal_deal_number = self::store_token_charge_references($renewal_order, $responseArray);
                    $renewal_order->payment_complete($renewal_deal_number);
                } else {
                    self::cardcom_log($log_title, "Deal Failed with: " . $renewal_order_id);
                    $renewal_order->add_order_note($msg_prefix . __("Scheduled payment for Renewal Order", "cardcom")
                        . " # " . $renewal_order_id . " ❌ " . $payment_date_time);
                    WC_Subscriptions_Manager::process_subscription_payment_failure_on_order($renewal_order);
                }
                WC_Gateway_Cardcom::cardcom_log($log_title, "Task completed");
            } catch (Exception $exception) {
                self::cardcom_error_log($log_title, $exception);
                $renewal_order->add_order_note($msg_prefix . __("Scheduled payment for Renewal Order", "cardcom")
                    . " # " . $renewal_order_id . " ❌ " . $payment_date_time . " Ended with an error. Please send logs and call support");
                WC_Subscriptions_Manager::process_subscription_payment_failure_on_order($renewal_order);
            }
            $renewal_order->save();
        }

        /**
         * Hook action that catches any scheduled subscription payment that occurs in Merchant's site.
         * This is not ideal since need we an action that invokes scheduled subscription payment ONLY related
         * To Cardcom's payment gateway. That action hook is "woocommerce_scheduled_subscription_payment_{gateway_id}"
         * was attached with the method "scheduled_subscription_payment_gateway_specific".
         *
         * DEAD CODE: this method is never invoked. Its add_action is commented out in the constructor and it
         * returns immediately below; renewals are handled by cardcom_scheduled_subscription_payment().
         * Do not apply fixes here -- apply them to cardcom_scheduled_subscription_payment() instead.
         *
         * @param $subscription_id integer
         */
        public function cardcom_scheduled_subscription_payment_alt($subscription_id)
        {
            // ========================================================= //
            // ================== Set local variables ================== //
            // ========================================================= //
            $subscription = null;
            $order = null;
            $logTitle = "cardcom_scheduled_subscription_payment";
            $msg_prefix = __("Cardcom", "cardcom") . ": ";
            $payment_date_time = date("Y-m-d l H:i");
            self::cardcom_log($logTitle, "Initialed");
            self::cardcom_log($logTitle, "Sub-ID " . $subscription_id);
            self::cardcom_log($logTitle, "Set local variables");
            self::cardcom_log($logTitle, "STOPPING process since \"cardcom_scheduled_subscription_payment\" Does the job instead.");
            return;
            try {
                $subscription = wcs_get_subscription($subscription_id);
                // ======== Could not find subscription object to process ======== //
                if (!isset($subscription) || !$subscription) {
                    self::cardcom_log($logTitle, "Could not get Subscription object with ID " . $subscription_id);
                    return;
                }
                // ======== The subscription object is not set on the Cardcom Payment Gateway product ======== //
                if ($subscription->get_payment_method() !== $this->id) {
                    self::cardcom_log($logTitle, "The subscription's payment method is not cardcom. 
                        It's " . $subscription->get_payment_method());
                    return;
                }
                $amount_to_charge = $subscription->get_total();
                $order = wc_get_order($subscription->get_parent_id());
                // ======== Could not find subscription object to process ======== //
                if (!isset($order) || $order === false) {
                    self::cardcom_log($logTitle, "Could not load Order (subscription's parent object)");
                    $subscription->add_order_note(__("Cardcom : Could not find subscription's order", 'cardcom'));
                    return;
                }
                // ======== The Order object is not set on the Cardcom Payment Gateway product ======== //
                if ($order->get_payment_method() !== $this->id) {
                    self::cardcom_log($logTitle, "The order's payment method is not cardcom. 
                        It's " . $order->get_payment_method());
                    $subscription->add_order_note(__("Cardcom : The subscription's order was not charge via cardcom", 'cardcom'));
                    return;
                }
                $order_id = $order->get_id();
                $customer_id = $order->get_user_id();
                $compName = self::get_clean_string($order->get_billing_company());
                $lastName = self::get_clean_string($order->get_billing_last_name());
                $firstName = self::get_clean_string($order->get_billing_first_name());
                $customerName = $firstName . " " . $lastName;
                if ($compName != '') {
                    $customerName = $compName;
                }
                // ======================================================================= //
                // =============== Get Cardcom's saved payment method (Token) ============ //
                // ======================================================================= //
                $billingToken = null;
                // ------------ Get the selected token of the subscription ------------ //
                $token_Id = $subscription->get_meta('CardcomTokenId');
                self::cardcom_log($logTitle, "User's selected token : " . $token_Id);
                // ------------ Get token list from user ------------ //
                $customer_tokens = WC_Payment_Tokens::get_customer_tokens($customer_id, $this->id);
                foreach ($customer_tokens as $token) {
                    self::cardcom_log($logTitle, "Loop Token Id: " . $token->get_id());
                    if (isset($token) && strval($token->get_id()) === $token_Id) {
                        self::cardcom_log($logTitle, "User's token found");
                        $billingToken = $token;
                        break;
                    }
                }
                // ------------ If not found, report to merchant ------------ //
                if (isset($billingToken) === false) {
                    self::cardcom_log($logTitle, "No billing payment (token) was found for customer " . $customer_id .
                        " with Token Id " . $token_Id);
                    $order->add_order_note($msg_prefix .
                        __("Customer removed saved payment method for subscription ", 'cardcom') . " #" . $subscription_id);
                    $order->save();
                    $subscription->payment_failed();
                    $subscription->add_order_note($msg_prefix . __("Customer removed saved payment method", 'cardcom'));
                    $subscription->save();
                    return;
                }
                // ======================================================================= //
                // =========================== Prepare Request =========================== //
                // ======================================================================= //
                self::cardcom_log($logTitle, "Prepare Request");
                $request = array();
                // =========================== Set Invoice Fields =========================== //
                if ($this->invoice == '1') {
                    self::cardcom_log($logTitle, "initInvoice method used");
                    $request = self::initInvoice($subscription->get_id(), self::$cvv_free_trm);
                } else {
                    self::cardcom_log($logTitle, "initTerminal method used");
                    $request = self::initTerminal($subscription->get_id(), self::$cvv_free_trm);
                }
                // =========================== Set Unique Asmachta =========================== //
                $recurring_payments_count_metadata_key = 'reccuring_payments_count';
                if ($subscription->meta_exists($recurring_payments_count_metadata_key)) {
                    self::cardcom_log($logTitle, "recurring already happened once");
                    $lastPaymentIndex = $subscription->get_meta($recurring_payments_count_metadata_key);
                    $lastPaymentIndex = intval($lastPaymentIndex);
                    $lastPaymentIndex = strval($lastPaymentIndex + 1);
                    $subscription->update_meta_data($recurring_payments_count_metadata_key, $lastPaymentIndex);
                    $subscription->save();
                } else {
                    self::cardcom_log($logTitle, "This is the first recurring");
                    $subscription->add_meta_data($recurring_payments_count_metadata_key, '1');
                    $subscription->save();
                }
                $reccuring_payment_index = $subscription->get_meta($recurring_payments_count_metadata_key, true);
                self::cardcom_log($logTitle, "Current payment index is " . $reccuring_payment_index);
                $request['TokenToCharge.UniqAsmachta'] = $subscription_id . $this->GetCurrentURL() . $reccuring_payment_index;
                $request['CustomeFields.Field1'] = 'Scheduled payment Deal';
                $request['CustomeFields.Field2'] = "order_id:" . $order_id;
                $request['CustomeFields.Field3'] = "subscription_id:" . $subscription_id;
                $request['CustomeFields.Field4'] = "recurring_payment_index" . $reccuring_payment_index;
                $request['TokenToCharge.Token'] = $billingToken->get_token();
                $request['TokenToCharge.CardValidityMonth'] = $billingToken->get_expiry_month();
                $request['TokenToCharge.CardValidityYear'] = $billingToken->get_expiry_year();
                $request['TokenToCharge.APILevel'] = '10';
                $request["TokenToCharge.SumToBill"] = number_format($amount_to_charge, 2, '.', '');
                $request["TokenToCharge.CardOwnerName"] = $customerName;
                $request["TokenToCharge.CardOwnerEmail"] = self::get_clean_string($order->get_billing_email());
                $request["TokenToCharge.CardOwnerPhone"] = self::get_clean_string($order->get_billing_phone());
                $urlencoded = http_build_query($this->senitize($request));
                $args = array('body' => $urlencoded,
                    'timeout' => '10',
                    'redirection' => 0, // never follow redirects with credentials in the body
                    'httpversion' => '1.0',
                    'blocking' => true,
                    'headers' => array(),
                    'cookies' => array());
                WC_Gateway_Cardcom::cardcom_log($logTitle, "Request SET");
                // ======================================================================= //
                // ======================= Send and Handle Response ====================== //
                // ======================================================================= //
                $response = $this->cardcom_post(self::$CardComURL . '/interface/ChargeToken.aspx', $args);
                WC_Gateway_Cardcom::cardcom_log($logTitle, "GOT Response");
                $body = wp_remote_retrieve_body($response);
                $responseArray = array();
                parse_str($body, $responseArray);
                $IPN = isset($responseArray['InternalDealNumber']) ? $responseArray['InternalDealNumber'] : null;
                $respCode = isset($responseArray['ResponseCode']) ? $responseArray['ResponseCode'] : null;
                WC_Gateway_Cardcom::cardcom_log($logTitle, "respCode " . $respCode);
                $isSuccess = isset($respCode) && ($respCode === '0');
                if ($isSuccess) {
                    self::cardcom_log($logTitle, "Deal Succeed with Sub-ID " . $subscription_id);
                    $subscription->payment_complete();
                    $order->add_order_note($msg_prefix . __("Scheduled payment for Subscription", "cardcom")
                        . " # " . $subscription_id . " ✅ " . $payment_date_time);
                    $subscription->add_order_note(__("IPN payment completed OK! Deal Number: ", 'cardcom') .
                        $IPN . ' ' . $payment_date_time);
                } else {
                    // Revert last Payment Index to previous.
                    $lastPaymentIndex = $subscription->get_meta($recurring_payments_count_metadata_key);
                    $lastPaymentIndex = intval($lastPaymentIndex);
                    $lastPaymentIndex = strval($lastPaymentIndex - 1);
                    $subscription->update_meta_data($recurring_payments_count_metadata_key, $lastPaymentIndex);
                    $subscription->save();
                    // Log that deal failed for Merchant
                    self::cardcom_log($logTitle, "Deal Failed with Sub-ID " . $subscription_id);
                    $subscription->payment_failed();
                    $order->add_order_note($msg_prefix . __("Scheduled payment for Subscription", "cardcom")
                        . " # " . $subscription_id . " ❌ " . $payment_date_time);
                    $subscription->add_order_note(__("IPN payment completed NOT OK! Deal Number: ", 'cardcom') .
                        $IPN . ' ' . $payment_date_time);
                }
                $subscription->save();
                $order->save();
                WC_Gateway_Cardcom::cardcom_log($logTitle, "Task completed");
            } catch (Exception $e) {
                $subscription->payment_failed();
                $order->add_order_note($msg_prefix . __("An error occurred in subscription", "cardcom")
                    . " # " . $subscription_id . " " . $payment_date_time);
                $subscription->add_order_note($msg_prefix . __("An unexpected error occurred, please check logs: ", 'cardcom')
                    . ' ' . $payment_date_time);
            }
        }

        /**Triggered when a payment is made on a subscription.
         * This can be payment for the initial order or a renewal order)
         *
         * @param $subscription WC_Subscription
         * @throws Exception (But not really)
         */
        public function cardcom_subscription_payment_complete($subscription)
        {
            $log_title = "cardcom_subscription_payment_complete";
            self::cardcom_log($log_title, "Initiated");
            self::cardcom_log($log_title, "Sub Id : " . $subscription->get_id());
            // ================ Get Order's token values and save it to the subscription ================ //
            $order = wc_get_order($subscription->get_parent_id());
            if (!$order) {
                self::cardcom_log($log_title, "Could not find subscription's order with this Id " . $subscription->get_parent_id());
                $subscription->update_status('failed', __("could not get order's token to charge user.", 'cardcom'));
                return;
            }
            $token = $this->get_cardcom_token_from_order($order);
            if(isset($token)) {
                self::cardcom_log($log_title, "Token found from order");
                $this->save_token_in_order_v2($token, $subscription);
                $subscription->save();
                self::cardcom_log($log_title, "Set token on the subscription successfully");
            } else {
                // This CASE happened to a merchant which had new Plugins from WooFunnels (WooFunnel's builder).
                // He tried to test the WooFunne's Upsell feature and got an error in this section, see Bug 929 in BillGold
                self::cardcom_log($log_title, "Could not find any token in the order, so no token will be saved in subscription item");
				
				//A fix for the issue that PayPal invoices are not being created for subscription orders because no token is saved in the order
				self::CreateinvoiceForPayPal($order->get_id());
            }
        }

        /** Triggered when a renewal payment is made on a subscription.
         *
         * @param $subscription WC_Subscription
         * @param $last_order WC_Order
         */
        public function cardcom_subscription_renewal_payment_complete($subscription, $last_order)
        {
            $logTitle = "cardcom_subscription_renewal_payment_complete";
            self::cardcom_log($logTitle, "Initiated");
            self::cardcom_log($logTitle, "sub Id : " . $subscription->get_id());
            self::cardcom_log($logTitle, "last order Id : " . $last_order->get_id());
            $date_time = date("Y-m-d l H:i");
            $cardcom_internal_deal_number = $last_order->get_meta('cardcom_internal_deal_number');
            if (isset($cardcom_internal_deal_number)) {
                $subscription->add_order_note(__("Cardcom", 'cardcom') . ' ' . $date_time . ' ' .
                    __("Renewal order - ", 'cardcom') . $last_order->get_id() .
                    __("Cardcom Deal Number - ", 'cardcom') . $cardcom_internal_deal_number);
                $subscription->save();
                $order = wc_get_order($subscription->get_parent_id());
                // ======== Could not find subscription object to process ======== //
                if (isset($order) && $order !== false) {
                    $order->add_order_note(__("Cardcom", 'cardcom') . ' ' . $date_time . ' ' .
                        __("Renewal order - ", 'cardcom') . $last_order->get_id() .
                        __("Cardcom Deal Number - ", 'cardcom') . $cardcom_internal_deal_number);
                    $order->save();
					
					//A fix for the issue that PayPal invoices are not being created for subscription renewal orders because no token is saved in the order
					self::CreateinvoiceForPayPal($order->get_id());
                }
            }
        }

        /**Triggered when a payment fails for a subscription.
         * This can be for payment of the initial order, a switch order or renewal order.
         *
         * @param $subscription WC_Subscription
         * @param $new_status string
         */
        public function cardcom_subscription_payment_failed($subscription, $new_status)
        {
            $logTitle = "cardcom_subscription_payment_failed";
            self::cardcom_log($logTitle, "Initiated");
            self::cardcom_log($logTitle, "sub Id : " . $subscription->get_id());
            self::cardcom_log($logTitle, "new status : " . $new_status);
        }

        /**Triggered when a renewal payment fails for a subscription.
         * It is only triggered for payments on renewal orders.
         *
         * @param $subscription WC_Subscription
         */
        public function cardcom_subscription_renewal_payment_failed($subscription)
        {
            $logTitle = "cardcom_subscription_renewal_payment_failed";
            self::cardcom_log($logTitle, "Initiated");
            self::cardcom_log($logTitle, "Sub Id : " . $subscription->get_id());
        }
        //endregion

        //region Subscription Status Change Actions
        /**
         * @param $subscription WC_Subscription
         * @param $new_status string
         * @param $old_status string
         */
        public function cardcom_subscription_status_updated($subscription, $new_status, $old_status)
        {
            $log_title = "cardcom_subscription_status_updated";
            self::cardcom_log($log_title, "Sub Id: " . $subscription->get_id());
            self::cardcom_log($log_title, "New status: " . $new_status);
            self::cardcom_log($log_title, "Old status: " . $old_status);
        }

        /**
         * @param $subscription WC_Subscription
         */
        public function cardcom_subscription_status_active($subscription)
        {
            $log_title = "cardcom_subscription_status_active";
            self::cardcom_log($log_title, "Sub Id: " . $subscription->get_id());
        }

        /**
         * @param $subscription WC_Subscription
         */
        public function cardcom_subscription_status_cancelled($subscription)
        {
            $log_title = "cardcom_subscription_status_cancelled";
            self::cardcom_log($log_title, "Sub Id: " . $subscription->get_id());
        }

        /**
         * @param $subscription WC_Subscription
         */
        public function cardcom_subscription_status_expired($subscription)
        {
            $log_title = "cardcom_subscription_status_expired";
            self::cardcom_log($log_title, "Sub Id: " . $subscription->get_id());
        }

        /**
         * @param $subscription WC_Subscription
         */
        public function cardcom_subscription_status_on_hold($subscription)
        {
            $log_title = "cardcom_subscription_status_on_hold";
            self::cardcom_log($log_title, "Sub Id: " . $subscription->get_id());
        }
        //endregion

        //endregion

        //region Util functions

        /**
         * @param $order WC_Order
         * @return bool indicator that the token created must be saved on user
         */
        function must_save_token_on_user($order)
        {
            return self::HasWooFunnelsUpsell();
        }

        /**
         * @param $order_id
         * @return bool True if the order contains a subscription product
         */
        protected function order_contains_subscription($order_id)
        {
            return function_exists('wcs_order_contains_subscription') && (wcs_order_contains_subscription($order_id) || wcs_order_contains_renewal($order_id));
        }

        /**
         * @return bool if WordPress site has WooCommerce Subscription Plugin extention installed, return true, else false
         */
        static function HasWooSubPlugin()
        {
            return class_exists('WC_Subscriptions_Order');
        }

        /**
         * @return bool if WordPress Site has WooFunnel's Upsell plugin installed
         */
        static function HasWooFunnelsUpsell()
        {
            return (class_exists('WooFunnels_Support_Upstroke_CardCom_Compatibility') || class_exists('WFOCU_CardCom_Compatibility'))
            && self::my_is_plugin_active("upstroke-woocommerce-one-click-upsell-cardcom/upstroke-woocommerce-one-click-upsell-cardcom.php");
        }

        /**
         * @param $log_title string title of the log error
         * @param $exception Exception the error to log
         */
        static function cardcom_error_log($log_title, $exception)
        {
            $file = $exception->getFile(); // Currently not used because the plugin has only one PHP file
            $line = $exception->getLine();
            $msg = $exception->getMessage();
            $trace = $exception->getTraceAsString();
            $err_log_info = 'Error ::: ' . $msg . ' | Line ::: ' . $line . ' | Trace ::: ' . $trace;
            // Always log errors regardless of debug setting
            error_log("Cardcom ::: " . $log_title . " ::: " . $err_log_info . "\n");
        }

        /**
         * Utility Function: Logs with error_log but with extra detail set in to know it's our log
         * @param string $logTitle <p>The title of the message log</p>
         * @param string $logInfo <p> Info on what happened </p>
         */
        static function cardcom_log($logTitle, $logInfo = "")
        {
            // Only log if debug mode is enabled in settings
            if (isset(self::$debug_logging) && self::$debug_logging === '1') {
                error_log("Cardcom ::: " . $logTitle . " ::: " . $logInfo . "\n");
            }
        }

        /**
         * Write a verbose payload to the WooCommerce logs, but only when Debug Logging is on.
         *
         * Mirrors cardcom_log()'s gating so the heavy print_r() serialization and the
         * synchronous WC_Logger disk write are skipped entirely on production sites that
         * leave Debug Logging off.
         *
         * @param string $handle WC_Logger source handle (becomes the log file name).
         * @param mixed  $data   Payload; serialized with print_r() only when logging is enabled.
         */
        static function cardcom_log_wc( $handle, $data )
        {
            if ( isset( self::$debug_logging ) && self::$debug_logging === '1' ) {
                ( new WC_Logger() )->add( $handle, print_r( is_array( $data ) ? self::redact( $data ) : $data, true ) );
            }
        }

        /**
         * @return bool if the operation selected allows for users to pay via saved payment methods (i.e. tokens)
         */
        public function operation_allows_to_pay_via_tokens()
        {
            // If "Capture Charge" OR "Charge and token" OR "Save Token (Only)"
            return $this->operation === '2' || $this->operation === '6' || $this->operation === '3';
        }

        /**
         * @return bool if the operation selected allows for users to save payment method (i.e. tokens)
         */
        public function allows_to_optionally_save_tokens()
        {
            // If "Capture Charge" OR "(Default) Charge + token"
            return $this->operation === '6' || $this->operation === '2' || $this->operation === '3';
        }

        /**
         * @return bool defined here if operation is compatible with PCI fields
         */
        public function does_operation_compatible_with_PCI_fields()
        {
            // If "Charge and token" OR "Charge Only" OR "Capture Charge" || "Create Token Only"
            return $this->operation === '2' || $this->operation === '1' || $this->operation === '6' || $this->operation === '3';
        }

        /**
         * Utility Function: get the core string value (e.g. from client page value strings)
         * @param $stringToClean the string to clean
         * @return string the same string but mostly cleaned from unnecessary characters (e.g. Html tags)
         */
        static function get_clean_string($stringToClean)
        {
            $stringToClean = substr(strip_tags(preg_replace("/&#\d*;/", " ", $stringToClean)), 0, 200);
            return $stringToClean;
        }

        /**
         * @param $val bool | string | int | object
         * @return bool parses value to a boolean like value much.
         */
        static function get_boolean_like($val)
        {
            if (is_bool($val)) { // return as is
                return $val;
            } else if (is_string($val)) { // check if string contains string is "true" value (NOT CASE SENSITIVE)
            return strtolower($val) === "true";
            } else if (is_int($val)) { // Check if equals to 1
                return $val === 1;
            } else { // Most basic is to check if value is set
                return isset($val);
            }
        }

        /**
         * @param $plugin string, the path to the plugin main php file. Relative to the plugin directory
         * @return bool
         */
        static function my_is_plugin_active($plugin)
        {
            // This function is used because the native "is_plugin_active" is not available in User pages.
            // For more info and solutions check this: https://wordpress.stackexchange.com/questions/9345/is-plugin-active-function-doesnt-exist
            // The solution provided from the page above is this: https://wordpress.stackexchange.com/a/15994
            if (function_exists("is_plugin_active")) {
                return is_plugin_active($plugin);
            } else {
                return in_array($plugin, (array)get_option('active_plugins', array()));
            }
        }

        /**
         * @param $str string
         * @return bool
         */
        static function string_is_set($str)
        {
            return (isset($str) && $str !== '' && strlen(trim($str)) > 0);
        }

        /**
         * @param $val any
         * @return int |null
         */
        static function try_parse_int($val)
        {
            if (is_int($val)) return $val;
            $int_value = ctype_digit($val) ? intval($val) : null;
            return $int_value;
        }
        //endregion

        public function add_id_billing_field( $fields ) {
            // Mandatory only when the admin set the field to "required".
            $required = ( $this->id_field === 'required' );
            $fields['cardcom_id_number'] = array(
                'label'    => __( 'Tax / Company ID', 'cardcom' ),
                'required' => $required,
                'class'    => array( 'form-row-wide' ),
                'priority' => 35,
            );
            return $fields;
        }

        public function save_id_billing_field( $order_id ) {
            if ( ! empty( $_POST['cardcom_id_number'] ) ) {
                $order = wc_get_order( $order_id );
                if ( ! $order ) {
                    return;
                }
                $order->update_meta_data( '_cardcom_id_number', sanitize_text_field( wp_unslash( $_POST['cardcom_id_number'] ) ) );
                $order->save();
            }
        }

    }
    // Include the block class
    require_once __DIR__ . '/blocks/class-wc-cardcom-payment-block.php';

    /**
     * Add the Gateway to WooCommerce
     **/
    function add_cardcom_gateway($methods)
    {
        $methods[] = 'WC_Gateway_Cardcom';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_cardcom_gateway');

    
    add_action( 'woocommerce_blocks_loaded', 'woocommerce_gateway_cardcom_woocommerce_block_support');

    function woocommerce_gateway_cardcom_woocommerce_block_support() {
	
		if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			// require_once 'includes/blocks/class-wc-dummy-payments-blocks.php';
			add_action(
				'woocommerce_blocks_payment_method_type_registration',
				function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
					$payment_method_registry->register( new WC_Gateway_Cardcom_Blocks_Support() );
				}
			);
		}
	}
    WC_Gateway_Cardcom::init(); // add listner to paypal payments

    add_action( 'woocommerce_init', function() {
        if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
            return;
        }
        $settings = get_option( 'woocommerce_cardcom_settings', array() );
        $enabled  = isset( $settings['enabled'] ) ? $settings['enabled'] : 'no';
        if ( $enabled !== 'yes' ) {
            return;
        }
        $id_field = isset( $settings['id_field'] ) ? $settings['id_field'] : 'hidden';
        if ( $id_field === 'hidden' ) {
            return;
        }
        woocommerce_register_additional_checkout_field( array(
            'id'       => 'cardcom/id-number',
            'label'    => __( 'Tax / Company ID', 'cardcom' ),
            'location' => 'address',
            'required' => ( $id_field === 'required' ),
        ) );
    } );

    // Add admin notice when debug_logging is enabled
    add_action('admin_notices', function() {
        $cardcom_settings = get_option('woocommerce_cardcom_settings', array());
        if (isset($cardcom_settings['debug_logging']) && $cardcom_settings['debug_logging'] === '1') {
            echo '<div class="notice notice-warning is-dismissible">
                    <p>' . esc_html__('CardCom Debug Logging is enabled. Remember to disable it after troubleshooting to avoid excessive log entries.', 'cardcom') . '</p>
                  </div>';
        }
    });
}