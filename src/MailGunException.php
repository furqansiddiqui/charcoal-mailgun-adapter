<?php
/*
 * This file is a part of "furqansiddiqui/charcoal-mailgun-adapter" package.
 * https://github.com/furqansiddiqui/charcoal-mailgun-adapter
 *
 * Copyright (c) Furqan A. Siddiqui <hello@furqansiddiqui.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code or visit following link:
 * https://github.com/furqansiddiqui/charcoal-mailgun-adapter/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Charcoal\Mailer\Agents\MailGun;

use Charcoal\Mailer\Exception\MailerException;

/**
 * Class MailGunException
 * @package Charcoal\Mailer\Agents\MailGun
 */
class MailGunException extends MailerException
{
    /**
     * @param string $message
     * @param int $code
     * @param \Throwable|null $previous
     * @param int|null $curlErrorCode
     * @param string|null $curlErrorStr
     * @param int|null $apiResponseCode
     * @param string|null $apiResponseMsg
     */
    public function __construct(
        string                  $message = "",
        int                     $code = 0,
        ?\Throwable             $previous = null,
        public readonly ?int    $curlErrorCode = null,
        public readonly ?string $curlErrorStr = null,
        public readonly ?int    $apiResponseCode = null,
        public readonly ?string $apiResponseMsg = null
    )
    {
        parent::__construct($message, $code, $previous);
    }
}
