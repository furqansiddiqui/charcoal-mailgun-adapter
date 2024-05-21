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

use Charcoal\Mailer\Agents\MailerAgentInterface;
use Charcoal\Mailer\Exception\EmailComposeException;
use Charcoal\Mailer\Message;
use Charcoal\Mailer\Message\CompiledMimeMessage;

/**
 * Class MailGunAdapter
 * @package Charcoal\Mailer\Agents\MailGun
 */
class MailGunAdapter implements MailerAgentInterface
{
    public readonly string $apiServerUrl;
    public bool $builtInMIME = true;
    public bool $sendIndividually = false;
    public bool $throwOnIndividualSend = false;

    /**
     * @param string $domain
     * @param string $apiKey
     * @param bool $euServer
     * @param string $caRootFile
     * @param int $timeOut
     * @param int $connectTimeout
     * @throws \Charcoal\Mailer\Agents\MailGun\MailGunException
     */
    public function __construct(
        public readonly string $domain,
        public readonly string $apiKey,
        public readonly bool   $euServer,
        public readonly string $caRootFile,
        public int             $timeOut = 3,
        public int             $connectTimeout = 3,
    )
    {
        $this->apiServerUrl = $this->euServer ? "https://api.eu.mailgun.net/v3/" . $this->domain :
            "https://api.mailgun.net/v3/" . $this->domain;

        if (!is_file($this->caRootFile) || !is_readable($this->caRootFile)) {
            throw new MailGunException("SSL/TLS CA root file is not readable or does not exist");
        }
    }

    /**
     * @return array
     */
    public function __debugInfo(): array
    {
        return [
            "domain" => $this->domain,
            "apiKey" => str_repeat("*", strlen($this->apiKey)),
            "apiServer" => $this->apiServerUrl,
        ];
    }

    /**
     * @param \Charcoal\Mailer\Message|\Charcoal\Mailer\Message\CompiledMimeMessage $message
     * @param array $recipients
     * @return int
     * @throws \Charcoal\Mailer\Agents\MailGun\MailGunException
     * @throws \Charcoal\Mailer\Exception\EmailComposeException
     */
    public function send(Message|CompiledMimeMessage $message, array $recipients): int
    {
        $multipartFormData = false;
        if ($this->builtInMIME || $message instanceof CompiledMimeMessage) {
            $multipartFormData = true;
            $payload["message"] = new \CURLStringFile(
                $message instanceof Message ? $message->compile()->compiledMimeBody : $message->compiledMimeBody,
                "message"
            );
        } else {
            $payload["from"] = sprintf("%s <%s>", $message->sender->name, $message->sender->email);
            $payload["subject"] = $message->subject;
            if ($message->body->plainText) {
                $payload["text"] = $message->body->plainText;
            }

            if ($message->body->html) {
                $payload["html"] = $message->body->html;
            }

            $attachments = $message->getAttachments();
            if ($attachments) {
                $attachmentsCount = 0;
                $inlinesCount = 0;

                /** @var \Charcoal\Mailer\Message\Attachment $attachment */
                foreach ($attachments as $attachment) {
                    switch ($attachment->disposition) {
                        case "attachment":
                            $payload["attachment[" . $attachmentsCount . "]"] = new \CURLFile($attachment->filePath, $attachment->contentType, $attachment->name);
                            $attachmentsCount++;
                            break;
                        case "inline":
                            $payload["inline[" . $inlinesCount . "]"] = new \CURLFile($attachment->filePath, $attachment->contentType, $attachment->name);
                            $inlinesCount++;
                            break;
                        default:
                            throw new EmailComposeException('Illegal value for attachment disposition');
                    }
                }

                if (($attachmentsCount + $inlinesCount) > 0) {
                    $multipartFormData = true;
                }
            }
        }

        $sentCount = 0;
        if ($this->sendIndividually) {
            foreach ($recipients as $recipient) {
                try {
                    $this->sendIndividual($recipient, $payload, $multipartFormData);
                    $sentCount++;
                } catch (\Exception $e) {
                    if ($this->throwOnIndividualSend) {
                        throw $e;
                    }
                }
            }

            return $sentCount;
        }

        if ($multipartFormData) {
            $recipientsCount = 0;
            foreach ($recipients as $recipient) {
                $payload["to[" . $recipientsCount . "]"] = $recipient;
                $recipientsCount++;
            }
        } else {
            $payload["to"] = $recipients;
        }

        $this->apiCall("post", $this->builtInMIME ? "/messages.mime" : "/messages", $payload, fileUpload: $multipartFormData);
        return 1;
    }

    /**
     * @param string $to
     * @param array $payload
     * @param bool $multipartFormData
     * @return void
     * @throws \Charcoal\Mailer\Agents\MailGun\MailGunException
     */
    private function sendIndividual(string $to, array $payload, bool $multipartFormData): void
    {
        $payload["to"] = $to;
        $endpoint = $this->builtInMIME ? "/messages.mime" : "/messages";
        $this->apiCall("post", $endpoint, $payload, fileUpload: $multipartFormData);
    }

    /**
     * @param string $method
     * @param string $endpoint
     * @param array $payload
     * @param bool $fileUpload
     * @return array|string
     * @throws \Charcoal\Mailer\Agents\MailGun\MailGunException
     */
    public function apiCall(string $method, string $endpoint, array $payload, bool $fileUpload = false): array|string
    {
        $apiQueryURL = $this->apiServerUrl . $endpoint;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiQueryURL);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CAINFO, $this->caRootFile);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeOut);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, "api:" . $this->apiKey);

        if (strtolower($method) === "get") {
            curl_setopt($ch, CURLOPT_HTTPGET, 1);
            if ($payload) {
                curl_setopt($ch, CURLOPT_URL, $apiQueryURL . strpos("?", $apiQueryURL) ? "&" : "?" . http_build_query($payload));
            }
        } else {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
            if ($payload) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "Content-type: " . $fileUpload ? "multipart/form-data" : "application/x-www-form-urlencoded"
                ]);

                curl_setopt($ch, CURLOPT_POSTFIELDS, $fileUpload ? $payload : http_build_query($payload));
            }
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $response = curl_exec($ch);
        if (false === $response) {
            throw new MailGunException(
                sprintf('MailGun cURL request %s %s failed', strtoupper($method), $endpoint),
                curlErrorCode: curl_errno($ch),
                curlErrorStr: curl_error($ch)
            );
        }

        $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $responseType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if (explode(";", $responseType ?? "")[0] === "application/json") {
            $response = json_decode($response, true);
        }

        if ($responseCode !== 200) {
            throw new MailGunException(
                sprintf('MailGun API call to %s %s failed', strtoupper($method), $endpoint),
                apiResponseCode: $responseCode,
                apiResponseMsg: $response["message"] ?? null,
            );
        }

        return $response;
    }
}