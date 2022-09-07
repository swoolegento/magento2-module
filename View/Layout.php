<?php

declare(strict_types=1);

namespace Swoolegento\Cli\View;

/**
 * Clear element cache when destructing layouts
 */
class Layout extends \Magento\Framework\View\Layout
{
    public function __destruct()
    {
        $this->_renderElementCache = [];
        parent::__destruct();
    }
}
