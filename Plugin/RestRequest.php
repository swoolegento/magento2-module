<?php

namespace Swoolegento\Cli\Plugin;

use Magento\Framework\Webapi\Rest\Request;

/**
 * Plugin to get post body for Swoole as reading php://input doesn't work
 */
class RestRequest
{
    /**
     * @param Request $subject
     * @return void
     */
    public function beforeGetBodyParams(Request $subject)
    {
        if (!empty($GLOBALS['HTTP_RAW_POST_DATA'])) {
            $subject->setContent($GLOBALS['HTTP_RAW_POST_DATA']);
        }
    }
}
