<?php

declare(strict_types=1);

namespace NoFrixion\WC\Exception;

class BadRequestException extends RequestException
{
    public const STATUS = 400;
}
