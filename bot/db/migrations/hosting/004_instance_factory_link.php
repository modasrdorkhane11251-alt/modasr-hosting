<?php

declare(strict_types=1);

return static function (PDO $pdo): void {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM hosting_bot_instance LIKE 'factory_bot_id'");
    $stmt->execute();
    if (!$stmt->fetch()) {
        $pdo->exec('ALTER TABLE hosting_bot_instance ADD factory_bot_id INT UNSIGNED NULL AFTER product_version');
        $pdo->exec('ALTER TABLE hosting_bot_instance ADD KEY idx_hosting_instance_factory (factory_bot_id)');
    }
};
