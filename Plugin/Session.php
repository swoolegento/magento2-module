<?php

namespace Swoolegento\Cli\Plugin;

use Magento\Framework\Session\SessionStartChecker;

/**
 * Plugin to enable sessions for Swoole as sessions are usually disabled for CLI commands
 */
class Session
{
    /**
     * @param SessionStartChecker $subject
     * @param callable $proceed
     * @return bool
     */
    public function aroundCheck(SessionStartChecker $subject, callable $proceed)
    {
        if (!empty($_SERVER['HTTP_HOST'])) {
            return true;
        }
        return $proceed();
    }
}
