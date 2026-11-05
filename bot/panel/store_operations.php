<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/../lib/storefront_core.php';
require_once __DIR__ . '/../lib/storefront_payment.php';
require_once __DIR__ . '/../lib/storefront_card.php';
require_auth();

$view = (string) ($_GET['view'] ?? 'inventory');
if (!in_array($view, ['inventory', 'coupons', 'payments', 'receipts', 'refunds', 'reviews', 'tickets'], true)) $view = 'inventory';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check_post();
  $action = (string) ($_POST['action'] ?? '');
  try {
    if ($action === 'inventory_add') {
      $productId = (int) ($_POST['product_id'] ?? 0);
      if (!db_count($pdo, 'SELECT COUNT(*) FROM product WHERE id = ? AND inventory_enabled = 1', [$productId])) throw new RuntimeException('inventory_product');
      $secrets = preg_split('/\r\n|\r|\n/', (string) ($_POST['secrets'] ?? ''));
      $count = storefrontInventoryAdd($pdo, $productId, $secrets);
      if ($count < 1) throw new RuntimeException('inventory_empty');
      flash('success', sprintf($textbotlang['panel']['storeInventoryAdded'], $count));
      header('Location: store_operations.php?view=inventory'); exit;
    }
    if ($action === 'coupon_add') {
      $code = strtoupper(trim((string) ($_POST['code'] ?? '')));
      $percent = (int) ($_POST['percent'] ?? 0);
      $limit = max(1, (int) ($_POST['limit'] ?? 1));
      $perUser = max(1, (int) ($_POST['per_user'] ?? 1));
      $validFrom = trim((string) ($_POST['valid_from'] ?? ''));
      $validTo = trim((string) ($_POST['valid_to'] ?? ''));
      $fromTs = $validFrom !== '' ? strtotime($validFrom) : null;
      $toTs = $validTo !== '' ? strtotime($validTo . ' 23:59:59') : null;
      if (!preg_match('/^[A-Z0-9]{2,40}$/', $code) || $percent < 1 || $percent > 90 || ($fromTs && $toTs && $fromTs > $toTs)) throw new RuntimeException('coupon_invalid');
      if (db_count($pdo, 'SELECT COUNT(*) FROM DiscountSell WHERE codeDiscount = ?', [$code])) throw new RuntimeException('coupon_exists');
      db_query($pdo, "INSERT INTO DiscountSell (codeDiscount, price, limitDiscount, agent, usefirst, useuser, code_product, code_panel, time, type, usedDiscount, valid_from, valid_to, per_user_limit) VALUES (?, ?, ?, 'allusers', ?, ?, ?, '/all', ?, 'buy', 0, ?, ?, ?)", [
        $code, $percent, $limit, !empty($_POST['usefirst']) ? '1' : '0', $perUser, (string) ($_POST['code_product'] ?? 'all'), $toTs, $fromTs, $toTs, $perUser,
      ]);
      flash('success', $textbotlang['panel']['storeCouponAdded']);
      header('Location: store_operations.php?view=coupons'); exit;
    }
    if ($action === 'receipt_review') {
      $receiptId = (int) ($_POST['receipt_id'] ?? 0);
      $decision = (string) ($_POST['decision'] ?? '');
      $note = trim((string) ($_POST['review_note'] ?? ''));
      if (!in_array($decision, ['approve', 'reject'], true)) throw new RuntimeException('review_decision');
      $result = storefrontReviewReceipt($pdo, $receiptId, (string) $_SESSION['admin_user'], $decision, $note);
      if (($result['status'] ?? '') !== 'already_reviewed') {
        sf_telegram_send((string) ($PLATFORM_TOKENS['telegram'] ?? $APIKEY ?? ''), (string) $result['order']['id_user'], storefrontReceiptUserNotice($result));
      }
      flash('success', $textbotlang['panel']['storeReceiptReviewed']);
      header('Location: store_operations.php?view=receipts'); exit;
    }
    if ($action === 'refund_review') {
      $id = (int) ($_POST['refund_id'] ?? 0);
      $decision = (string) ($_POST['decision'] ?? '');
      $note = trim((string) ($_POST['review_note'] ?? ''));
      $providerRef = trim((string) ($_POST['provider_ref'] ?? ''));
      $map = ['approve' => 'refund_approved', 'reject' => 'rejected', 'refunded' => 'refunded'];
      if (!isset($map[$decision])) throw new RuntimeException('refund_state');
      $stmt = $pdo->prepare("SELECT * FROM storefront_refund WHERE id = ? LIMIT 1");
      $stmt->execute([$id]);
      $refund = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$refund) throw new RuntimeException('refund_not_found');
      $allowed = ['requested' => ['approve', 'reject'], 'refund_approved' => ['refunded']];
      if (!in_array($decision, $allowed[$refund['status']] ?? [], true)) throw new RuntimeException('refund_state');
      if ($decision === 'refunded' && $providerRef === '') throw new RuntimeException('refund_reference');
      $stmt = $pdo->prepare("UPDATE storefront_refund SET status = ?, reviewer = ?, review_note = ?, provider_ref = ?, reviewed_at = ? WHERE id = ? AND status = ?");
      $stmt->execute([$map[$decision], $_SESSION['admin_user'], $note, $providerRef !== '' ? $providerRef : null, time(), $id, $refund['status']]);
      if ($decision === 'refunded') {
        $orderStmt = $pdo->prepare("SELECT id_payment FROM service_other WHERE id = ? LIMIT 1");
        $orderStmt->execute([(int) $refund['order_id']]);
        $orderNo = (string) $orderStmt->fetchColumn();
        global $PLATFORM_TOKENS;
        sf_telegram_send((string) ($PLATFORM_TOKENS['telegram'] ?? $APIKEY ?? ''), (string) $refund['id_user'], "↩️ درخواست بازگشت وجه سفارش {$orderNo} تأیید و ثبت شد.\nمراجعه پیگیری: {$providerRef}");
      }
      flash('success', $textbotlang['panel']['storeRefundUpdated']);
      header('Location: store_operations.php?view=refunds'); exit;
    }
    if ($action === 'review_moderate') {
      $id = (int) ($_POST['review_id'] ?? 0);
      $status = (string) ($_POST['status'] ?? '');
      if (!in_array($status, ['published', 'rejected'], true)) throw new RuntimeException('review_state');
      $stmt = $pdo->prepare("UPDATE storefront_review SET status = ?, reviewed_at = ? WHERE id = ? AND status = 'pending'");
      $stmt->execute([$status, time(), $id]);
      if ($stmt->rowCount() !== 1) throw new RuntimeException('review_state');
      flash('success', $textbotlang['panel']['storeReviewUpdated']);
      header('Location: store_operations.php?view=reviews'); exit;
    }
    if ($action === 'ticket_reply') {
      $tracking = trim((string) ($_POST['tracking'] ?? ''));
      $reply = trim((string) ($_POST['reply'] ?? ''));
      if ($tracking === '' || $reply === '') throw new RuntimeException('ticket_reply');
      $ticket = db_fetch($pdo, 'SELECT * FROM support_message WHERE Tracking = ? LIMIT 1', [$tracking]);
      if (!$ticket) throw new RuntimeException('ticket_not_found');
      db_query($pdo, "INSERT INTO support_message (Tracking, idsupport, iduser, name_departman, text, result, time, status, order_no) VALUES (?, 'admin', ?, ?, ?, ?, ?, 'Answered', ?)", [$tracking, $ticket['iduser'], $ticket['name_departman'], $reply, $reply, date('Y/m/d H:i:s'), $ticket['order_no'] ?? null]);
      global $PLATFORM_TOKENS;
      sf_telegram_send((string) ($PLATFORM_TOKENS['telegram'] ?? $APIKEY ?? ''), (string) $ticket['iduser'], "🎧 پاسخ پشتیبانی به تیکت {$tracking}:\n\n" . htmlspecialchars($reply, ENT_QUOTES, 'UTF-8'));
      flash('success', $textbotlang['panel']['storeTicketAnswered']);
      header('Location: store_operations.php?view=tickets'); exit;
    }
    if ($action === 'ticket_close') {
      $tracking = trim((string) ($_POST['tracking'] ?? ''));
      db_query($pdo, "UPDATE support_message SET status = 'close' WHERE Tracking = ?", [$tracking]);
      flash('success', $textbotlang['panel']['storeTicketClosed']);
      header('Location: store_operations.php?view=tickets'); exit;
    }
    throw new RuntimeException('action');
  } catch (Throwable $e) {
    $key = 'storeOperationError';
    flash('error', $textbotlang['panel'][$key] . ' ' . $e->getMessage());
    header('Location: store_operations.php?view=' . $view); exit;
  }
}

$products = db_fetchAll($pdo, "SELECT id, code_product, name_product, inventory_enabled FROM product ORDER BY display_order, id");
$inventoryCounts = [];
foreach ($pdo->query("SELECT product_id, SUM(status='available') available, SUM(status='delivered') delivered FROM storefront_inventory_item GROUP BY product_id")->fetchAll(PDO::FETCH_ASSOC) as $countRow) {
  $inventoryCounts[(int) $countRow['product_id']] = $countRow;
}
$coupons = db_fetchAll($pdo, "SELECT * FROM DiscountSell ORDER BY id DESC LIMIT 100");
$payments = db_fetchAll($pdo, "SELECT p.*, o.id_user, o.payable_amount, o.status AS order_status FROM storefront_payment p JOIN service_other o ON o.id = p.order_id ORDER BY p.id DESC LIMIT 100");
$receipts = db_fetchAll($pdo, "SELECT * FROM storefront_receipt ORDER BY id DESC LIMIT 100");
$refunds = db_fetchAll($pdo, "SELECT * FROM storefront_refund ORDER BY id DESC LIMIT 100");
$reviews = db_fetchAll($pdo, "SELECT * FROM storefront_review ORDER BY id DESC LIMIT 100");
$tickets = db_fetchAll($pdo, "SELECT * FROM support_message ORDER BY id DESC LIMIT 200");

$pageTitle = $textbotlang['panel']['storeOperationsTitle'];
$pageLede = $textbotlang['panel']['storeOperationsSubtitle'];
$activeNav = 'store_operations';
include __DIR__ . '/inc/layout_head.php';
?>
<div class="tabs" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px">
<?php foreach (['inventory' => 'storeInventory', 'coupons' => 'storeCoupons', 'payments' => 'storePayments', 'receipts' => 'storeReceipts', 'refunds' => 'storeRefunds', 'reviews' => 'storeReviews', 'tickets' => 'storeTickets'] as $key => $label): ?>
  <a class="btn <?= $view === $key ? 'btn-primary' : 'btn-ghost' ?>" href="store_operations.php?view=<?= $key ?>"><?= $textbotlang['panel'][$label] ?></a>
<?php endforeach; ?>
</div>
<?php if ($view === 'inventory'): ?>
<div class="card" style="padding:20px;margin-bottom:18px"><h2><?= $textbotlang['panel']['storeInventoryAdd'] ?></h2><form method="POST"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="inventory_add"><div class="form-grid"><div class="field"><label><?= $textbotlang['panel']['storeProduct'] ?></label><select class="select" name="product_id" required><?php foreach ($products as $p): if (!$p['inventory_enabled']) continue; ?><option value="<?= (int) $p['id'] ?>"><?= htmlspecialchars($p['name_product']) ?></option><?php endforeach; ?></select></div><div class="field full"><label><?= $textbotlang['panel']['storeSecrets'] ?></label><textarea class="input" name="secrets" rows="8" required placeholder="<?= $textbotlang['panel']['storeSecretsHint'] ?>"></textarea><small><?= $textbotlang['panel']['storeSecretsHint'] ?></small></div></div><button class="btn btn-primary" type="submit"><?= $textbotlang['panel']['storeInventorySave'] ?></button></form></div>
<div class="catalog-grid"><?php foreach ($products as $p): if (!$p['inventory_enabled']) continue; $counts = $inventoryCounts[$p['id']] ?? null; ?><article class="product-card"><div class="product-body"><h3><?= htmlspecialchars($p['name_product']) ?></h3><div class="product-category" dir="ltr"><?= htmlspecialchars($p['code_product']) ?></div><div class="product-meta"><span><?= $textbotlang['panel']['storeAvailable'] ?>: <strong><?= $counts ? (int) $counts['available'] : 0 ?></strong></span><span><?= $textbotlang['panel']['storeDeliveredLabel'] ?>: <strong><?= $counts ? (int) $counts['delivered'] : 0 ?></strong></span></div></div></article><?php endforeach; ?></div>
<?php elseif ($view === 'coupons'): ?>
<div class="card" style="padding:20px;margin-bottom:18px"><h2><?= $textbotlang['panel']['storeCouponAdd'] ?></h2><form method="POST"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="coupon_add"><div class="form-grid"><div class="field"><label><?= $textbotlang['panel']['storeCouponCode'] ?></label><input class="input" name="code" required pattern="[A-Za-z0-9]{2,40}" dir="ltr"></div><div class="field"><label><?= $textbotlang['panel']['storeCouponPercent'] ?></label><input class="input" name="percent" type="number" min="1" max="90" required></div><div class="field"><label><?= $textbotlang['panel']['storeCouponLimit'] ?></label><input class="input" name="limit" type="number" min="1" value="100" required></div><div class="field"><label><?= $textbotlang['panel']['storeCouponPerUser'] ?></label><input class="input" name="per_user" type="number" min="1" value="1" required></div><div class="field"><label><?= $textbotlang['panel']['storeValidFrom'] ?></label><input class="input" name="valid_from" type="date"></div><div class="field"><label><?= $textbotlang['panel']['storeValidTo'] ?></label><input class="input" name="valid_to" type="date"></div><div class="field"><label><?= $textbotlang['panel']['storeProduct'] ?></label><select class="select" name="code_product"><option value="all"><?= $textbotlang['panel']['catalogAllCategories'] ?></option><?php foreach ($products as $p): ?><option value="<?= htmlspecialchars($p['code_product']) ?>"><?= htmlspecialchars($p['name_product']) ?></option><?php endforeach; ?></select></div><div class="field"><label><input type="checkbox" name="usefirst" value="1"> <?= $textbotlang['panel']['storeFirstOrder'] ?></label></div></div><button class="btn btn-primary" type="submit"><?= $textbotlang['panel']['storeCouponSave'] ?></button></form></div>
<div class="card tbl-wrap"><table class="tbl-xl"><thead><tr><th><?= $textbotlang['panel']['storeCouponCode'] ?></th><th>%</th><th><?= $textbotlang['panel']['storeCouponLimit'] ?></th><th><?= $textbotlang['panel']['storeUsed'] ?></th><th><?= $textbotlang['panel']['storeValidity'] ?></th></tr></thead><tbody><?php foreach ($coupons as $c): ?><tr><td dir="ltr"><?= htmlspecialchars($c['codeDiscount']) ?></td><td><?= (int) $c['price'] ?>%</td><td><?= (int) $c['limitDiscount'] ?></td><td><?= (int) $c['usedDiscount'] ?></td><td><?= $c['valid_to'] ? date('Y/m/d', (int) $c['valid_to']) : '—' ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php elseif ($view === 'payments'): ?>
<div class="card tbl-wrap"><table class="tbl-xl"><thead><tr><th><?= $textbotlang['panel']['storePaymentOrder'] ?></th><th><?= $textbotlang['panel']['storePaymentAmount'] ?></th><th><?= $textbotlang['panel']['storePaymentStatus'] ?></th><th><?= $textbotlang['panel']['storePaymentOrderStatus'] ?></th><th><?= $textbotlang['panel']['storePaymentTransId'] ?></th><th><?= $textbotlang['panel']['storePaymentUpdated'] ?></th></tr></thead><tbody><?php foreach ($payments as $p): ?><tr><td dir="ltr"><?= htmlspecialchars($p['order_no']) ?></td><td><?= number_format((int) $p['amount_toman']) ?> <?= $textbotlang['panel']['catalogToman'] ?></td><td><span class="tag <?= $p['status'] === 'paid' ? 'tag-ok' : ($p['status'] === 'unknown' ? 'tag-warn' : 'tag-info') ?>"><?= htmlspecialchars($p['status']) ?></span></td><td><?= htmlspecialchars($p['order_status']) ?></td><td dir="ltr"><?= htmlspecialchars($p['provider_transid'] ?: '—') ?></td><td><?= date('Y/m/d H:i', (int) $p['updated_at']) ?></td></tr><?php endforeach; ?></tbody></table><?php if (!$payments): ?><div class="empty" style="padding:40px 20px"><?= $textbotlang['panel']['storePaymentEmpty'] ?></div><?php endif; ?><p class="muted" style="font-size:11px"><?= $textbotlang['panel']['storePaymentReconcileNote'] ?></p></div>
<?php elseif ($view === 'receipts'): ?>
<div class="catalog-grid"><?php foreach ($receipts as $r): ?><article class="product-card"><div class="product-media"><a href="receipt.php?id=<?= (int) $r['id'] ?>" target="_blank" rel="noopener"><img src="receipt.php?id=<?= (int) $r['id'] ?>" alt="<?= $textbotlang['panel']['storeReceiptImage'] ?>" loading="lazy"></a></div><div class="product-body"><h3 dir="ltr"><?= htmlspecialchars($r['order_no']) ?></h3><div class="product-category"><?= $textbotlang['panel']['storeReceiptStatus'] ?>: <?= htmlspecialchars($r['status']) ?></div><div class="product-meta"><span dir="ltr"><?= htmlspecialchars($r['id_user']) ?></span><span><?= date('Y/m/d H:i', (int) $r['created_at']) ?></span></div><?php if ($r['status'] === 'waiting'): ?><form method="POST" class="catalog-form"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="receipt_review"><input type="hidden" name="receipt_id" value="<?= (int) $r['id'] ?>"><input class="input" name="review_note" placeholder="<?= $textbotlang['panel']['storeReviewNote'] ?>"><div class="product-actions"><button class="btn btn-primary btn-sm" name="decision" value="approve"><?= $textbotlang['panel']['storeApprove'] ?></button><button class="btn btn-no btn-sm" name="decision" value="reject"><?= $textbotlang['panel']['storeReject'] ?></button></div></form><?php endif; ?></div></article><?php endforeach; ?></div>
<?php elseif ($view === 'refunds'): ?>
<div class="catalog-grid"><?php foreach ($refunds as $r): ?><article class="product-card"><div class="product-body"><h3>#<?= (int) $r['id'] ?> · <?= $textbotlang['panel']['storeOrder'] ?> #<?= (int) $r['order_id'] ?></h3><div class="status-chip on" style="position:static"><?= htmlspecialchars($r['status']) ?></div><p><?= htmlspecialchars($r['reason']) ?></p><div class="product-price"><strong><?= number_format((int) $r['amount_toman']) ?></strong><span><?= $textbotlang['panel']['catalogToman'] ?></span></div><form method="POST" class="catalog-form"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="refund_review"><input type="hidden" name="refund_id" value="<?= (int) $r['id'] ?>"><input class="input" name="review_note" placeholder="<?= $textbotlang['panel']['storeReviewNote'] ?>"><input class="input" name="provider_ref" placeholder="<?= $textbotlang['panel']['storeProviderRef'] ?>"><div class="product-actions"><?php if ($r['status'] === 'requested'): ?><button class="btn btn-primary btn-sm" name="decision" value="approve"><?= $textbotlang['panel']['storeApprove'] ?></button><button class="btn btn-no btn-sm" name="decision" value="reject"><?= $textbotlang['panel']['storeReject'] ?></button><?php elseif ($r['status'] === 'refund_approved'): ?><button class="btn btn-primary btn-sm" name="decision" value="refunded"><?= $textbotlang['panel']['storeMarkRefunded'] ?></button><?php endif; ?></div></form></div></article><?php endforeach; ?></div>
<?php elseif ($view === 'reviews'): ?>
<div class="catalog-grid"><?php foreach ($reviews as $r): ?><article class="product-card"><div class="product-body"><h3><?= htmlspecialchars($r['product_code']) ?></h3><div class="product-price">★ <?= (int) $r['rating'] ?> / 5</div><p><?= htmlspecialchars($r['review_text']) ?></p><small><?= htmlspecialchars($r['status']) ?></small><?php if ($r['status'] === 'pending'): ?><form method="POST" class="product-actions"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="review_moderate"><input type="hidden" name="review_id" value="<?= (int) $r['id'] ?>"><button class="btn btn-primary btn-sm" name="status" value="published"><?= $textbotlang['panel']['storeApprove'] ?></button><button class="btn btn-no btn-sm" name="status" value="rejected"><?= $textbotlang['panel']['storeReject'] ?></button></form><?php endif; ?></div></article><?php endforeach; ?></div>
<?php else: ?>
<div class="catalog-grid"><?php foreach ($tickets as $t): ?><article class="product-card"><div class="product-body"><h3><?= htmlspecialchars($t['Tracking']) ?></h3><div class="product-category"><?= htmlspecialchars($t['name_departman']) ?> · <?= htmlspecialchars($t['status']) ?></div><p><?= htmlspecialchars($t['text']) ?></p><?php if ($t['result']): ?><p class="success"><?= $textbotlang['panel']['storeTicketAnswer'] ?>: <?= htmlspecialchars($t['result']) ?></p><?php endif; ?><form method="POST"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="ticket_reply"><input type="hidden" name="tracking" value="<?= htmlspecialchars($t['Tracking']) ?>"><textarea class="input" name="reply" rows="3" required></textarea><div class="product-actions"><button class="btn btn-primary btn-sm" type="submit"><?= $textbotlang['panel']['storeTicketReply'] ?></button></div></form><form method="POST"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="ticket_close"><input type="hidden" name="tracking" value="<?= htmlspecialchars($t['Tracking']) ?>"><button class="btn btn-ghost btn-sm" type="submit"><?= $textbotlang['panel']['storeTicketClose'] ?></button></form></div></article><?php endforeach; ?></div>
<?php endif; ?>
<?php include __DIR__ . '/inc/layout_foot.php'; ?>
