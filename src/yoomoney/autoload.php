<?php

require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

define('YOOMONEY_MODULE_PATH', dirname(__FILE__));

function yooMoneyClassLoader($className)
{
    if (strncmp('YooMoney', $className, 8) === 0) {
        $length = 8;
        $path = YOOMONEY_MODULE_PATH;
    } else {
        return;
    }
    if (DIRECTORY_SEPARATOR === '/') {
        $path .= str_replace('\\', '/', substr($className, $length)) . '.php';
    } else {
        $path .= substr($className, $length) . '.php';
    }
    if (file_exists($path)) {
        require_once $path;
    }
}

spl_autoload_register('yooMoneyClassLoader');
