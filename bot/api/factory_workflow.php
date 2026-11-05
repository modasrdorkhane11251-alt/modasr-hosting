<?php

declare(strict_types=1);

function factoryWorkflowEnsureSchema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS factory_flow (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            owner_id VARCHAR(64) NOT NULL,
            name VARCHAR(120) NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT "draft",
            active_version_id INT UNSIGNED NULL,
            draft_json LONGTEXT NOT NULL,
            published_json LONGTEXT NULL,
            validation_json LONGTEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_flow_owner_name (owner_id,name),
            KEY ix_flow_owner_status (owner_id,status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS factory_flow_version (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            flow_id INT UNSIGNED NOT NULL,
            version_number INT UNSIGNED NOT NULL,
            manifest_hash CHAR(64) NOT NULL,
            content_json LONGTEXT NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT "published",
            created_by VARCHAR(64) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_flow_version (flow_id,version_number),
            UNIQUE KEY uq_flow_manifest (flow_id,manifest_hash),
            KEY ix_flow_version_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS factory_execution (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            flow_id INT UNSIGNED NOT NULL,
            version_id INT UNSIGNED NOT NULL,
            owner_id VARCHAR(64) NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT "running",
            current_node VARCHAR(64) NULL,
            context_json LONGTEXT NULL,
            attempt TINYINT UNSIGNED NOT NULL DEFAULT 1,
            dedupe_key VARCHAR(190) NOT NULL,
            error VARCHAR(255) NULL,
            started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            finished_at TIMESTAMP NULL DEFAULT NULL,
            UNIQUE KEY uq_execution_dedupe (dedupe_key),
            KEY ix_execution_owner (owner_id,status),
            KEY ix_execution_flow (flow_id,status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS factory_job (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            execution_id BIGINT UNSIGNED NOT NULL,
            node_key VARCHAR(64) NOT NULL,
            status VARCHAR(24) NOT NULL DEFAULT "scheduled",
            run_after BIGINT NOT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
            last_error VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY ix_job_due (status,run_after)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function factoryDefaultWorkflow(string $name): array
{
    return [
        'schema_version' => '1.0',
        'workflow' => [
            'name' => $name,
            'version' => 1,
            'start_node' => 'start',
            'nodes' => [
                ['key' => 'start', 'type' => 'start', 'name' => 'Start', 'config' => ['event_type' => 'manual']],
                ['key' => 'message', 'type' => 'message', 'name' => 'Message', 'config' => ['text' => 'ورک‌فلو ساخته شد', 'parse_mode' => 'HTML']],
                ['key' => 'condition', 'type' => 'condition', 'name' => 'Check user', 'config' => ['field' => 'user_id', 'operator' => 'not_empty', 'value' => '1']],
                ['key' => 'database', 'type' => 'database', 'name' => 'Count bots', 'config' => ['operation' => 'count_bots']],
                ['key' => 'delay', 'type' => 'delay', 'name' => 'Delay', 'config' => ['seconds' => 2]],
                ['key' => 'end', 'type' => 'end', 'name' => 'End', 'config' => ['text' => 'ورک‌فلو با موفقیت اجرا شد.']],
            ],
            'connections' => [
                ['from_node' => 'start', 'to_node' => 'message', 'condition_key' => 'always'],
                ['from_node' => 'message', 'to_node' => 'condition', 'condition_key' => 'always'],
                ['from_node' => 'condition', 'to_node' => 'database', 'condition_key' => 'always'],
                ['from_node' => 'database', 'to_node' => 'delay', 'condition_key' => 'always'],
                ['from_node' => 'delay', 'to_node' => 'end', 'condition_key' => 'always'],
            ],
            'limits' => ['max_steps' => 20, 'max_execution_ms' => 5000, 'max_payload_bytes' => 65536],
        ],
    ];
}

function factoryWorkflowTemplate(string $template, string $name): array
{
    $flow = factoryDefaultWorkflow($name);
    $workflow = &$flow['workflow'];

    if ($template === 'welcome') {
        $workflow['nodes'] = [
            ['key' => 'start', 'type' => 'start', 'name' => 'Start', 'config' => ['event_type' => 'manual']],
            ['key' => 'welcome', 'type' => 'message', 'name' => 'Welcome', 'config' => ['text' => 'به MODASR Hosting خوش آمدید.', 'parse_mode' => 'HTML', 'send_now' => false]],
            ['key' => 'end', 'type' => 'end', 'name' => 'End', 'config' => ['text' => 'پیام خوش‌آمد آماده است.']],
        ];
        $workflow['connections'] = [
            ['from_node' => 'start', 'to_node' => 'welcome', 'condition_key' => 'always'],
            ['from_node' => 'welcome', 'to_node' => 'end', 'condition_key' => 'always'],
        ];
        return $flow;
    }

    if ($template === 'report') {
        $workflow['nodes'] = [
            ['key' => 'start', 'type' => 'start', 'name' => 'Start', 'config' => ['event_type' => 'manual']],
            ['key' => 'orders', 'type' => 'database', 'name' => 'Count orders', 'config' => ['operation' => 'count_orders']],
            ['key' => 'message', 'type' => 'message', 'name' => 'Report', 'config' => ['text' => 'گزارش سفارش‌ها از دیتابیس خوانده شد.', 'send_now' => false]],
            ['key' => 'end', 'type' => 'end', 'name' => 'End', 'config' => ['text' => 'گزارش آماده است.']],
        ];
        $workflow['connections'] = [
            ['from_node' => 'start', 'to_node' => 'orders', 'condition_key' => 'always'],
            ['from_node' => 'orders', 'to_node' => 'message', 'condition_key' => 'always'],
            ['from_node' => 'message', 'to_node' => 'end', 'condition_key' => 'always'],
        ];
        return $flow;
    }

    if ($template === 'followup') {
        $workflow['nodes'] = [
            ['key' => 'start', 'type' => 'start', 'name' => 'Start', 'config' => ['event_type' => 'manual']],
            ['key' => 'message', 'type' => 'message', 'name' => 'Follow up', 'config' => ['text' => 'پیام پیگیری آماده شد.', 'send_now' => false]],
            ['key' => 'delay', 'type' => 'delay', 'name' => 'Delay', 'config' => ['seconds' => 5]],
            ['key' => 'log', 'type' => 'action', 'name' => 'Log event', 'config' => ['operation' => 'log_event']],
            ['key' => 'end', 'type' => 'end', 'name' => 'End', 'config' => ['text' => 'پیگیری زمان‌دار کامل شد.']],
        ];
        $workflow['connections'] = [
            ['from_node' => 'start', 'to_node' => 'message', 'condition_key' => 'always'],
            ['from_node' => 'message', 'to_node' => 'delay', 'condition_key' => 'always'],
            ['from_node' => 'delay', 'to_node' => 'log', 'condition_key' => 'always'],
            ['from_node' => 'log', 'to_node' => 'end', 'condition_key' => 'always'],
        ];
        return $flow;
    }

    throw new RuntimeException('template_not_found');
}

function factoryWorkflowGet(PDO $pdo, int $flowId, string $ownerId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM factory_flow WHERE id = ? AND owner_id = ?');
    $stmt->execute([$flowId, $ownerId]);
    $flow = $stmt->fetch();
    return is_array($flow) ? $flow : null;
}

function factoryWorkflowValidate(array $flow): array
{
    $errors = [];
    $workflow = $flow['workflow'] ?? null;
    if (!is_array($workflow)) {
        return [false, ['workflow_missing']];
    }
    $nodes = $workflow['nodes'] ?? [];
    $connections = $workflow['connections'] ?? [];
    if (!is_array($nodes) || count($nodes) < 2) {
        $errors[] = 'nodes_required';
    }
    if (!is_array($connections)) {
        $errors[] = 'connections_required';
    }
    $allowed = ['start', 'message', 'condition', 'delay', 'api', 'action', 'database', 'end', 'button', 'form'];
    $map = [];
    foreach ($nodes as $node) {
        $key = (string) ($node['key'] ?? '');
        $type = (string) ($node['type'] ?? '');
        if ($key === '' || !preg_match('/^[a-z0-9_-]+$/', $key)) {
            $errors[] = 'node_key_invalid';
        }
        if (isset($map[$key])) {
            $errors[] = 'duplicate_node_key_' . $key;
        }
        $map[$key] = $node;
        if (!in_array($type, $allowed, true)) {
            $errors[] = 'node_type_denied_' . $type;
        }
        $config = $node['config'] ?? [];
        if (!is_array($config)) {
            $errors[] = 'node_config_invalid_' . $key;
        }
        if ($type === 'message' && trim((string) ($config['text'] ?? '')) === '') {
            $errors[] = 'message_empty_' . $key;
        }
        if ($type === 'delay') {
            $seconds = (int) ($config['seconds'] ?? 0);
            if ($seconds < 0 || $seconds > 60) {
                $errors[] = 'delay_invalid_' . $key;
            }
        }
        if ($type === 'condition') {
            $operator = (string) ($config['operator'] ?? '');
            if (!in_array($operator, ['eq', 'neq', 'contains', 'exists', 'not_empty', 'empty'], true)) {
                $errors[] = 'condition_operator_invalid_' . $key;
            }
            if (trim((string) ($config['field'] ?? '')) === '') {
                $errors[] = 'condition_field_required_' . $key;
            }
        }
        if ($type === 'database' && !in_array((string) ($config['operation'] ?? ''), ['count_bots', 'count_orders', 'count_customers'], true)) {
            $errors[] = 'database_operation_denied_' . $key;
        }
        if ($type === 'api' && !in_array((string) ($config['operation'] ?? ''), ['get_bot_status', 'get_catalog_count'], true)) {
            $errors[] = 'api_operation_denied_' . $key;
        }
        if ($type === 'action' && !in_array((string) ($config['operation'] ?? ''), ['log_event', 'send_message', 'stop_bot', 'start_bot'], true)) {
            $errors[] = 'action_operation_denied_' . $key;
        }
        if ($type === 'start' && ($config['event_type'] ?? '') !== 'manual') {
            $errors[] = 'start_event_invalid_' . $key;
        }
        if ($type === 'end' && trim((string) ($config['text'] ?? '')) === '') {
            $errors[] = 'end_text_required_' . $key;
        }
    }
    if (!isset($map[$workflow['start_node'] ?? ''])) {
        $errors[] = 'start_node_missing';
    }
    foreach ($connections as $edge) {
        if (!isset($map[$edge['from_node'] ?? '']) || !isset($map[$edge['to_node'] ?? ''])) {
            $errors[] = 'connection_node_missing';
        }
    }
    $start = (string) ($workflow['start_node'] ?? '');
    $visiting = [];
    $visited = [];
    $walk = static function (string $nodeKey) use (&$walk, &$visiting, &$visited, $map, $connections): bool {
        if (isset($visiting[$nodeKey])) {
            return true;
        }
        if (isset($visited[$nodeKey])) {
            return false;
        }
        $visiting[$nodeKey] = true;
        foreach ($connections as $edge) {
            if (($edge['from_node'] ?? '') === $nodeKey) {
                if ($walk((string) $edge['to_node'])) {
                    return true;
                }
            }
        }
        $visited[$nodeKey] = true;
        return false;
    };
    if ($start !== '' && $walk($start)) {
        $errors[] = 'cycle_detected';
    }
    return [count($errors) === 0, $errors];
}

function factoryWorkflowCondition(array $context, array $config): bool
{
    $field = (string) ($config['field'] ?? '');
    $operator = (string) ($config['operator'] ?? '');
    $expected = (string) ($config['value'] ?? '');
    $value = $context[$field] ?? null;
    return match ($operator) {
        'eq' => (string) $value === $expected,
        'neq' => (string) $value !== $expected,
        'contains' => str_contains((string) $value, $expected),
        'exists' => $value !== null,
        'not_empty' => $value !== null && $value !== '',
        'empty' => $value === null || $value === '',
        default => false,
    };
}

function factoryWorkflowNext(array $workflow, string $nodeKey, array $context): ?string
{
    $condition = $context['condition'] ?? true;
    $fallback = null;
    foreach ($workflow['workflow']['connections'] as $edge) {
        if (($edge['from_node'] ?? '') !== $nodeKey) {
            continue;
        }
        $key = (string) ($edge['condition_key'] ?? 'always');
        if ($key === 'always' || ($key === 'true' && $condition) || ($key === 'false' && !$condition)) {
            return (string) $edge['to_node'];
        }
        $fallback ??= (string) $edge['to_node'];
    }
    return $fallback;
}

function factoryWorkflowExecuteNode(PDO $pdo, array $node, array &$context, int $executionId, bool $preview): string
{
    $type = (string) $node['type'];
    $config = $node['config'] ?? [];
    switch ($type) {
        case 'start':
            $context['log'][] = 'start:manual';
            return 'continue';
        case 'message':
            $text = trim((string) ($config['text'] ?? ''));
            if ($text === '') {
                throw new RuntimeException('message_empty');
            }
            $context['response'] = $text;
            $context['log'][] = 'message:' . $text;
            if (!$preview && !empty($config['send_now']) && !empty($context['chat_id'])) {
                factoryReply($context['chat_id'], $text, factoryMenu());
            }
            return 'continue';
        case 'condition':
            $context['condition'] = factoryWorkflowCondition($context, $config);
            $context['log'][] = 'condition:' . ($context['condition'] ? 'true' : 'false');
            return 'continue';
        case 'database':
            $operation = (string) ($config['operation'] ?? '');
            $context['db_result'] = match ($operation) {
                'count_bots' => (int) $pdo->query('SELECT COUNT(*) FROM factory_bot')->fetchColumn(),
                'count_orders' => (int) $pdo->query('SELECT COUNT(*) FROM factory_order')->fetchColumn(),
                'count_customers' => (int) $pdo->query('SELECT COUNT(*) FROM factory_customer')->fetchColumn(),
                default => throw new RuntimeException('database_operation_denied'),
            };
            $context['log'][] = 'database:' . $operation . '=' . $context['db_result'];
            return 'continue';
        case 'delay':
            $seconds = (int) ($config['seconds'] ?? 0);
            if ($seconds < 0 || $seconds > 60) {
                throw new RuntimeException('delay_invalid');
            }
            if ($preview) {
                $context['log'][] = 'delay:' . $seconds;
                return 'continue';
            }
            $stmt = $pdo->prepare('INSERT INTO factory_job (execution_id,node_key,status,run_after) VALUES (?, ?, "scheduled", ?)');
            $stmt->execute([$executionId, (string) $node['key'], time() + $seconds]);
            $context['log'][] = 'delayed:' . $seconds;
            return 'delayed';
        case 'api':
            $operation = (string) ($config['operation'] ?? '');
            $context['api_result'] = match ($operation) {
                'get_bot_status' => trim((string) shell_exec('systemctl is-active modasr-hosting-full-php')),
                'get_catalog_count' => (int) $pdo->query('SELECT COUNT(*) FROM product WHERE status_product != "disable"')->fetchColumn(),
                default => throw new RuntimeException('api_operation_denied'),
            };
            $context['log'][] = 'api:' . $operation . '=' . $context['api_result'];
            return 'continue';
        case 'action':
            $operation = (string) ($config['operation'] ?? '');
            if ($operation === 'log_event') {
                $context['log'][] = 'action:log_event';
                return 'continue';
            }
            if ($operation === 'send_message') {
                if (!$preview && !empty($context['chat_id'])) {
                    factoryReply($context['chat_id'], (string) ($config['text'] ?? 'Action executed'), factoryMenu());
                }
                $context['log'][] = 'action:send_message';
                return 'continue';
            }
            if (in_array($operation, ['stop_bot', 'start_bot'], true)) {
                if (!$preview) {
                    $botId = (int) ($config['bot_id'] ?? 0);
                    if ($botId <= 0) {
                        throw new RuntimeException('bot_id_invalid');
                    }
                    factoryRuntimeAction($pdo, $botId, $operation === 'stop_bot' ? 'stop' : 'start', (string) $context['user_id']);
                }
                $context['log'][] = 'action:' . $operation;
                return 'continue';
            }
            throw new RuntimeException('action_operation_denied');
        case 'end':
            $context['response'] = (string) ($config['text'] ?? 'End');
            $context['log'][] = 'end:' . $context['response'];
            return 'stop';
        default:
            throw new RuntimeException('node_type_denied_' . $type);
    }
}

function factoryWorkflowExecute(PDO $pdo, int $flowId, string $ownerId, string $updateId, bool $preview = false, ?int $resumeExecutionId = null): array
{
    $flow = factoryWorkflowGet($pdo, $flowId, $ownerId);
    if (!$flow) {
        throw new RuntimeException('flow_not_found');
    }
    $versionId = (int) ($flow['active_version_id'] ?? 0);
    if ($preview && $versionId <= 0) {
        $version = ['id' => 0, 'content_json' => $flow['draft_json']];
    } else {
        if ($versionId <= 0) {
            throw new RuntimeException('flow_not_published');
        }
        $stmt = $pdo->prepare('SELECT * FROM factory_flow_version WHERE id = ? AND flow_id = ?');
        $stmt->execute([$versionId, $flowId]);
        $version = $stmt->fetch();
        if (!$version) {
            throw new RuntimeException('flow_version_not_found');
        }
    }
    $workflow = json_decode((string) $version['content_json'], true);
    if (!is_array($workflow)) {
        throw new RuntimeException('flow_invalid_json');
    }
    [$valid, $errors] = factoryWorkflowValidate($workflow);
    if (!$valid) {
        throw new RuntimeException('flow_validation_failed:' . implode(',', $errors));
    }

    if ($resumeExecutionId !== null) {
        $resumeStmt = $pdo->prepare('SELECT id,current_node,context_json FROM factory_execution WHERE id = ? AND owner_id = ?');
        $resumeStmt->execute([$resumeExecutionId, $ownerId]);
        $existing = $resumeStmt->fetch();
        if (!$existing) {
            throw new RuntimeException('execution_not_found');
        }
        $context = json_decode((string) ($existing['context_json'] ?? '{}'), true) ?: [];
        $executionId = $resumeExecutionId;
        $current = factoryWorkflowNext($workflow, (string) ($existing['current_node'] ?? ''), $context) ?? '';
    } else {
        $dedupeKey = $flowId . '|' . $versionId . '|' . $updateId;
        $stmt = $pdo->prepare('SELECT id,status,context_json FROM factory_execution WHERE dedupe_key = ?');
        $stmt->execute([$dedupeKey]);
        $existing = $stmt->fetch();
        if ($existing) {
            $context = json_decode((string) ($existing['context_json'] ?? '{}'), true) ?: [];
            return ['status' => 'duplicate', 'execution_id' => (int) $existing['id'], 'context' => $context];
        }

        $context = [
            'user_id' => $ownerId,
            'chat_id' => $ownerId,
            'condition' => true,
            'log' => [],
            'response' => '',
        ];
        $stmt = $pdo->prepare('INSERT INTO factory_execution (flow_id,version_id,owner_id,status,current_node,context_json,dedupe_key) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$flowId, $versionId, $ownerId, $preview ? 'preview' : 'running', $workflow['workflow']['start_node'], json_encode($context, JSON_UNESCAPED_UNICODE), $dedupeKey]);
        $executionId = (int) $pdo->lastInsertId();
        $context['execution_id'] = $executionId;
        $current = (string) $workflow['workflow']['start_node'];
    }
    $steps = 0;
    try {
        while ($current !== '' && $steps < 20) {
            $node = null;
            foreach ($workflow['workflow']['nodes'] as $candidate) {
                if (($candidate['key'] ?? '') === $current) {
                    $node = $candidate;
                    break;
                }
            }
            if ($node === null) {
                throw new RuntimeException('node_not_found_' . $current);
            }
            $result = factoryWorkflowExecuteNode($pdo, $node, $context, $executionId, $preview);
            $steps++;
            if ($result === 'stop' || $result === 'delayed') {
                break;
            }
            $current = factoryWorkflowNext($workflow, $current, $context) ?? '';
        }
        if ($steps >= 20) {
            throw new RuntimeException('max_steps_exceeded');
        }
        $status = $result === 'delayed' ? 'delayed' : 'succeeded';
        $currentNode = $result === 'delayed' ? $current : null;
        $finishedSql = $result === 'delayed' ? 'NULL' : 'CURRENT_TIMESTAMP';
        $stmt = $pdo->prepare("UPDATE factory_execution SET status = ?, current_node = ?, context_json = ?, finished_at = {$finishedSql} WHERE id = ?");
        $stmt->execute([$status, $currentNode, json_encode($context, JSON_UNESCAPED_UNICODE), $executionId]);
        return ['status' => $status, 'execution_id' => $executionId, 'context' => $context];
    } catch (Throwable $e) {
        $context['error'] = $e->getMessage();
        $stmt = $pdo->prepare('UPDATE factory_execution SET status = "failed", context_json = ?, finished_at = CURRENT_TIMESTAMP, error = ? WHERE id = ?');
        $stmt->execute([json_encode($context, JSON_UNESCAPED_UNICODE), $context['error'], $executionId]);
        throw $e;
    }
}

function factoryWorkflowPublish(PDO $pdo, int $flowId, string $ownerId): void
{
    $flow = factoryWorkflowGet($pdo, $flowId, $ownerId);
    if (!$flow) {
        throw new RuntimeException('flow_not_found');
    }
    $workflow = json_decode((string) $flow['draft_json'], true);
    if (!is_array($workflow)) {
        throw new RuntimeException('flow_invalid_json');
    }
    [$valid, $errors] = factoryWorkflowValidate($workflow);
    if (!$valid) {
        $stmt = $pdo->prepare('UPDATE factory_flow SET status = "invalid", validation_json = ? WHERE id = ?');
        $stmt->execute([json_encode($errors, JSON_UNESCAPED_UNICODE), $flowId]);
        throw new RuntimeException('flow_validation_failed:' . implode(',', $errors));
    }
    $hash = hash('sha256', (string) $flow['draft_json']);
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(version_number), 0) + 1 FROM factory_flow_version WHERE flow_id = ?');
    $stmt->execute([$flowId]);
    $versionNumber = (int) $stmt->fetchColumn();
    $stmt = $pdo->prepare('INSERT INTO factory_flow_version (flow_id,version_number,manifest_hash,content_json,status,created_by) VALUES (?, ?, ?, ?, "published", ?)');
    $stmt->execute([$flowId, $versionNumber, $hash, $flow['draft_json'], $ownerId]);
    $versionId = (int) $pdo->lastInsertId();
    $stmt = $pdo->prepare('UPDATE factory_flow SET active_version_id = ?, published_json = draft_json, status = "published", validation_json = NULL WHERE id = ?');
    $stmt->execute([$versionId, $flowId]);
    factoryLog($pdo, $ownerId, 'workflow_published', null, 'flow=' . $flowId . ',version=' . $versionNumber);
}

function factoryWorkflowRollback(PDO $pdo, int $flowId, string $ownerId): void
{
    $flow = factoryWorkflowGet($pdo, $flowId, $ownerId);
    if (!$flow) {
        throw new RuntimeException('flow_not_found');
    }
    $activeId = (int) ($flow['active_version_id'] ?? 0);
    if ($activeId <= 0) {
        throw new RuntimeException('flow_not_published');
    }
    $stmt = $pdo->prepare('SELECT version_number FROM factory_flow_version WHERE id = ?');
    $stmt->execute([$activeId]);
    $activeVersionNumber = (int) $stmt->fetchColumn();
    $stmt = $pdo->prepare('SELECT id,version_number FROM factory_flow_version WHERE flow_id = ? AND version_number < ? ORDER BY version_number DESC LIMIT 1');
    $stmt->execute([$flowId, $activeVersionNumber]);
    $previous = $stmt->fetch();
    if (!$previous) {
        throw new RuntimeException('no_previous_version');
    }
    $stmt = $pdo->prepare('UPDATE factory_flow SET active_version_id = ?, status = "rollback" WHERE id = ?');
    $stmt->execute([(int) $previous['id'], $flowId]);
    factoryLog($pdo, $ownerId, 'workflow_rollback', null, 'flow=' . $flowId . ',to=' . $previous['version_number']);
}

function factoryWorkflowArchive(PDO $pdo, int $flowId, string $ownerId): void
{
    $stmt = $pdo->prepare('UPDATE factory_flow SET status = "archived", active_version_id = NULL WHERE id = ? AND owner_id = ?');
    $stmt->execute([$flowId, $ownerId]);
    factoryLog($pdo, $ownerId, 'workflow_archived', null, 'flow=' . $flowId);
}

function factoryWorkflowDueJobs(PDO $pdo, string $ownerId): array
{
    $stmt = $pdo->prepare('SELECT j.*, e.flow_id, e.version_id, e.owner_id FROM factory_job j JOIN factory_execution e ON e.id = j.execution_id WHERE j.status = "scheduled" AND j.run_after <= ? AND e.owner_id = ? ORDER BY j.id ASC');
    $stmt->execute([time(), $ownerId]);
    $jobs = $stmt->fetchAll();
    $completed = [];
    foreach ($jobs as $job) {
        $result = factoryWorkflowExecute($pdo, (int) $job['flow_id'], $ownerId, 'job-' . $job['id'], false, (int) $job['execution_id']);
        if ($result['status'] === 'succeeded') {
            $stmt = $pdo->prepare('UPDATE factory_job SET status = "completed", attempts = attempts + 1 WHERE id = ?');
            $stmt->execute([(int) $job['id']]);
        }
        $completed[] = $result;
        factoryLog($pdo, $ownerId, 'workflow_job_completed', null, 'execution=' . $job['execution_id']);
    }
    return $completed;
}
