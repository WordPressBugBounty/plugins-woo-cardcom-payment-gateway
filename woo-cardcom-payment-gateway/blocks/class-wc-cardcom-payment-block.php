<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * CardCom Payment Blocks integration
 *
 * @since 1.0.3
 */
final class WC_Gateway_Cardcom_Blocks_Support extends AbstractPaymentMethodType {

	/**
	 * The gateway instance.
	 *
	 * @var WC_Gateway_Cardcom
	 */
	private $gateway;

	/**
	 * Payment method name/id/slug.
	 *
	 * @var string
	 */
	protected $name = 'cardcom';

	/**
	 * Initializes the payment method type.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_cardcom_settings', [] );
		$this->gateway  = new WC_Gateway_Cardcom(['block' =>true]);
	}

	/**
	 * Returns if this payment method should be active. If false, the scripts will not be enqueued.
	 *
	 * @return boolean
	 */
	public function is_active() {
		return $this->gateway->is_available();
	}

	/**
	 * Returns an array of scripts/handles to be registered for this payment method.
	 *
	 * @return array
	 */
	public function get_payment_method_script_handles() {
		$script_path       = '/assets/js/frontend/blocks.js';
		$script_asset_path = PLUGIN_DIRECTORY . 'assets/js/frontend/blocks.asset.php';
		$script_asset      = file_exists( $script_asset_path )
			? require( $script_asset_path )
			: array(
				'dependencies' => array(),
				'version'      => '1.2.0'
			);
		$script_url        = PLUGIN_DIRECTORY. $script_path;

		wp_register_script(
			'wc-cardcom-payments-blocks',
			$script_url,
			$script_asset[ 'dependencies' ],
			$script_asset[ 'version' ],
			true
		);

	

		return [ 'wc-cardcom-payments-blocks' ];
	}

	/**
	 * Returns an array of key=>value pairs of data made available to the payment methods script.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {

		$RenderCVV = false;
		$inline_cc_form = false;
        // -------------- Load saved payment methods (i.e. Token) -------------- //
            if ($this->gateway->supports('tokenization') && is_checkout() && $this->gateway->operation_allows_to_pay_via_tokens()) {
				
                $this->gateway->cardcom_checkout_script();
                $RenderCVV = true;
            }
        // ------- PCI fields Render Credit Card fields for the user to input ------- //
        if ($this->gateway->cerPCI == '1' && ($this->gateway->does_operation_compatible_with_PCI_fields())) {
             
                $inline_cc_form = true;
                $RenderCVV = true;
        }
            // -------------- Render CVV Field -------------- //
        if ($this->gateway::$must_cvv == '1' && $RenderCVV) {
        
        }
		return [
			'id'          => $this->gateway->id,			
			'title'       => $this->get_setting( 'title' ),
			'description' => $this->get_setting( 'description' ),
			'supports'    => array_filter( $this->gateway->supports, [ $this->gateway, 'supports' ] ),
			'inline_cc_form' => $inline_cc_form,
			'rendercvv'	  => $RenderCVV,
			'operations_allows_to_pay_via_token' =>   $this->gateway->operation_allows_to_pay_via_tokens(),
			'cerPCI' 	  => $this->gateway->cerPCI,
			'does_operation_compatible_with_PCI_fields' => $this->gateway->does_operation_compatible_with_PCI_fields(),
			'must_cvv'    => $this->gateway::$must_cvv,
			'showSaveOption' => $this->gateway->allows_to_optionally_save_tokens(),
			'showSavedCards' => $this->gateway->supports('tokenization') && is_checkout() && $this->gateway->operation_allows_to_pay_via_tokens()

            // more data to get added
		];
	}
}
