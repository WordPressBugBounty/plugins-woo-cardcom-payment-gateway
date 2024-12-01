import React, { useState } from 'react';

export function CardcomCVV({settings}) {

    const alwaysDisplayCVV = settings.cerPCI == '1' ? true : false;
    return (  
    <p className={"form-row " + (alwaysDisplayCVV ? '' : 'payment_method_cardcom_validation')}  >
        <label htmlFor={settings.id+"-card-cvc"}>Security Digits (CVV)<span className="required">*</span></label>
        <input id={settings.id+"-card-cvc"} name={settings.id+"-card-cvc"}  className="input-text" autoComplete="off" autoCorrect="no" autoCapitalize="no" spellCheck="no" 
        type="tel" maxLength="4" placeholder="CVC" style={{width: "150px"}} /> 
    </p>
 )}

