<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/catalog.php';
require_auth();

$storefrontColumns = catalogColumns($pdo);
$usdtRate = catalogLiveUsdtRate();

function catalog_redirect(): void
{
  header('Location: product.php');
  exit;
}

function catalog_error_key(Throwable $e): string
{
  return match ($e->getMessage()) {
    'invalid_toman', 'invalid_usd' => 'catalogInvalidPrice',
    'rate_unavailable' => 'catalogRateUnavailable',
    'migration_required' => 'catalogMigrationRequired',
    'image_too_large' => 'catalogImageTooLarge',
    'image_invalid', 'image_dimensions' => 'catalogImageInvalid',
    'image_upload_failed', 'image_storage' => 'catalogImageStorage',
    'invalid_category' => 'catalogInvalidCategory',
    'invalid_panel' => 'catalogInvalidPanel',
    'product_not_found' => 'catalogProductNotFound',
    default => 'catalogSaveError',
  };
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check_post();
  $action = (string) ($_POST['action'] ?? '');
  try {
    if ($action === 'add' || $action === 'edit') {
      $pid = $action === 'edit' ? (int) ($_POST['edit_id'] ?? 0) : 0;
      $name = trim((string) ($_POST['name_product'] ?? ''));
      if ($name === '' || mb_strlen($name, 'UTF-8') > 500) {
        throw new RuntimeException('product_not_found');
      }
      $duplicate = db_fetch($pdo, "SELECT id FROM product WHERE name_product = ?" . ($pid ? " AND id != ?" : "") . " LIMIT 1", $pid ? [$name, $pid] : [$name]);
      if ($duplicate) {
        throw new RuntimeException('duplicate_name');
      }

      $pdo->beginTransaction();
      $old = $pid ? db_fetch($pdo, "SELECT * FROM product WHERE id = ? FOR UPDATE", [$pid]) : null;
      if ($pid && !$old) {
        throw new RuntimeException('product_not_found');
      }
      if (!$storefrontColumns['storefront_usd_cents'] && ($_POST['pricing_mode'] ?? '') === 'dynamic') {
        throw new RuntimeException('migration_required');
      }
      [$priceToman, $usdCents] = catalogResolvePricing(
        (string) ($_POST['pricing_mode'] ?? 'fixed'),
        (string) ($_POST['price_product'] ?? ''),
        (string) ($_POST['price_usd'] ?? ''),
        $usdtRate
      );

      $category = trim((string) ($_POST['category_product'] ?? ''));
      if ($category !== '' && !db_count($pdo, "SELECT COUNT(*) FROM category WHERE remark = ?", [$category])) {
        throw new RuntimeException('invalid_category');
      }
      $location = (string) ($_POST['namepanel'] ?? '/all');
      if ($location !== '/all' && !db_count($pdo, "SELECT COUNT(*) FROM marzban_panel WHERE name_panel = ?", [$location])) {
        throw new RuntimeException('invalid_panel');
      }
      $agent = (string) ($_POST['agent_product'] ?? 'f');
      if (!in_array($agent, ['f', 'n', 'n2'], true)) {
        $agent = 'f';
      }
      $status = (string) ($_POST['status_product'] ?? 'active');
      $status = in_array($status, ['active', 'disable'], true) ? $status : 'active';
      $volume = catalogToman((string) ($_POST['volume_product'] ?? '0'));
      $time = catalogToman((string) ($_POST['time_product'] ?? '0'));
      $note = trim((string) ($_POST['note_product'] ?? ''));
      $image = $storefrontColumns['storefront_image']
        ? catalogStoreUploadedImage($_FILES['product_image'] ?? [], $old['storefront_image'] ?? null)
        : null;

      if ($action === 'add') {
        $code = bin2hex(random_bytes(3));
        $fields = [
          'name_product' => $name,
          'code_product' => $code,
          'price_product' => $priceToman,
          'Volume_constraint' => $volume,
          'Service_time' => $time,
          'Location' => $location,
          'agent' => $agent,
          'data_limit_reset' => 'no_reset',
          'note' => $note,
          'category' => $category,
          'hide_panel' => '{}',
          'one_buy_status' => '0',
          'status_product' => $status,
        ];
        if (!empty($storefrontColumns['multi_month'])) {
          $fields['multi_month'] = !empty($_POST['multi_month']) ? 1 : 0;
        }
        if (!empty($storefrontColumns['inventory_enabled'])) {
          $fields['inventory_enabled'] = !empty($_POST['inventory_enabled']) ? 1 : 0;
        }
        if (!empty($storefrontColumns['tax_included'])) {
          $fields['tax_included'] = !empty($_POST['tax_included']) ? 1 : 0;
        }
        if ($storefrontColumns['storefront_usd_cents']) {
          $fields['storefront_usd_cents'] = $usdCents;
        }
        if ($storefrontColumns['storefront_image']) {
          $fields['storefront_image'] = $image;
        }
        $columns = implode(',', array_keys($fields));
        $marks = implode(',', array_fill(0, count($fields), '?'));
        db_query($pdo, "INSERT INTO product ($columns) VALUES ($marks)", array_values($fields));
        $pid = (int) $pdo->lastInsertId();
        db_query($pdo, "UPDATE product SET display_order = id WHERE id = ?", [$pid]);
        $new = $fields;
      } else {
        $fields = [
          'name_product' => $name,
          'price_product' => $priceToman,
          'Volume_constraint' => $volume,
          'Service_time' => $time,
          'Location' => $location,
          'agent' => $agent,
          'note' => $note,
          'category' => $category,
          'status_product' => $status,
        ];
        if (!empty($storefrontColumns['multi_month'])) {
          $fields['multi_month'] = !empty($_POST['multi_month']) ? 1 : 0;
        }
        if (!empty($storefrontColumns['inventory_enabled'])) {
          $fields['inventory_enabled'] = !empty($_POST['inventory_enabled']) ? 1 : 0;
        }
        if (!empty($storefrontColumns['tax_included'])) {
          $fields['tax_included'] = !empty($_POST['tax_included']) ? 1 : 0;
        }
        if ($storefrontColumns['storefront_usd_cents']) {
          $fields['storefront_usd_cents'] = $usdCents;
        }
        if ($storefrontColumns['storefront_image']) {
          $fields['storefront_image'] = $image;
        }
        $sql = 'UPDATE product SET ' . implode(',', array_map(fn($k) => "$k = ?", array_keys($fields))) . ' WHERE id = ?';
        db_query($pdo, $sql, [...array_values($fields), $pid]);
        if (isset($_POST['position'])) {
          move_display_order($pdo, 'product', $pid, max(1, (int) $_POST['position']));
        }
        $new = $fields;
      }
      $pdo->commit();
      $auditOld = array_intersect_key($old ?? [], array_flip(['name_product', 'price_product', 'Volume_constraint', 'Service_time', 'Location', 'agent', 'note', 'category', 'status_product', 'storefront_usd_cents', 'storefront_image']));
      catalogAudit($pdo, $action, $pid, $auditOld, $new);
      flash('success', $textbotlang['panel'][$action === 'add' ? 'catalogAdded' : 'catalogEdited']);
    } elseif ($action === 'status' || $action === 'delete') {
      $pid = (int) ($_POST['id'] ?? 0);
      $old = db_fetch($pdo, "SELECT id, name_product, status_product FROM product WHERE id = ?", [$pid]);
      if (!$old) {
        throw new RuntimeException('product_not_found');
      }
      $status = $action === 'delete' ? 'disable' : (string) ($_POST['status'] ?? '');
      if (!in_array($status, ['active', 'disable'], true)) {
        throw new RuntimeException('catalogSaveError');
      }
      db_query($pdo, "UPDATE product SET status_product = ? WHERE id = ?", [$status, $pid]);
      catalogAudit($pdo, $action, $pid, $old, ['status_product' => $status]);
      flash('success', $textbotlang['panel'][$status === 'disable' ? 'catalogHidden' : 'catalogActivated']);
    } else {
      throw new RuntimeException('catalogSaveError');
    }
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    $key = $e->getMessage() === 'duplicate_name' ? 'productNameExists' : catalog_error_key($e);
    flash('error', $textbotlang['panel'][$key] ?? $textbotlang['panel']['catalogSaveError']);
  }
  catalog_redirect();
}

$categories = db_fetchAll($pdo, "SELECT id, remark FROM category ORDER BY display_order, id");
$panels = db_fetchAll($pdo, "SELECT name_panel FROM marzban_panel ORDER BY display_order, id");
$q = trim((string) ($_GET['q'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? 'all');
if (!in_array($statusFilter, ['all', 'active', 'disable'], true)) {
  $statusFilter = 'all';
}
$categoryFilter = trim((string) ($_GET['category'] ?? ''));
$where = [];
$params = [];
if ($q !== '') {
  $where[] = '(name_product LIKE :q_name OR code_product LIKE :q_code)';
  $params[':q_name'] = '%' . $q . '%';
  $params[':q_code'] = '%' . $q . '%';
}
if ($statusFilter !== 'all') {
  $where[] = 'status_product = :status';
  $params[':status'] = $statusFilter;
}
if ($categoryFilter !== '') {
  $where[] = 'category = :category';
  $params[':category'] = $categoryFilter;
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$total = (int) db_query($pdo, "SELECT COUNT(*) FROM product" . $whereSql, $params)->fetchColumn();
$perPage = 24;
$totalPages = max(1, (int) ceil($total / $perPage));
$page = max(1, min($totalPages, (int) ($_GET['page'] ?? 1)));
$offset = ($page - 1) * $perPage;
$stmt = $pdo->prepare("SELECT * FROM product" . $whereSql . " ORDER BY display_order, id LIMIT :limit OFFSET :offset");
foreach ($params as $key => $value) {
  $stmt->bindValue($key, $value, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($products as &$product) {
  $product['image_url'] = catalogImageUrl($product);
  $product['position'] = (int) $product['display_order'];
  $product['price_usd'] = !empty($product['storefront_usd_cents']) ? number_format(((int) $product['storefront_usd_cents']) / 100, 2, '.', '') : '';
  $product['pricing_mode'] = !empty($product['storefront_usd_cents']) ? 'dynamic' : 'fixed';
}
unset($product);
$stats = db_fetch($pdo, "SELECT COUNT(*) total, SUM(status_product = 'active') active, SUM(status_product = 'disable') disabled FROM product") ?: ['total' => 0, 'active' => 0, 'disabled' => 0];

$pageTitle = $textbotlang['panel']['catalogTitle'];
$pageLede = $textbotlang['panel']['catalogSubtitle'];
$activeNav = 'product';
include __DIR__ . '/inc/layout_head.php';
?>

<section class="catalog-hero fade-up">
  <div>
    <div class="catalog-eyebrow"><span class="sync-dot"></span><?= $textbotlang['panel']['catalogSyncReady'] ?></div>
    <h2><?= $textbotlang['panel']['catalogHeroTitle'] ?></h2>
    <p><?= $textbotlang['panel']['catalogHeroText'] ?></p>
  </div>
  <div class="catalog-hero-actions">
    <a class="btn btn-ghost" href="https://shop.modasr.site" target="_blank" rel="noopener noreferrer"><?= icon('eye', 15) ?><?= $textbotlang['panel']['catalogOpenStore'] ?></a>
    <button class="btn btn-primary" onclick="openModal('addModal')"><?= icon('plus', 15) ?><?= $textbotlang['panel']['productAddProductBtn'] ?></button>
  </div>
</section>

<div class="catalog-stats fade-up d1">
  <div class="catalog-stat"><span><?= $textbotlang['panel']['catalogTotal'] ?></span><strong><?= number_format((int) $stats['total']) ?></strong></div>
  <div class="catalog-stat ok"><span><?= $textbotlang['panel']['catalogActive'] ?></span><strong><?= number_format((int) $stats['active']) ?></strong></div>
  <div class="catalog-stat muted"><span><?= $textbotlang['panel']['catalogHiddenLabel'] ?></span><strong><?= number_format((int) $stats['disabled']) ?></strong></div>
  <div class="catalog-stat rate"><span><?= $textbotlang['panel']['catalogLiveRate'] ?></span><strong><?= $usdtRate ? number_format($usdtRate) : '—' ?><small><?= $usdtRate ? $textbotlang['panel']['catalogToman'] : $textbotlang['panel']['catalogRateOffline'] ?></small></strong></div>
</div>

<form class="catalog-filters fade-up d2" method="GET">
  <label class="catalog-search"><?= icon('search', 16) ?><input name="q" value="<?= htmlspecialchars($q) ?>" placeholder="<?= htmlspecialchars($textbotlang['panel']['catalogSearch']) ?>" aria-label="<?= htmlspecialchars($textbotlang['panel']['catalogSearch']) ?>"></label>
  <select name="status" class="select" aria-label="<?= htmlspecialchars($textbotlang['panel']['catalogStatus']) ?>">
    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>><?= $textbotlang['panel']['catalogAllStatuses'] ?></option>
    <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>><?= $textbotlang['panel']['catalogActive'] ?></option>
    <option value="disable" <?= $statusFilter === 'disable' ? 'selected' : '' ?>><?= $textbotlang['panel']['catalogHiddenLabel'] ?></option>
  </select>
  <select name="category" class="select" aria-label="<?= htmlspecialchars($textbotlang['panel']['productDetailPrice']) ?>">
    <option value=""><?= $textbotlang['panel']['catalogAllCategories'] ?></option>
    <?php foreach ($categories as $cat): ?><option value="<?= htmlspecialchars($cat['remark']) ?>" <?= $categoryFilter === $cat['remark'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['remark']) ?></option><?php endforeach; ?>
  </select>
  <button class="btn btn-primary" type="submit"><?= icon('search', 14) ?><?= $textbotlang['panel']['catalogApply'] ?></button>
  <a class="btn btn-ghost" href="product.php"><?= $textbotlang['panel']['catalogReset'] ?></a>
</form>

<?php if (!$products): ?>
  <div class="card catalog-empty"><div class="empty-icon"><?= icon('search', 28) ?></div><h3><?= $textbotlang['panel']['catalogNoResults'] ?></h3><p><?= $textbotlang['panel']['catalogNoResultsText'] ?></p></div>
<?php else: ?>
  <div class="catalog-grid">
    <?php foreach ($products as $p): $disabled = ($p['status_product'] ?? '') === 'disable'; ?>
      <article class="product-card fade-up <?= $disabled ? 'is-disabled' : '' ?>">
        <div class="product-media">
          <?php if ($p['image_url']): ?><img src="<?= htmlspecialchars($p['image_url']) ?>" alt="" loading="lazy"><?php else: ?><div class="product-placeholder"><?= icon('package', 28) ?></div><?php endif; ?>
          <span class="status-chip <?= $disabled ? 'off' : 'on' ?>"><?= $textbotlang['panel'][$disabled ? 'catalogHiddenLabel' : 'catalogActive'] ?></span>
        </div>
        <div class="product-body">
          <div class="product-title-row"><h3><?= htmlspecialchars($p['name_product']) ?></h3><code dir="ltr"><?= htmlspecialchars($p['code_product']) ?></code></div>
          <div class="product-category"><?= icon('folder', 13) ?><span><?= htmlspecialchars($p['category'] ?: $textbotlang['panel']['catalogNoCategory']) ?></span></div>
          <div class="product-price"><strong><?= number_format((int) $p['price_product']) ?></strong><span><?= $textbotlang['panel']['catalogToman'] ?></span><?php if ($p['price_usd'] !== ''): ?><small dir="ltr">$<?= $p['price_usd'] ?> · <?= $textbotlang['panel']['catalogDynamic'] ?></small><?php endif; ?></div>
          <div class="product-meta"><span><?= htmlspecialchars($p['Volume_constraint'] ?: '0') ?> GB</span><span><?= htmlspecialchars($p['Service_time'] ?: '0') ?> <?= $textbotlang['panel']['productDetailTime'] ?></span></div>
          <div class="product-actions">
            <button class="btn btn-primary btn-sm" onclick='openEditModal(<?= htmlspecialchars(json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT), ENT_QUOTES) ?>)'><?= icon('edit', 13) ?><?= $textbotlang['panel']['productEditBtn'] ?></button>
            <form method="POST" class="inline-form"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><input type="hidden" name="status" value="<?= $disabled ? 'active' : 'disable' ?>"><button class="btn btn-ghost btn-sm" type="submit"><?= icon($disabled ? 'check' : 'eye', 13) ?><?= $textbotlang['panel'][$disabled ? 'catalogActivate' : 'catalogHide'] ?></button></form>
            <form method="POST" class="inline-form"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><button class="btn btn-no btn-sm btn-icon" type="submit" title="<?= htmlspecialchars($textbotlang['panel']['catalogDelete']) ?>" data-confirm="<?= htmlspecialchars(sprintf($textbotlang['panel']['catalogDeleteConfirm'], $p['name_product'])) ?>"><?= icon('trash', 13) ?></button></form>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($totalPages > 1): $queryBase = ['q' => $q, 'status' => $statusFilter, 'category' => $categoryFilter]; ?>
<nav class="catalog-pagination" aria-label="pagination">
  <?php for ($i = 1; $i <= $totalPages; $i++): if ($totalPages > 9 && abs($i - $page) > 2 && $i !== 1 && $i !== $totalPages) { if ($i === 2 || $i === $totalPages - 1) echo '<span>…</span>'; continue; } ?>
    <a class="<?= $i === $page ? 'active' : '' ?>" href="?<?= htmlspecialchars(http_build_query($queryBase + ['page' => $i])) ?>"><?= $i ?></a>
  <?php endfor; ?>
</nav>
<?php endif; ?>

<?php
function catalog_form_modal(string $id, string $title, bool $edit, array $categories, array $panels, array $textbotlang, array $storefrontColumns, ?int $usdtRate): void { ?>
<div class="modal-veil" id="<?= $id ?>">
  <div class="modal catalog-modal">
    <div class="modal-head"><h3><?= $title ?></h3><button class="modal-x" onclick="closeModal('<?= $id ?>')" aria-label="close"><?= icon('close', 14) ?></button></div>
    <form method="POST" enctype="multipart/form-data" class="catalog-form" data-pricing-form>
      <div class="modal-body">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="action" value="<?= $edit ? 'edit' : 'add' ?>">
        <?php if ($edit): ?><input type="hidden" name="edit_id" id="edit_id"><?php endif; ?>
        <div class="form-grid">
          <div class="field full"><label><?= $textbotlang['panel']['productDetailName'] ?></label><input type="text" name="name_product" id="<?= $edit ? 'edit_name' : '' ?>" class="input" maxlength="500" required></div>
          <div class="field"><label><?= $textbotlang['panel']['catalogPricingMode'] ?></label><select name="pricing_mode" id="<?= $edit ? 'edit_pricing_mode' : 'add_pricing_mode' ?>" class="select" data-pricing-mode><option value="fixed"><?= $textbotlang['panel']['catalogFixed'] ?></option><option value="dynamic" <?= $storefrontColumns['storefront_usd_cents'] ? '' : 'disabled' ?>><?= $textbotlang['panel']['catalogDynamic'] ?></option></select></div>
          <div class="field"><label><?= $textbotlang['panel']['catalogUsdPrice'] ?></label><input type="number" name="price_usd" id="<?= $edit ? 'edit_price_usd' : 'add_price_usd' ?>" class="input" min="0.01" step="0.01" inputmode="decimal"></div>
          <div class="field"><label><?= $textbotlang['panel']['productSaveBtn'] ?></label><input type="number" name="price_product" id="<?= $edit ? 'edit_price' : 'add_price' ?>" class="input" min="0" step="1" required></div>
          <div class="field"><label><?= $textbotlang['panel']['productVolumeGbSuffix'] ?></label><input type="number" name="volume_product" id="<?= $edit ? 'edit_volume' : '' ?>" class="input" min="0" value="0" required></div>
          <div class="field"><label><?= $textbotlang['panel']['productDetailTime'] ?></label><input type="number" name="time_product" id="<?= $edit ? 'edit_time' : '' ?>" class="input" min="0" value="0" required></div>
          <div class="field"><label><?= $textbotlang['panel']['productDetailPrice'] ?></label><select name="category_product" id="<?= $edit ? 'edit_cat' : '' ?>" class="select"><option value=""><?= $textbotlang['panel']['catalogNoCategory'] ?></option><?php foreach ($categories as $cat): ?><option value="<?= htmlspecialchars($cat['remark']) ?>"><?= htmlspecialchars($cat['remark']) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label><?= $textbotlang['panel']['productDetailType'] ?></label><select name="namepanel" id="<?= $edit ? 'edit_panel' : '' ?>" class="select"><option value="/all">/all · <?= $textbotlang['panel']['catalogDigital'] ?></option><?php foreach ($panels as $pl): ?><option value="<?= htmlspecialchars($pl['name_panel']) ?>"><?= htmlspecialchars($pl['name_panel']) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label><?= $textbotlang['panel']['productDetailCategory'] ?></label><select name="agent_product" id="<?= $edit ? 'edit_agent' : '' ?>" class="select"><option value="f">f</option><option value="n">n</option><option value="n2">n2</option></select></div>
          <div class="field"><label><?= $textbotlang['panel']['catalogStatus'] ?></label><select name="status_product" id="<?= $edit ? 'edit_status' : '' ?>" class="select"><option value="active"><?= $textbotlang['panel']['catalogActive'] ?></option><option value="disable"><?= $textbotlang['panel']['catalogHiddenLabel'] ?></option></select></div>
          <?php if ($edit): ?><div class="field"><label><?= $textbotlang['panel']['productFieldPosition'] ?></label><input type="number" name="position" id="edit_position" class="input" min="1" required></div><?php endif; ?>
          <?php if (!empty($storefrontColumns['multi_month'])): ?><div class="field"><label><input type="checkbox" name="multi_month" id="<?= $edit ? 'edit_multi_month' : '' ?>" value="1"> <?= $textbotlang['panel']['storeMultiMonth'] ?></label></div><?php endif; ?>
          <?php if (!empty($storefrontColumns['inventory_enabled'])): ?><div class="field"><label><input type="checkbox" name="inventory_enabled" id="<?= $edit ? 'edit_inventory_enabled' : '' ?>" value="1"> <?= $textbotlang['panel']['storeInventoryEnabled'] ?></label></div><?php endif; ?>
          <?php if (!empty($storefrontColumns['tax_included'])): ?><div class="field"><label><input type="checkbox" name="tax_included" id="<?= $edit ? 'edit_tax_included' : '' ?>" value="1" checked> <?= $textbotlang['panel']['storeTaxIncluded'] ?></label></div><?php endif; ?>
          <div class="field full"><label><?= $textbotlang['panel']['productUnlimitedLabel'] ?></label><textarea name="note_product" id="<?= $edit ? 'edit_note' : '' ?>" class="input catalog-note" rows="3"></textarea></div>
          <?php if ($storefrontColumns['storefront_image']): ?><div class="field full"><label><?= $textbotlang['panel']['catalogImage'] ?></label><div class="catalog-upload"><img id="<?= $edit ? 'edit_image_preview' : 'add_image_preview' ?>" class="catalog-preview is-empty" alt=""><div><input type="file" name="product_image" accept="image/jpeg,image/png,image/webp" data-image-input data-preview="<?= $edit ? 'edit_image_preview' : 'add_image_preview' ?>"><small><?= $textbotlang['panel']['catalogImageHint'] ?></small></div></div></div><?php endif; ?>
          <div class="field full"><div class="catalog-rate-note"><?= $textbotlang['panel']['catalogRateHelp'] ?>: <strong><?= $usdtRate ? number_format($usdtRate) . ' ' . $textbotlang['panel']['catalogToman'] : $textbotlang['panel']['catalogRateOffline'] ?></strong></div></div>
        </div>
      </div>
      <div class="modal-foot"><button type="submit" class="btn btn-primary"><?= icon('check', 13) ?><?= $textbotlang['panel']['categorySaveChangeBtn'] ?></button><button type="button" class="btn btn-ghost" onclick="closeModal('<?= $id ?>')"><?= $textbotlang['panel']['categoryCancelBtn'] ?></button></div>
    </form>
  </div>
</div>
<?php }
catalog_form_modal('addModal', $textbotlang['panel']['productFieldProductType'], false, $categories, $panels, $textbotlang, $storefrontColumns, $usdtRate);
catalog_form_modal('editModal', $textbotlang['panel']['productDetailTitle'], true, $categories, $panels, $textbotlang, $storefrontColumns, $usdtRate);
?>
<script>window.CATALOG_USDT_RATE = <?= json_encode($usdtRate) ?>;</script>
<script src="js/product.js"></script>
<?php include __DIR__ . '/inc/layout_foot.php'; ?>
