import { registerPaymentMethod } from '@woocommerce/blocks-registry';
import { decodeEntities } from '@wordpress/html-entities';
import { getSetting } from '@woocommerce/settings';
import { CardcomCVV } from  './CardcomCVV'
import { CardcomCC } from  './CardcomCC'
const { useEffect } = window.wp.element;

const settings = getSetting( 'cardcom_data', {} );
const defaultLabel = 'CardCom';
const label = decodeEntities( settings.title ) || defaultLabel;

/**
 * Content component
 */
const Content = (props) => {
	const { eventRegistration, emitResponse } = props;
	const { onPaymentSetup } = eventRegistration;
	const { onCheckoutFail } = eventRegistration;
	useEffect( () => {
		const unsubscribe = onPaymentSetup( async ( data) => {
			
			let creditCardData ;
			
			let cardComCCNumber    = document.querySelector("#cardcom-card-number");
			let cardComExpiryMonth = document.querySelector("#cardcom-expire-month");	
			let cardComExpiryYear  = document.querySelector("#cardcom-expire-year");	
			let cardComCvc         = document.querySelector("#cardcom-card-cvc");

			if( cardComCCNumber ){
				
				creditCardData = {
					'cardcom-card-number' : cardComCCNumber.value,
					'cardcom-expire-month' : cardComExpiryMonth.value,
					'cardcom-expire-year' : cardComExpiryYear.value ,
				};
			}else{
				return {
					type: emitResponse.responseTypes.SUCCESS,
					meta: {}
				};
			}
			
			if( cardComCvc ){
				creditCardData['cardcom-card-cvc'] = cardComCvc.value ;
			}

			if ( creditCardData ) {
				return {
					type: emitResponse.responseTypes.SUCCESS,
					meta: {
						paymentMethodData: creditCardData,
					},
				};
			}

			return {
				type: emitResponse.responseTypes.ERROR,
				message: 'There was an error',
			};
		} );
		// Unsubscribes when this component is unmounted.
		return () => {
			unsubscribe();
		};
	}, [
		emitResponse.responseTypes.ERROR,
		emitResponse.responseTypes.SUCCESS,
		onPaymentSetup,
	] );

	
	useEffect( () => {
		const unsubscribe = onCheckoutFail( processFailure );
	}, [ emitResponse.responseTypes.ERROR, onCheckoutFail ] );

	const alwaysDisplayCVV = settings.cerPCI == '1' ? true : false;
	return (
		
		<>
		{(settings.cerPCI == '1' && settings.does_operation_compatible_with_PCI_fields ) && <CardcomCC settings={settings}></CardcomCC>}
		{(settings.must_cvv == '1' && settings.rendercvv ) && <CardcomCVV settings={settings}></CardcomCVV>}
		{decodeEntities( settings.description || '' )}
		</>);
};

/**
 * Get reason for failure and show message.
 * @param {object} data 
 */
function processFailure( data ){

	let errorMessage = data.processingResponse.paymentDetails.message;
	data.processingResponse.message = errorMessage
	return {
		message: errorMessage 
	};	
	
}

/**
 * Label component
 *
 * @param {*} props Props from payment API.
 */
const Label = ( props ) => {
	const { PaymentMethodLabel } = props.components;
	return <PaymentMethodLabel text={ label } />;
};

const SavedToken = (props) =>{
	return (<></>);
}

const options = {
	name: 'cardcom',
	label:<Label />,
	content: <Content />,
	edit: <Content />,
	canMakePayment: () => true,
	paymentMethodId: 'cardcom',
	savedTokenComponent: <SavedToken />,
	ariaLabel: label,
	supports: {
		showSavedCards: settings.showSavedCards,
		showSaveOption:  settings.showSaveOption,
		features: settings.supports,
		savePaymentInfo: settings.showSavedCards
	},
};
registerPaymentMethod( options );