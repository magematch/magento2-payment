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

namespace MageMatch\Payment\Model\Api;

use Magento\Framework\Exception\CouldNotSaveException;
use Psr\Log\LoggerInterface;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Framework\Webapi\Rest\Response;
use Adyen\Exception\AuthenticationException;
use Adyen\Exception\MerchantAccountCodeException;
use Adyen\Payment\Helper\Config;
use Adyen\Payment\Helper\Data;
use Adyen\Payment\Helper\IpAddress;
use Adyen\Payment\Helper\RateLimiter;
use Adyen\Payment\Logger\AdyenLogger;
use Adyen\Payment\Model\Notification;
use Adyen\Payment\Model\NotificationFactory;
use Adyen\Webhook\Exception\HMACKeyValidationException;
use Adyen\Webhook\Exception\InvalidDataException;
use Adyen\Webhook\Receiver\HmacSignature;
use Adyen\Webhook\Receiver\NotificationReceiver;
use MageMatch\Payment\Api\PaymentWebhookInterface;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\SerializerInterface;
use Symfony\Component\Config\Definition\Exception\Exception;
use MageMatch\Payment\Model\Resolver\OrderPayment;

class PaymentWebhook
{
    const PENDING_PAYMENT = 'pending_payment';
    const PAYMENT_REVIEW = 'payment_review';
    const PAYMENT_FAILED = 'payment_failed';
    const PAYMENT_PROCESSING = 'processing';
    const REFUND_APPROVED = "refund_approved";
    const CANCELLED = 'canceled';
    const PROCESSING = 'processing';
    const CLOSED = 'closed';

    const ORDER_STATUS_MAPPING = [
        Notification::REFUND => self::REFUND_APPROVED,
        Notification::AUTHORISATION => self::PAYMENT_PROCESSING,
        Notification::REFUND_FAILED => self::PAYMENT_REVIEW,
        "REFUND_REVERSED" => self::PAYMENT_REVIEW,
        Notification::CAPTURE => self::PAYMENT_PROCESSING,
        Notification::AUTHORISED => self::PAYMENT_PROCESSING,
        Notification::ERROR => self::PAYMENT_FAILED,
        Notification::REFUSED => self::PAYMENT_FAILED,
        Notification::PENDING => self::PENDING_PAYMENT,
        Notification::CANCELLED => self::CANCELLED
    ];

    protected $logger;
    protected $request;
    protected $response;
    protected $orderPayment;
    /**
     * @var NotificationFactory
     */
    private $notificationFactory;

    /**
     * @var Data
     */
    private $adyenHelper;

    /**
     * @var AdyenLogger
     */
    private $adyenLogger;

    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var Config
     */
    private $configHelper;

    /**
     * @var IpAddress
     */
    private $ipAddressHelper;

    /**
     * @var RateLimiter
     */
    private $rateLimiterHelper;

    /**
     * @var HmacSignature
     */
    private $hmacSignature;

    /**
     * @var NotificationReceiver
     */
    private $notificationReceiver;


    public function __construct(
        LoggerInterface $logger,
        Request $request,
        Response $response,
        Context $context,
        NotificationFactory $notificationFactory,
        Data $adyenHelper,
        AdyenLogger $adyenLogger,
        SerializerInterface $serializer,
        Config $configHelper,
        IpAddress $ipAddressHelper,
        RateLimiter $rateLimiterHelper,
        HmacSignature $hmacSignature,
        NotificationReceiver $notificationReceiver,
        OrderPayment $orderPayment
    )
    {
        $this->logger = $logger;
        $this->request = $request;
        $this->response = $response;
        $this->notificationFactory = $notificationFactory;
        $this->adyenHelper = $adyenHelper;
        $this->adyenLogger = $adyenLogger;
        $this->serializer = $serializer;
        $this->configHelper = $configHelper;
        $this->ipAddressHelper = $ipAddressHelper;
        $this->rateLimiterHelper = $rateLimiterHelper;
        $this->hmacSignature = $hmacSignature;
        $this->notificationReceiver = $notificationReceiver;
        $this->orderPayment = $orderPayment;

    }

    /**
     * @inheritdoc
     */
    public function getPost()
    {
        $value = $this->request->getContent();
        // Read JSON encoded notification body
        $notificationItems = json_decode($value, true);
        $this->logger->info(json_encode($notificationItems));

        // Check notification mode
        if (!isset($notificationItems['live'])) {
            $this->return401();
            return;
        }
        $notificationMode = $notificationItems['live'];
        $demoMode = $this->configHelper->isDemoMode();
        if (!$this->notificationReceiver->validateNotificationMode($notificationMode, $demoMode)) {
            throw new LocalizedException(
                __('Mismatch between Live/Test modes of Magento store and the Adyen platform')
            );
        }

        try {
            // Process each notification item
            $acceptedMessage = '';

            foreach ($notificationItems['notificationItems'] as $notificationItem) {
                $status = $this->processNotification(
                    $notificationItem['NotificationRequestItem'],
                    $notificationMode
                );

                if ($status !== true) {
                    $this->return401();
                    return;
                }

                $acceptedMessage = "[accepted]";
            }

            // Run the query for checking unprocessed notifications, do this only for test notifications coming
            // from the Adyen Customer Area
            $cronCheckTest = $notificationItems['notificationItems'][0]['NotificationRequestItem']['pspReference'];
            if ($this->notificationReceiver->isTestNotification($cronCheckTest)) {
                $unprocessedNotifications = $this->adyenHelper->getUnprocessedNotifications();
                if ($unprocessedNotifications > 0) {
                    $acceptedMessage .= "\nYou have " . $unprocessedNotifications . " unprocessed notifications.";
                }
            }

            $this->adyenLogger->addAdyenNotification("The result is accepted");

            $this->response->clearHeader('Content-Type')
                ->setHeader('Content-Type', 'text/html')
                ->setBody($acceptedMessage);
            return $acceptedMessage;
        } catch (Exception $e) {
            throw new LocalizedException(__($e->getMessage()));
        }

    }


    /**
     * HTTP Authentication of the notification
     *
     * @param array $response
     * @return bool
     * @throws AuthenticationException
     * @throws MerchantAccountCodeException
     */
    private function isAuthorised(array $response)
    {
        // Add CGI support
        $this->fixCgiHttpAuthentication();

        $authResult = $this->notificationReceiver->isAuthenticated(
            $response,
            $this->configHelper->getMerchantAccount(),
            $this->configHelper->getNotificationsUsername(),
            $this->configHelper->getNotificationsPassword()
        );

        return $authResult;
    }

    /**
     * save notification into the database for cronjob to execute notification
     *
     * @param $response
     * @param $notificationMode
     * @return bool
     * @throws AuthenticationException
     * @throws MerchantAccountCodeException
     * @throws HMACKeyValidationException
     * @throws InvalidDataException
     */
    private function processNotification(array $response, $notificationMode)
    {
        if (!$this->isAuthorised($response)) {
            $this->logger->info("Webhook Authorization failed");
            return false;
        }

        $orderNumber = $response['merchantReference']??'';
        $eventCode = $response['eventCode']??'';
        $success = $response['success']??'';
        $responseCode = $response['additionalData']['retry.attempt1.responseCode']??'';
        $paymentMethod = $response['paymentMethod']??'';
        $pspReference = $response['pspReference']??'';

        $this->updateOrderStatus($orderNumber, $eventCode, $responseCode, $success, $paymentMethod, $pspReference, $response);
        return true;
    }


    /**
     * @param $orderNumber
     * @param $eventCode
     * @param $responseCode
     * @param $success
     * @param $paymentMethod
     * @param $pspReference
     * @param $requestItem
     * @return void
     * @throws CouldNotSaveException
     */

    private function updateOrderStatus($orderNumber, $eventCode, $responseCode, $success, $paymentMethod, $pspReference, $requestItem)
    {
        if (!$orderNumber && !$success) {
            return;
        }

        $orders = $this->orderPayment->getOrderIdByIncrementId($orderNumber);

        if (empty($orders) || !count($orders)) {
            return;
        }

        foreach ($orders as $order) {
            $state = "";
            $orderStatus = self::ORDER_STATUS_MAPPING[$eventCode] ?? "";
            if (!$orderStatus) {
                continue;
            }

            $orderAuthorizedStatus = $this->orderPayment->getPaymentAuthorizedStatus((int)$order->getStoreId());
            if ($success != "true") {
                continue;
            }

            if ($eventCode == "AUTHORISATION" && (($responseCode == "Approved" && $paymentMethod != "paypal") || $paymentMethod == "paypal")) {
               $state = self::PROCESSING;
               $orderStatus = self::PROCESSING;
            } elseif ($orderStatus == $orderAuthorizedStatus) {
               $state = self::PROCESSING;
            } elseif ($orderStatus == self::REFUND_APPROVED) {
               $state = self::CLOSED;
            }

           if ($state == self::PROCESSING) {
               $this->setPaymentAdditionalInformation($order, $paymentMethod, $eventCode, $pspReference, $requestItem);
           }

           $order->setStatus($orderStatus);
           if ($state) {
               $order->setState($state);
           } else {
               $order->setState($orderStatus);
           }
           $this->orderPayment->saveOrderInfo($order);
       }
    }

    /**
     * Fix these global variables for the CGI
     */
    private function fixCgiHttpAuthentication()
    {
        // do nothing if values are already there
        if (!empty($_SERVER['PHP_AUTH_USER']) && !empty($_SERVER['PHP_AUTH_PW'])) {
            return;
        } elseif (isset($_SERVER['REDIRECT_REMOTE_AUTHORIZATION']) &&
            $_SERVER['REDIRECT_REMOTE_AUTHORIZATION'] != ''
        ) {
            list(
                $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']
                ) =
                explode(':', base64_decode($_SERVER['REDIRECT_REMOTE_AUTHORIZATION']), 2);
        } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            list(
                $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']
                ) =
                explode(':', base64_decode(substr($_SERVER['REDIRECT_HTTP_AUTHORIZATION'], 6)), 2);
        } elseif (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            list(
                $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']
                ) =
                explode(':', base64_decode(substr($_SERVER['HTTP_AUTHORIZATION'], 6)), 2);
        } elseif (!empty($_SERVER['REMOTE_USER'])) {
            list(
                $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']
                ) =
                explode(':', base64_decode(substr($_SERVER['REMOTE_USER'], 6)), 2);
        } elseif (!empty($_SERVER['REDIRECT_REMOTE_USER'])) {
            list(
                $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']
                ) =
                explode(':', base64_decode(substr($_SERVER['REDIRECT_REMOTE_USER'], 6)), 2);
        }
    }

    /**
     * Return a 401 result
     */
    private function return401()
    {
        $this->response->setHttpResponseCode(401);
    }

    /**
     * @param $order
     * @param $paymentMethod
     * @param $eventCode
     * @param $pspReference
     * @param $requestItem
     * @return void
     * @throws CouldNotSaveException
     */
    private function setPaymentAdditionalInformation($order, $paymentMethod, $eventCode, $pspReference, $requestItem)
    {
        $code = "";
        $payment = $order->getPayment();
        $adyenHppMethod = ["paypal", "paywithgoogle", "applepay"];
        if (in_array($paymentMethod, $adyenHppMethod)) {
            $payment->setAdditionalInformation("brand_code", $paymentMethod);
        }else{
            $code = $this->orderPayment->getCcTypesByAlt($paymentMethod);
        }
        $payment->setAdditionalInformation("eventCode", $eventCode);
        $payment->setAdditionalInformation("brand", $paymentMethod);
        $payment->setAdditionalInformation("pspReference", $pspReference);
        $payment->setAdditionalInformation("additionalData", json_encode($requestItem));
        $payment->setCcTransId($pspReference);
        if ($code && $code != "") {
            $payment->setCcType($code);
        }
        $payment->setLastTransId($pspReference);
        $this->orderPayment->setOrderComment($order, json_encode($requestItem));
    }
}
