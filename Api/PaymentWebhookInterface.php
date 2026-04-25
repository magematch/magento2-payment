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

namespace MageMatch\Payment\Api;

interface PaymentWebhookInterface
{

    /**
     * getPost from webhook
     * @return string
     */

    public function getPost(): string;
}

