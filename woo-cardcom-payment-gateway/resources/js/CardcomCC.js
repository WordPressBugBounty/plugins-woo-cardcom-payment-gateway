import React, { useState } from 'react';

export function CardcomCC({settings}) {

    const alwaysDisplayCVV = settings.cerPCI == '1' ? true : false;
    let months = [], years = [];
    let yy = (new Date().getFullYear()+'').slice(-2);

    for(let i = 1; i <= 12; i++ ){
        months.push( <option key={i} value={i} > {i} </option>);
    }
    
    for(let j = yy; j <= ( parseInt(yy)+15);  j++ ){
       years.push(<option key={j} value={j} >{j}</option>);
    }
    
    return (  
    <>
    <p className="form-row wc-payment-form">
        <label htmlFor="cardcom-card-number">Credit Card Number<span className="required">*</span></label>
        <input id="cardcom-card-number" name="cardcom-card-number" type="text" className="input-text" 
        maxLength="20" autoComplete="off" style={{width: "200px"}} />
    </p>
    <label htmlFor="cardcom-expire-date">Expiration date<span className="required">*</span></label>
    <br />
    <div style={{display: "flex", flexDirection: "row"}} className="wc-payment-form">
  
        <div style={{margin: "0 0.11em 0 0.11em" }}>
            <select name="cardcom-expire-month" id="cardcom-expire-month" className="woocommerce-select woocommerce-cc-month input-text">
                <option value="">Month</option>
                {months}
            </select>
        </div>
        <div  style={{margin: "0 0.11em 0 0.11em"}} >
            <select name="cardcom-expire-year" id="cardcom-expire-year" className="woocommerce-select woocommerce-cc-year input-text">
                <option value="">Year</option>
                {years}
            </select>
        </div>
    </div>
    </>  
 )}

