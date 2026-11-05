<?php

declare(strict_types=1);

function factoryLog(PDO $pdo, string $ownerId, string $action, ?string $username = null, ?string $detail = null): void
{
    $stmt = $pdo->prepare('INSERT INTO factory_event (owner_id, action, bot_username, detail) VALUES (?, ?, ?, ?)');
    $stmt->execute([$ownerId, $action, $username, $detail]);
}
