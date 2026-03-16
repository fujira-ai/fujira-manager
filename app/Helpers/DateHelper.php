<?php
declare(strict_types=1);

namespace FujiraManager\Helpers;

final class DateHelper
{
    public static function today(): string
    {
        return date('Y-m-d');
    }
}
