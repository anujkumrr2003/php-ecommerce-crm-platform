<?php
/* inc/catalog.php - reading products, categories and reviews for the storefront */

function cat_base_sql(): string
{
    return "SELECT p.id, p.name, p.sku, p.category, p.brand, p.fabric, p.mrp, p.price, p.gst, p.description, p.created_at,
        COALESCE(m.is_featured,0) AS is_featured, COALESCE(m.is_new,0) AS is_new, COALESCE(m.is_bestseller,0) AS is_bestseller,
        COALESCE(m.short_description,'') AS short_description, COALESCE(m.meta_title,'') AS meta_title, COALESCE(m.meta_description,'') AS meta_description, COALESCE(m.sort_order,0) AS sort_order,
        (SELECT COALESCE(SUM(v.stock),0) FROM product_variants v WHERE v.product_id = p.id) AS stock,
        (SELECT MIN(COALESCE(v.price, p.price)) FROM product_variants v WHERE v.product_id = p.id AND v.stock > 0) AS min_price,
        (SELECT MAX(COALESCE(v.price, p.price)) FROM product_variants v WHERE v.product_id = p.id AND v.stock > 0) AS max_price,
        (SELECT i.file_name FROM product_images i WHERE i.product_id = p.id ORDER BY i.sort_order, i.id LIMIT 1) AS img1,
        (SELECT i.file_name FROM product_images i WHERE i.product_id = p.id ORDER BY i.sort_order, i.id LIMIT 1 OFFSET 1) AS img2,
        (SELECT ROUND(AVG(r.rating),1) FROM web_reviews r WHERE r.product_id = p.id AND r.status = 'approved') AS rating,
        (SELECT COUNT(*) FROM web_reviews r WHERE r.product_id = p.id AND r.status = 'approved') AS rating_count,
        (SELECT COALESCE(SUM(oi.qty),0) FROM web_order_items oi WHERE oi.product_id = p.id) AS sold
      FROM products p LEFT JOIN web_product_meta m ON m.product_id = p.id LEFT JOIN web_categories c ON c.name = p.category
      WHERE p.status = 'Active' AND COALESCE(m.show_on_website,1) = 1 AND COALESCE(c.is_active,1) = 1";
}

function cat_post(array $p): array
{
    $p['eff_price'] = (float)($p['min_price'] ?? $p['price']);
    return $p;
}

/**
 * @param array $f cat (slug), q, min, max, sizes[], colors[], avail (1), sale (1), flag (featured|new|best), sort, page, per, exclude (id)
 * @return array{0:array,1:int} [rows, total]
 */
function products_list(array $f): array
{
    $where = ['1=1'];
    $ty = '';
    $val = [];
    if (!empty($f['cat'])) {
        $where[] = 't.category = (SELECT name FROM web_categories WHERE slug = ? LIMIT 1)';
        $ty .= 's';
        $val[] = $f['cat'];
    }
    if (!empty($f['category'])) {
        $where[] = 't.category = ?';
        $ty .= 's';
        $val[] = $f['category'];
    }
    if (($f['q'] ?? '') !== '') {
        foreach (preg_split('/\s+/', trim((string)$f['q'])) as $w) {
            if ($w === '') {
                continue;
            }
            $like = '%' . addcslashes($w, '%_\\') . '%';
            $where[] = '(t.name LIKE ? OR t.category LIKE ? OR t.brand LIKE ? OR t.sku LIKE ? OR t.fabric LIKE ? OR t.short_description LIKE ?)';
            $ty .= 'ssssss';
            array_push($val, $like, $like, $like, $like, $like, $like);
        }
    }
    if (($f['min'] ?? '') !== '' && is_numeric($f['min'])) {
        $where[] = 'COALESCE(t.min_price, t.price) >= ?';
        $ty .= 'd';
        $val[] = (float)$f['min'];
    }
    if (($f['max'] ?? '') !== '' && is_numeric($f['max'])) {
        $where[] = 'COALESCE(t.min_price, t.price) <= ?';
        $ty .= 'd';
        $val[] = (float)$f['max'];
    }
    foreach (['sizes' => 'size', 'colors' => 'color'] as $key => $col) {
        $list = array_values(array_filter(array_map('strval', (array)($f[$key] ?? [])), 'strlen'));
        if ($list) {
            $where[] = "EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = t.id AND v.stock > 0 AND v.$col IN (" . implode(',', array_fill(0, count($list), '?')) . '))';
            $ty .= str_repeat('s', count($list));
            array_push($val, ...$list);
        }
    }
    if (!empty($f['avail'])) {
        $where[] = 't.stock > 0';
    }
    if (!empty($f['sale'])) {
        $where[] = 't.mrp > COALESCE(t.min_price, t.price)';
    }
    if (!empty($f['flag'])) {
        $col = ['featured' => 'is_featured', 'new' => 'is_new', 'best' => 'is_bestseller'][$f['flag']] ?? '';
        if ($col !== '') {
            $where[] = "t.$col = 1";
        }
    }
    if (!empty($f['exclude'])) {
        $where[] = 't.id <> ?';
        $ty .= 'i';
        $val[] = (int)$f['exclude'];
    }
    $order = [
        'new'        => 't.id DESC', 'price_asc' => 'COALESCE(t.min_price, t.price) ASC, t.id DESC', 'price_desc' => 'COALESCE(t.min_price, t.price) DESC, t.id DESC',
        'name'       => 't.name ASC', 'popular' => 't.sold DESC, t.id DESC', 'rating' => 't.rating DESC, t.rating_count DESC, t.id DESC',
    ][$f['sort'] ?? ''] ?? 't.sort_order ASC, t.is_featured DESC, t.id DESC';
    $per  = max(1, min(60, (int)($f['per'] ?? 12)));
    $page = max(1, (int)($f['page'] ?? 1));
    $base = '(' . cat_base_sql() . ') t WHERE ' . implode(' AND ', $where);
    $total = (int)(wc_one('SELECT COUNT(*) AS c FROM ' . $base, $ty, $val)['c'] ?? 0);
    $rows = wc_all('SELECT t.* FROM ' . $base . ' ORDER BY ' . $order . ' LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), $ty, $val);
    return [array_map('cat_post', $rows), $total];
}

function product_get(int $id): ?array
{
    $r = wc_one('SELECT t.* FROM (' . cat_base_sql() . ') t WHERE t.id = ?', 'i', [$id]);
    return $r ? cat_post($r) : null;
}

function product_variants(int $id): array
{
    return wc_all('SELECT id, size, color, sku, stock, COALESCE(price, ?) AS price FROM product_variants WHERE product_id = ? ORDER BY id', 'di', [(float)(wc_one('SELECT price FROM products WHERE id = ?', 'i', [$id])['price'] ?? 0), $id]);
}

function product_images(int $id): array
{
    return array_column(wc_all('SELECT file_name FROM product_images WHERE product_id = ? ORDER BY sort_order, id', 'i', [$id]), 'file_name');
}

function product_url(array $p): string
{
    return url('product/' . (int)$p['id'] . '-' . wc_slug((string)$p['name'], 'product'));
}

/** price information for a card / detail page */
function price_info(array $p): array
{
    $price = (float)($p['min_price'] ?? $p['price']);
    $max   = (float)($p['max_price'] ?? $price);
    $mrp   = (float)$p['mrp'];
    return ['price' => $price, 'mrp' => $mrp > $price ? $mrp : 0.0, 'from' => $max > $price + 0.001, 'off' => $mrp > $price ? (int)round(($mrp - $price) * 100 / $mrp) : 0];
}

function categories_all(bool $menuOnly = false): array
{
    static $cache = null;
    if ($cache === null) {
        wc_sync_categories();                                   // a product with a new category gets its category row automatically
        $cache = wc_all("SELECT c.*, (SELECT COUNT(*) FROM products p LEFT JOIN web_product_meta m ON m.product_id = p.id WHERE p.category = c.name AND p.status = 'Active' AND COALESCE(m.show_on_website,1) = 1) AS n
                           FROM web_categories c WHERE c.is_active = 1 ORDER BY c.sort_order, c.name");
        $cache = array_values(array_filter($cache, fn($c) => (int)$c['n'] > 0));
    }
    return $menuOnly ? array_values(array_filter($cache, fn($c) => (int)$c['show_in_menu'])) : $cache;
}

function category_by_slug(string $slug): ?array
{
    foreach (categories_all() as $c) {
        if ($c['slug'] === $slug) {
            return $c;
        }
    }
    return null;
}

function wishlist_ids(): array
{
    static $ids = null;
    if ($ids === null) {
        $ids = [];
        if ($u = user()) {
            $ids = array_map('intval', array_column(wc_all('SELECT product_id FROM web_wishlist WHERE web_user_id = ?', 'i', [(int)$u['id']]), 'product_id'));
        }
    }
    return $ids;
}

function product_reviews(int $id, int $limit = 20): array
{
    return wc_all("SELECT * FROM web_reviews WHERE product_id = ? AND status = 'approved' ORDER BY id DESC LIMIT " . (int)$limit, 'i', [$id]);
}

function rating_breakdown(int $id): array
{
    $out = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
    foreach (wc_all("SELECT rating, COUNT(*) c FROM web_reviews WHERE product_id = ? AND status = 'approved' GROUP BY rating", 'i', [$id]) as $r) {
        $out[(int)$r['rating']] = (int)$r['c'];
    }
    return $out;
}
