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
     * How many misprints will be accepted from one IP address before it will be banned
     *
     * @var integer
     */
    public $attemptsThreshold = 20;

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
     * Fallback text
     *
     * @var string
     */
    protected $fallback;

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
     * URL analysis function
     *
     * @var function
     */
    protected $urlAnalysis;

    /**
     * User ID analysis function
     *
     * @var function
     */
    protected $userIdAnalysis;

    /**
     * Slack options setter
     *
     * @param  array       $slackSettings e.g. [ 'endpoint' => 'https://hook.slack.com/...', 'channel' => '#misprints' ]
     * @return TJ\Newrphus
     */
    public function setSlackSettings($slackSettings)
    {
        $this->slackSettings = $slackSettings;

        return $this;
    }

    /**
     * PSR-3 compatible logger setter
     *
     * @param  LoggerInterface $logger
     * @return TJ\Newrphus
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;

        return $this;
    }

    /**
     * Memcached setter
     *
     * @param  Memcached $memcached
     * @return TJ\Newrphus
     */
    public function setMemcached($memcached)
    {
        $this->memcached = $memcached;

        return $this;
    }

    /**
     * URL analysis setter
     *
     * @param  function $function
     * @return TJ\Newrphus
     */
    public function setURLAnalysis($function)
    {
        $this->urlAnalysis = $function;

        return $this;
    }

    /**
     * User ID analysis setter
     *
     * @param  function $function
     * @return TJ\Newrphus
     */
    public function setUserIdAnalysis($function)
    {
        $this->userIdAnalysis = $function;

        return $this;
    }

    /**
     * Custom fallback text setter
     *
     * @param  string      $fallback
     * @return TJ\Newrphus
     */
    public function setCustomFallback($fallback)
    {
        $this->fallback = $fallback;

        return $this;
    }

    /**
     * Report about misprint
     *
     * @param  array misprintData
     * @return boolean
     */
    public function report($misprintData)
    {
        try {
            $this->floodProtect(md5($misprintData['misprintText']));

            $urlInfo = null;
            $userInfo = null;

            if (isset($misprintData['misprintUrl'])) {
                $urlInfo = ['title' => 'URL', 'value' => $misprintData['misprintUrl'], 'short' => true];

                if (is_callable($this->urlAnalysis)) {
                    $result = call_user_func($this->urlAnalysis, $misprintData['misprintUrl']);

                    if ($result && is_array($result) && isset($result['title']) && isset($result['value'])) {
                        $urlInfo = $result;
                    }
                }
            }

            if (isset($misprintData['misprintUserId']) && $misprintData['misprintUserId'] > 0) {
                $userInfo = ['title' => 'User ID', 'value' => $misprintData['misprintUserId'], 'short' => true];

                if (is_callable($this->userIdAnalysis)) {
                    $result = call_user_func($this->userIdAnalysis, $misprintData['misprintUserId']);

                    if ($result && is_array($result) && isset($result['title']) && isset($result['value'])) {
                        $userInfo = $result;
                    }
                }
            }

            return $this->sendToSlack([
                'urlInfo' => $urlInfo,
                'userInfo' => $userInfo,
                'misprint' => $misprintData['misprintText']
            ]);
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
     * @param  string $misprintHash
     * @throws Exception if report is flood-positive
     * @return boolean
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
     * @return string|boolean
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
     * @param  array   $data
     * @return boolean successful sending
     */
    public function sendToSlack($data)
    {
        if (!is_array($data) || !count($data)) {
            return false;
        }

        if (!isset($this->slackSettings['endpoint'])) {
            if ($this->logger) {
                $this->logger->error('Newrphus: you should set endpoint in Slack settings');
            }

            return false;
        }

        $config = [
            'username' => 'Newrphus'
        ];

        if (isset($this->slackSettings['channel'])) {
            $config['channel'] = $this->slackSettings['channel'];
        }

        $misprintText = mb_substr($data['misprint'], 0, 1000);

        $fields = [];

        if ($data['urlInfo']) {
            array_push($fields, $data['urlInfo']);
        }

        if ($data['userInfo']) {
            array_push($fields, $data['userInfo']);
        }

        if ($this->fallback) {
            $fallback = $this->fallback;
            $this->fallback = null;
        } else {
            $fallback = "{$misprintText}";
        }

        try {
            $slack = new Slack($this->slackSettings['endpoint'], $config);
            $slack->attach([
                'fallback' => $fallback,
                'color' => '#cccccc',
                'pretext' => $misprintText,
                'fields' => $fields
            ])->send();
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Newrphus: exception while sending misprint', ['exception' => $e]);
            }

            return false;
        }

        return true;
    }
}
