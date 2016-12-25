<?php
namespace TJ;

use Exception;
use Maknz\Slack\Client as Slack;
use Psr\Log\LoggerInterface;

/**
 * Send user-reported misprints to your Slack channel
 */
class Newrphus
{
    /**
     * Headers where we should search for IP
     *
     * @var array
     */
    public $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];

    /**
     * How many misprints will be accepted from one IP address
     * per 10 minutes before it will be banned
     *
     * @var int
     */
    public $attemptsThreshold = 10;

    /**
     * Color of attachments field in Slack message
     *
     * @var string
     */
    public $color = '#cccccc';

    /**
     * List of Slack settings
     *
     * - string  $endpoint  required  Slack hook endpoint
     * - string  $channel   optional  Slack channel
     *
     * @var array
     */
    protected $slackSettings;

    /**
     * Slack notification text
     *
     * @var string
     */
    protected $notificationText;

    /**
     * Slack message text
     *
     * @var string
     */
    protected $messageText;

    /**
     * Slack message fields
     *
     * @var array
     */
    protected $fields = [];

    /**
     * Memcached instance
     *
     * @var Memcached
     */
    protected $memcached;

    /**
     * Logger instance
     *
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Slack options setter
     *
     * @param array $slackSettings e.g. [ 'endpoint' => 'https://hook.slack.com/...', 'channel' => '#misprints' ]
     *
     * @return Newrphus
     */
    public function setSlackSettings($slackSettings)
    {
        $this->slackSettings = $slackSettings;

        return $this;
    }

    /**
     * PSR-3 compatible logger setter
     *
     * @param LoggerInterface $logger
     *
     * @return Newrphus
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * Memcached setter
     *
     *
     * @param Memcached $memcached
     *
     * @return Newrphus
     */
    public function setMemcached($memcached)
    {
        $this->memcached = $memcached;

        return $this;
    }

    /**
     * Slack mesage text setter
     *
     * @param string $messageText
     *
     * @return Newrphus
     */
    public function setMessageText($messageText)
    {
        $this->messageText = $messageText;

        return $this;
    }

    /**
     * Custom Slack notification text setter
     *
     * @param string $notificationText
     *
     * @return Newrphus
     */
    public function setNotificationText($notificationText)
    {
        $this->notificationText = $notificationText;

        return $this;
    }

    /**
     * Add custom field to Slack message
     *
     * @param string $title
     * @param string $value
     * @param bool   $short
     *
     * @return Newrphus
     */
    public function addField($title, $value, $short = false)
    {
        array_push($this->fields, [
            'title' => $title,
            'value' => $value,
            'short' => (bool) $short
        ]);

        return $this;
    }

    /**
     * Report about misprint
     *
     * @param  string  misprintText
     * @param  string  misprintUrl
     *
     * @return bool
     */
    public function report($misprintText, $misprintUrl = null)
    {
        try {
            $this->floodProtect(md5($misprintText));

            return $this->sendToSlack($misprintText, $misprintUrl);
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->debug('Newrphus: antiflood: ' . $e->getMessage());
            }

            return false;
        }
    }

    /**
     * Flood protection with Memcached
     *
     * @param string $misprintHash
     *
     * @throws Exception if report is flood-positive
     *
     * @return bool
     */
    protected function floodProtect($misprintHash)
    {
        if (!$this->memcached) {
            return false;
        }

        $ip = $this->getIP();

        if ($ip !== false) {
            $mcIpHash = 'newrphus:byIP:' . md5($ip);
            $attemptsCount = $this->memcached->get($mcIpHash);
            if ($this->memcached->getResultCode() === 0) {
                if ($attemptsCount > $this->attemptsThreshold) {
                    throw new Exception("Too many attempts", 429);
                }
                $this->memcached->increment($mcIpHash);
            } else {
                $this->memcached->set($mcIpHash, 1, 300);
            }
        }

        $mcTextHash = 'newrphus:byText:' . $misprintHash;
        $this->memcached->get($mcTextHash);
        if ($this->memcached->getResultCode() === 0) {
            throw new Exception("This misprint already was sent", 202);
        }

        $this->memcached->set($mcTextHash, true, 300);

        return true;
    }

    /**
     * Get user IP address
     *
     * @return string|bool
     */
    protected function getIP()
    {
        foreach ($this->headers as $header) {
            if (array_key_exists($header, $_SERVER)) {
                if (filter_var($_SERVER[$header], FILTER_VALIDATE_IP)) {
                    return $_SERVER[$header];
                }
            }
        }

        return false;
    }

    /**
     * Send misprint report to Slack
     *
     * @param string $misprintText
     * @param string $misprintUrl
     *
     * @return bool
     */
    protected function sendToSlack($misprintText, $misprintUrl)
    {
        if (empty($misprintText)) {
            return false;
        }

        if (!isset($this->slackSettings['endpoint'])) {
            if ($this->logger) {
                $this->logger->error('Newrphus: you should set endpoint in Slack settings');
            }

            return false;
        }

        // Slack config
        $config = [
            'username' => 'Newrphus',
            'link_names' => true
        ];

        if (isset($this->slackSettings['channel'])) {
            $config['channel'] = $this->slackSettings['channel'];
        }

        $misprintText = trim(mb_substr($misprintText, 0, 1000));

        if ($this->messageText) {
            $text = $this->messageText;
        } else {
            $text = $misprintText;

            if (!empty($misprintUrl)) {
                $text .= "\n<{$misprintUrl}|Link>";
            }
        }

        if ($this->notificationText) {
            $fallback = $this->notificationText;
        } else {
            $fallback = $misprintText;
        }

        try {
            $slack = new Slack($this->slackSettings['endpoint'], $config);

            if (count($this->fields)) {
                $slack = $slack->attach([
                    'fallback' => $fallback,
                    'color' => $this->color,
                    'fields' => $this->fields
                ]);
            }

            $slack->send($text);

            return true;
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Newrphus: exception while sending misprint', ['exception' => $e]);
            }
        }

        return false;
    }
}
