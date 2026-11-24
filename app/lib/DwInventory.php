<?php
declare(strict_types=1);
// Inventory foundation (2026-11-13, docs/sql/005_inventory.sql). STAGING ONLY until approved.
// inventory_movement is the single source of truth (insert-only, signed quantity). inventory_balance is a cache kept
// in the same transaction as each movement and rebuildable from the movements. product_variant.stock is the
// storefront mirror: SUM(on_hand - reserved) over the variant's active locations, refreshed after every movement.
// Every function takes the caller's PDO and brand; nothing here reads the Host or a session (the /store endpoints
// in lib/DwStore.php authorize first). Lots, serials and bins are stored if given but not used yet.
require_once '/var/www/html/lib/Dw.php';

final class DwInventory
{
    public const TYPES = ['receipt', 'sale', 'adjustment', 'transfer', 'return'];
    public const LOCATION_TYPES = ['store', 'warehouse', 'supplier', 'virtual', 'other'];

    private static function q(PDO $db, string $sql, array $a = []): PDOStatement
    {
        $s = $db->prepare($sql);
        $s->execute($a);
        return $s;
    }

    private static function now3(): string
    {
        return (new DateTime('now'))->format('Y-m-d H:i:s.v');
    }

    // Default location of a store (brand + company). Created ("Main", type store) when the store has none.
    // Must run inside the caller's transaction (it may insert).
    public static function defaultLocation(PDO $db, string $brand, string $companyId, string $by): array
    {
        $l = self::q($db, 'SELECT * FROM inventory_location WHERE brand = ? AND company_id = ? AND is_default = 1 LIMIT 1', [$brand, $companyId])->fetch(PDO::FETCH_ASSOC);
        if ($l) {
            return $l;
        }
        $l = self::q($db, 'SELECT * FROM inventory_location WHERE brand = ? AND company_id = ? AND is_active = 1 ORDER BY created LIMIT 1', [$brand, $companyId])->fetch(PDO::FETCH_ASSOC);
        if ($l) {
            Dw::history($db, 'inventory_location', (string)$l['id'], 'UPDATE');
            self::q($db, 'UPDATE inventory_location SET is_default = 1, last_updated = NOW(), last_updated_by = ? WHERE id = ?', [$by, $l['id']]);
            $l['is_default'] = 1;
            return $l;
        }
        return self::createLocation($db, $brand, $companyId, 'Main', 'store', true, $by);
    }

    public static function createLocation(PDO $db, string $brand, string $companyId, string $name, string $type, bool $default, string $by): array
    {
        $id = Dw::randId();
        self::q($db, 'INSERT INTO inventory_location (id, brand, company_id, name, type, is_active, is_default, created, created_by) VALUES (?, ?, ?, ?, ?, 1, ?, NOW(), ?)',
            [$id, $brand, $companyId, $name, $type, $default ? 1 : 0, $by]);
        Dw::history($db, 'inventory_location', $id, 'INSERT');
        return self::q($db, 'SELECT * FROM inventory_location WHERE id = ?', [$id])->fetch(PDO::FETCH_ASSOC);
    }

    // Record one movement and update the balance cache and the variant's stock mirror, all in one transaction.
    // $m: brand, variant_id, location_id, quantity (signed, not 0), movement_type, created_by; optional reference_type,
    // reference_id, unit_cost (string/float), notes, lot_id, serial, bin, allow_negative (default false).
    // Joins the caller's transaction if there is one, otherwise opens and commits its own.
    // Returns ['movement_id', 'on_hand' (this location, after), 'stock' (variant mirror, after)].
    public static function recordMovement(PDO $db, array $m): array
    {
        $own = !$db->inTransaction();
        if ($own) {
            $db->beginTransaction();
        }
        try {
            $brand = (string)$m['brand'];
            $qty = (int)$m['quantity'];
            if ($qty === 0) {
                throw new InvalidArgumentException('quantity must not be 0');
            }
            if (!in_array($m['movement_type'], self::TYPES, true)) {
                throw new InvalidArgumentException('movement_type must be one of ' . implode(', ', self::TYPES));
            }
            // Lock the variant first so concurrent movements on it run one after the other.
            $v = self::q($db, 'SELECT id, product_id FROM product_variant WHERE id = ? AND brand = ? FOR UPDATE', [$m['variant_id'], $brand])->fetch(PDO::FETCH_ASSOC);
            if (!$v) {
                throw new DomainException('variant not found on this brand');
            }
            $loc = self::q($db, 'SELECT id, company_id, is_active FROM inventory_location WHERE id = ? AND brand = ?', [$m['location_id'], $brand])->fetch(PDO::FETCH_ASSOC);
            if (!$loc) {
                throw new DomainException('location not found on this brand');
            }
            $now = self::now3();
            self::q($db, 'INSERT INTO inventory_balance (product_id, variant_id, location_id, brand, company_id, quantity_on_hand, quantity_reserved, updated) VALUES (?, ?, ?, ?, ?, 0, 0, ?)
                ON DUPLICATE KEY UPDATE updated = updated', [$v['product_id'], $v['id'], $loc['id'], $brand, $loc['company_id'], $now]);
            $bal = self::q($db, 'SELECT quantity_on_hand FROM inventory_balance WHERE product_id = ? AND variant_id = ? AND location_id = ? FOR UPDATE', [$v['product_id'], $v['id'], $loc['id']])->fetch(PDO::FETCH_ASSOC);
            $after = (int)$bal['quantity_on_hand'] + $qty;
            if ($after < 0 && empty($m['allow_negative'])) {
                throw new LengthException('only ' . (int)$bal['quantity_on_hand'] . ' on hand at this location');
            }
            $id = Dw::randId();
            self::q($db, 'INSERT INTO inventory_movement (id, brand, company_id, product_id, variant_id, location_id, quantity, movement_type, reference_type, reference_id, unit_cost, notes, lot_id, serial, bin, created, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
                $id, $brand, $loc['company_id'], $v['product_id'], $v['id'], $loc['id'], $qty, $m['movement_type'],
                $m['reference_type'] ?? null, $m['reference_id'] ?? null, isset($m['unit_cost']) ? (string)$m['unit_cost'] : null, $m['notes'] ?? null,
                $m['lot_id'] ?? null, $m['serial'] ?? null, $m['bin'] ?? null, $now, (string)$m['created_by']]);
            self::q($db, 'UPDATE inventory_balance SET quantity_on_hand = quantity_on_hand + ?, updated = ? WHERE product_id = ? AND variant_id = ? AND location_id = ?',
                [$qty, $now, $v['product_id'], $v['id'], $loc['id']]);
            $stock = self::syncVariantStock($db, $brand, (string)$v['id'], (string)$m['created_by']);
            if ($own) {
                $db->commit();
            }
            return ['movement_id' => $id, 'on_hand' => $after, 'stock' => $stock];
        } catch (Throwable $e) {
            if ($own && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    // product_variant.stock := SUM(on_hand - reserved) over active locations (with history, only when it changes).
    public static function syncVariantStock(PDO $db, string $brand, string $variantId, string $by): int
    {
        $avail = (int)self::q($db, 'SELECT COALESCE(SUM(b.quantity_on_hand - b.quantity_reserved), 0) FROM inventory_balance b JOIN inventory_location l ON l.id = b.location_id
            WHERE b.brand = ? AND b.variant_id = ? AND l.is_active = 1', [$brand, $variantId])->fetchColumn();
        $cur = self::q($db, 'SELECT stock FROM product_variant WHERE id = ? AND brand = ?', [$variantId, $brand])->fetchColumn();
        if ($cur === null || $cur === false || (int)$cur !== $avail) {
            Dw::history($db, 'product_variant', $variantId, 'UPDATE');
            self::q($db, 'UPDATE product_variant SET stock = ?, last_updated = NOW(), last_updated_by = ? WHERE id = ? AND brand = ?', [$avail, $by, $variantId, $brand]);
        }
        return $avail;
    }

    // True once the variant has any balance row (inventory owns its stock from then on).
    public static function tracked(PDO $db, string $brand, string $variantId): bool
    {
        return (bool)self::q($db, 'SELECT 1 FROM inventory_balance WHERE brand = ? AND variant_id = ? LIMIT 1', [$brand, $variantId])->fetchColumn();
    }

    // Per-location stock for a variant (or all variants of a product): on_hand, reserved, available, plus totals.
    public static function getStock(PDO $db, string $brand, ?string $productId, ?string $variantId, ?string $companyId = null): array
    {
        $w = ['b.brand = ?'];
        $a = [$brand];
        if ($variantId !== null) {
            $w[] = 'b.variant_id = ?';
            $a[] = $variantId;
        }
        if ($productId !== null) {
            $w[] = 'b.product_id = ?';
            $a[] = $productId;
        }
        if ($companyId !== null) {
            $w[] = 'b.company_id = ?';
            $a[] = $companyId;
        }
        $rows = self::q($db, 'SELECT b.product_id, b.variant_id, b.location_id, l.name AS location_name, l.is_active, b.company_id, b.quantity_on_hand, b.quantity_reserved, b.updated
            FROM inventory_balance b JOIN inventory_location l ON l.id = b.location_id WHERE ' . implode(' AND ', $w) . ' ORDER BY b.product_id, b.variant_id, l.name', $a)->fetchAll(PDO::FETCH_ASSOC);
        $on = 0;
        $res = 0;
        foreach ($rows as &$r) {
            $r['quantity_on_hand'] = (int)$r['quantity_on_hand'];
            $r['quantity_reserved'] = (int)$r['quantity_reserved'];
            $r['available'] = $r['quantity_on_hand'] - $r['quantity_reserved'];
            $r['is_active'] = (bool)$r['is_active'];
            if ($r['is_active']) {
                $on += $r['quantity_on_hand'];
                $res += $r['quantity_reserved'];
            }
        }
        unset($r);
        return ['locations' => $rows, 'on_hand' => $on, 'reserved' => $res, 'available' => $on - $res];
    }

    public static function getAvailable(PDO $db, string $brand, string $variantId, ?string $locationId = null): int
    {
        if ($locationId !== null) {
            return (int)self::q($db, 'SELECT COALESCE(SUM(quantity_on_hand - quantity_reserved), 0) FROM inventory_balance WHERE brand = ? AND variant_id = ? AND location_id = ?', [$brand, $variantId, $locationId])->fetchColumn();
        }
        return self::getStock($db, $brand, null, $variantId)['available'];
    }

    // Recompute the balance cache from the movements for a brand (optionally one store). Keeps quantity_reserved.
    // Returns ['checked' => rows compared, 'fixed' => [diffs]]. Runs in its own transaction.
    public static function rebuildBalances(PDO $db, string $brand, ?string $companyId, string $by): array
    {
        $db->beginTransaction();
        try {
            $cw = $companyId !== null ? ' AND company_id = ?' : '';
            $ca = $companyId !== null ? [$brand, $companyId] : [$brand];
            $truth = [];
            foreach (self::q($db, 'SELECT product_id, variant_id, location_id, company_id, SUM(quantity) AS q FROM inventory_movement WHERE brand = ?' . $cw . ' GROUP BY product_id, variant_id, location_id, company_id', $ca)->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $truth[$r['product_id'] . '|' . $r['variant_id'] . '|' . $r['location_id']] = $r;
            }
            $cache = [];
            foreach (self::q($db, 'SELECT product_id, variant_id, location_id, company_id, quantity_on_hand FROM inventory_balance WHERE brand = ?' . $cw . ' FOR UPDATE', $ca)->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $cache[$r['product_id'] . '|' . $r['variant_id'] . '|' . $r['location_id']] = $r;
            }
            $fixed = [];
            $variants = [];
            $now = self::now3();
            foreach ($truth + $cache as $k => $r) {
                $want = isset($truth[$k]) ? (int)$truth[$k]['q'] : 0;
                $have = isset($cache[$k]) ? (int)$cache[$k]['quantity_on_hand'] : null;
                if ($have === $want) {
                    continue;
                }
                if ($have === null) {
                    self::q($db, 'INSERT INTO inventory_balance (product_id, variant_id, location_id, brand, company_id, quantity_on_hand, quantity_reserved, updated) VALUES (?, ?, ?, ?, ?, ?, 0, ?)',
                        [$r['product_id'], $r['variant_id'], $r['location_id'], $brand, $r['company_id'], $want, $now]);
                } else {
                    self::q($db, 'UPDATE inventory_balance SET quantity_on_hand = ?, updated = ? WHERE product_id = ? AND variant_id = ? AND location_id = ?', [$want, $now, $r['product_id'], $r['variant_id'], $r['location_id']]);
                }
                $fixed[] = ['variant_id' => $r['variant_id'], 'location_id' => $r['location_id'], 'cache_was' => $have, 'movements_say' => $want];
                $variants[$r['variant_id']] = true;
            }
            foreach (array_keys($variants) as $vid) {
                self::syncVariantStock($db, $brand, (string)$vid, $by);
            }
            $db->commit();
            return ['checked' => count($truth + $cache), 'fixed' => $fixed];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}
