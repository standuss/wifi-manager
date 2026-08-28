<?php

declare(strict_types=1);

namespace WifiManager\RouterOS;

final class RouterOsException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?string $category = null)
    {
        parent::__construct($message);
    }
}

