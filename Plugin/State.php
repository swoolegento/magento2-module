<?php

namespace Swoolegento\Cli\Plugin;

use Magento\Framework\Exception\LocalizedException;

/**
 * Plugin to catch exception when state is set multiple times or unset
 */
class State
{
    /**
     * @param \Magento\Framework\App\State $subject
     * @param callable $proceed
     * @return bool
     */
    public function aroundGetAreaCode(\Magento\Framework\App\State $subject, callable $proceed)
    {
        try {
            return $proceed();
        } catch (LocalizedException $e) {}
    }

    /**
     * @param \Magento\Framework\App\State $subject
     * @param callable $proceed
     * @param string $code
     * @return bool
     */
    public function aroundSetAreaCode(\Magento\Framework\App\State $subject, callable $proceed, $code)
    {
        try {
            return $proceed($code);
        } catch (LocalizedException $e) {}
    }
}
