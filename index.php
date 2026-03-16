<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

header('Content-Type: text/plain; charset=UTF-8');

echo "Fujira Manager is installed.\n";
echo "Next steps:\n";
echo "1. Edit app/config.php\n";
echo "2. Import sql/schema.sql\n";
echo "3. Configure LINE webhook to /fujira-manager/webhook.php\n";
