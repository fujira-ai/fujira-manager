<?php
declare(strict_types=1);

namespace FujiraManager\Core;

final class AiSecretary
{
    public function handleText(string $ownerId, string $text): string
    {
        // TODO: migrate CLI logic here step by step.
        return "受け付けました: {$text}";
    }
}
