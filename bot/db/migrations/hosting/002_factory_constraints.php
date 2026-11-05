<?php

declare(strict_types=1);

return static function (PDO $pdo): void {
    $exists = $pdo->query("SHOW TABLES LIKE 'factory_bot'")->fetchColumn();
    if ($exists === false) {
        return;
    }
    $duplicates = (int) $pdo->query('SELECT COUNT(*) FROM (SELECT order_id FROM factory_bot GROUP BY order_id HAVING COUNT(*) > 1) duplicates')->fetchColumn();
    if ($duplicates > 0) {
        throw new RuntimeException('factory_bot_order_duplicates_present');
    }
    $index = $pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'factory_bot' AND index_name = 'uniq_factory_bot_order'");
    $index->execute();
    if ((int) $index->fetchColumn() === 0) {
        $pdo->exec('ALTER TABLE factory_bot ADD UNIQUE KEY uniq_factory_bot_order (order_id)');
    }
};
