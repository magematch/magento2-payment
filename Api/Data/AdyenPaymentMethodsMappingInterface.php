<?php
declare(strict_types=1);

/**
 * MageMatch_Payment
 *
 * @category  MageMatch
 * @package   MageMatch\Payment
 * @author    MageMatch <arjundhiman90@gmail.com>
 * @copyright 2024 MageMatch
 * @license   MIT
 */

namespace MageMatch\Payment\Api\Data;

use Adyen\Payment\Helper\PaymentMethods\ApplePayPaymentMethod;
use Adyen\Payment\Helper\PaymentMethods\GooglePayPaymentMethod;
use Adyen\Payment\Helper\PaymentMethods\PayPalPaymentMethod;

interface AdyenPaymentMethodsMappingInterface
{
    /**
     * Scheme and Payment Name Mapping
     */
    const PAYMENT_NAME_FOR_SCHEME = [
        'paywithgoogle' => GooglePayPaymentMethod::NAME,
        'applepay' => ApplePayPaymentMethod::NAME,
        'paypal' => PayPalPaymentMethod::NAME
    ];
}

