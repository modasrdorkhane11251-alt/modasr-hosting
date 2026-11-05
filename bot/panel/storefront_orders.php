<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/../lib/storefront_payment.php';
require_auth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check_post();
  $orderId = (int) ($_POST['order_id'] ?? 0);
  $delivery = trim((string) ($_POST['delivery'] ?? ''));
  if ($orderId <= 0 || $delivery === '' || mb_strlen($delivery, 'UTF-8') > 5000) {
    flash('error', $textbotlang['panel']['storefrontDeliveryRequired']);
    header('Location: storefront_orders.php');
    exit;
  }
  try {
    $stmt = $pdo->prepare("SELECT * FROM service_other WHERE id = ? AND type = 'storefront' LIMIT 1");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
      throw new RuntimeException('not_found');
    }
    $stmt = $pdo->prepare("UPDATE service_other SET status = 'paid', output = ? WHERE id = ? AND status = 'paid_pending_delivery'");
    $stmt->execute([$delivery, $orderId]);
    if ($stmt->rowCount() !== 1) {
      throw new RuntimeException('invalid_state');
    }
    $orderNo = sf_order_no($order);
    @file_put_contents(__DIR__ . '/../storage/storefront_order_audit.jsonl', json_encode([
      'time' => date('c'), 'actor' => $_SESSION['admin_user'] ?? 'unknown', 'action' => 'delivered', 'order_id' => $orderId, 'order_no' => $orderNo,
    ], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    global $PLATFORM_TOKENS;
    $token = (string) ($PLATFORM_TOKENS['telegram'] ?? $APIKEY ?? '');
    $message = "📦 <b>سفارش شما تحویل شد</b>\n\nشماره سفارش: <code>{$orderNo}</code>\n\n" . htmlspecialchars($delivery, ENT_QUOTES, 'UTF-8');
    sf_telegram_send($token, (string) $order['id_user'], $message);
    flash('success', $textbotlang['panel']['storefrontDelivered']);
  } catch (Throwable $e) {
    flash('error', $textbotlang['panel'][$e->getMessage() === 'not_found' ? 'storefrontNotFound' : 'storefrontNotReady']);
  }
  header('Location: storefront_orders.php');
  exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? 'all');
if (!in_array($status, ['all', 'unpaid', 'paid_pending_delivery', 'paid'], true)) $status = 'all';
$where = ["type = 'storefront'"];
$params = [];
if ($q !== '') {
  $where[] = '(id_payment LIKE :q_order OR id_user LIKE :q_user OR username LIKE :q_username)';
  $params = [':q_order' => '%' . $q . '%', ':q_user' => '%' . $q . '%', ':q_username' => '%' . $q . '%'];
}
if ($status !== 'all') {
  $where[] = 'status = :status';
  $params[':status'] = $status;
}
$whereSql = ' WHERE ' . implode(' AND ', $where);
$total = (int) db_query($pdo, 'SELECT COUNT(*) FROM service_other' . $whereSql, $params)->fetchColumn();
$perPage = 20;
$pages = max(1, (int) ceil($total / $perPage));
$page = max(1, min($pages, (int) ($_GET['page'] ?? 1)));
$stmt = $pdo->prepare('SELECT * FROM service_other' . $whereSql . ' ORDER BY id DESC LIMIT :limit OFFSET :offset');
foreach ($params as $k => $v) $stmt->bindValue($k, $v, PDO::PARAM_STR);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
$stmt->execute();
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stats = db_fetch($pdo, "SELECT COUNT(*) total, SUM(status = 'unpaid') unpaid, SUM(status = 'paid_pending_delivery') pending, SUM(status = 'paid') delivered FROM service_other WHERE type = 'storefront'") ?: [];

$pageTitle = $textbotlang['panel']['storefrontOrdersTitle'];
$pageLede = $textbotlang['panel']['storefrontOrdersSubtitle'];
$activeNav = 'storefront_orders';
include __DIR__ . '/inc/layout_head.php';
?>
<div class="catalog-stats fade-up">
  <div class="catalog-stat"><span><?= $textbotlang['panel']['storefrontTotal'] ?></span><strong><?= number_format((int) ($stats['total'] ?? 0)) ?></strong></div>
  <div class="catalog-stat rate"><span><?= $textbotlang['panel']['storefrontUnpaid'] ?></span><strong><?= number_format((int) ($stats['unpaid'] ?? 0)) ?></strong></div>
  <div class="catalog-stat ok"><span><?= $textbotlang['panel']['storefrontPending'] ?></span><strong><?= number_format((int) ($stats['pending'] ?? 0)) ?></strong></div>
  <div class="catalog-stat"><span><?= $textbotlang['panel']['storefrontDeliveredLabel'] ?></span><strong><?= number_format((int) ($stats['delivered'] ?? 0)) ?></strong></div>
</div>
<form class="catalog-filters" method="GET">
  <label class="catalog-search"><?= icon('search', 16) ?><input name="q" value="<?= htmlspecialchars($q) ?>" placeholder="<?= htmlspecialchars($textbotlang['panel']['storefrontSearch']) ?>"></label>
  <select class="select" name="status">
    <?php foreach (['all' => 'storefrontAll', 'unpaid' => 'storefrontUnpaid', 'paid_pending_delivery' => 'storefrontPending', 'paid' => 'storefrontDeliveredLabel'] as $value => $key): ?>
      <option value="<?= $value ?>" <?= $status === $value ? 'selected' : '' ?>><?= $textbotlang['panel'][$key] ?></option>
    <?php endforeach; ?>
  </select>
  <button class="btn btn-primary" type="submit"><?= $textbotlang['panel']['catalogApply'] ?></button>
  <a class="btn btn-ghost" href="storefront_orders.php"><?= $textbotlang['panel']['catalogReset'] ?></a>
</form>
<div class="catalog-grid">
<?php foreach ($orders as $order): $value = json_decode((string) $order['value'], true) ?: []; $orderNo = sf_order_no($order); $state = $order['status']; ?>
  <article class="product-card">
    <div class="product-body">
      <div class="product-title-row"><h3 dir="ltr"><?= htmlspecialchars($orderNo) ?></h3><span class="status-chip <?= $state === 'unpaid' ? 'off' : 'on' ?>" style="position:static"><?= $textbotlang['panel'][['unpaid' => 'storefrontUnpaid', 'paid_pending_delivery' => 'storefrontPending', 'paid' => 'storefrontDeliveredLabel'][$state] ?? 'storefrontAll'] ?></span></div>
      <div class="product-category"><?= icon('user', 13) ?><span dir="ltr"><?= htmlspecialchars($order['id_user']) ?></span><span>·</span><span><?= htmlspecialchars($order['time']) ?></span></div>
      <div class="product-price"><strong><?= number_format((int) ($order['payable_amount'] ?? $order['price'])) ?></strong><span><?= $textbotlang['panel']['catalogToman'] ?></span></div>
      <div class="storefront-lines">
        <?php foreach (($value['lines'] ?? []) as $line): ?><div>• <?= htmlspecialchars($line['name'] ?? '') ?> × <?= (int) ($line['qty'] ?? 1) ?></div><?php endforeach; ?>
      </div>
      <?php if ($state === 'paid_pending_delivery'): ?>
        <button class="btn btn-primary" onclick="openDeliveryModal(<?= (int) $order['id'] ?>, '<?= htmlspecialchars($orderNo, ENT_QUOTES) ?>')"><?= icon('check', 14) ?><?= $textbotlang['panel']['storefrontMarkDelivered'] ?></button>
      <?php elseif ($state === 'paid'): ?>
        <details class="storefront-output"><summary><?= $textbotlang['panel']['storefrontViewDelivery'] ?></summary><pre><?= htmlspecialchars($order['output']) ?></pre></details>
      <?php endif; ?>
    </div>
  </article>
<?php endforeach; ?>
</div>
<?php if (!$orders): ?><div class="card catalog-empty"><h3><?= $textbotlang['panel']['storefrontNoOrders'] ?></h3></div><?php endif; ?>
<?php if ($pages > 1): ?><nav class="catalog-pagination"><?php for ($i = 1; $i <= $pages; $i++): ?><a class="<?= $i === $page ? 'active' : '' ?>" href="?<?= htmlspecialchars(http_build_query(['q' => $q, 'status' => $status, 'page' => $i])) ?>"><?= $i ?></a><?php endfor; ?></nav><?php endif; ?>
<div class="modal-veil" id="deliveryModal"><div class="modal"><div class="modal-head"><h3><?= $textbotlang['panel']['storefrontMarkDelivered'] ?></h3><button class="modal-x" onclick="closeModal('deliveryModal')"><?= icon('close', 14) ?></button></div><form method="POST"><div class="modal-body"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="order_id" id="delivery_order_id"><p id="delivery_order_no" dir="ltr"></p><div class="field full"><label><?= $textbotlang['panel']['storefrontDeliveryData'] ?></label><textarea class="input" name="delivery" rows="7" maxlength="5000" required placeholder="<?= htmlspecialchars($textbotlang['panel']['storefrontDeliveryPlaceholder']) ?>"></textarea><small><?= $textbotlang['panel']['storefrontDeliveryNotice'] ?></small></div></div><div class="modal-foot"><button class="btn btn-primary" type="submit"><?= icon('check', 13) ?><?= $textbotlang['panel']['storefrontConfirmDelivery'] ?></button><button class="btn btn-ghost" type="button" onclick="closeModal('deliveryModal')"><?= $textbotlang['panel']['categoryCancelBtn'] ?></button></div></form></div></div>
<script>
function openDeliveryModal(id, orderNo) {
  document.getElementById('delivery_order_id').value = id;
  document.getElementById('delivery_order_no').textContent = orderNo;
  openModal('deliveryModal');
}
</script>
<style>.storefront-lines{color:var(--mute);font-size:.75rem;line-height:1.9;margin:12px 0}.storefront-output{margin-top:12px;color:var(--mute)}.storefront-output pre{white-space:pre-wrap;overflow-wrap:anywhere;background:var(--sf2);padding:12px;border-radius:12px;color:var(--text2)}</style>
<?php include __DIR__ . '/inc/layout_foot.php'; ?>
