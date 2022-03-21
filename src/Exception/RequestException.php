<?php

declare(strict_types=1);

namespace NoFrixion\WC\Exception;

use NoFrixion\WC\Http\ResponseInterface;

class RequestException extends NoFrixionException
{
    public function __construct(string $method, string $url, ResponseInterface $response)
    {
        $message = 'Error during ' . $method . ' to ' . $url . '. Got response (' . $response->getStatus() . '): ' . $response->getBody();
        parent::__construct($message, $response->getStatus());
    }
}
