'use client';

import { Card } from '@/components/ui/card';
import { adjustStock, createCategory, createProduct, deleteProduct, fetchCategories, fetchProducts, updateProduct } from '@/lib/api';
import type { Product } from '@/lib/types';
import { useAuthStore } from '@/store/auth-store';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';

// ─── helpers ──────────────────────────────────────────────────────────────────
function stockBadge(stock: number) {
  if (stock <= 0) return { label: 'Out of Stock', color: '#ef4444', bg: 'rgba(239,68,68,0.15)' };
  if (stock <= 5)  return { label: 'Critical',    color: '#f97316', bg: 'rgba(249,115,22,0.15)' };
  if (stock <= 15) return { label: 'Low',         color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' };
  return                  { label: 'Healthy',     color: '#25d366', bg: 'rgba(37,211,102,0.15)' };
}

const inputCls = 'w-full rounded-xl border px-3.5 py-2.5 text-sm outline-none transition focus:border-[var(--accent)] focus:ring-2 focus:ring-[var(--accent)]/20';
const inputStyle = { background: 'rgba(0,0,0,0.30)', borderColor: 'var(--card-border)', color: 'var(--text-primary)' };
const labelStyle = { color: 'var(--text-muted)' };

// ─── Add / Edit product modal ─────────────────────────────────────────────────
function ProductModal({
  product, retailerId, categories, onClose,
}: {
  product: Product | null;
  retailerId: number;
  categories: Array<{ id: number; name: string }>;
  onClose: () => void;
}) {
  const qc = useQueryClient();
  const isEdit = !!product;
  const [form, setForm] = useState({
    name: product?.name ?? '',
    sku: product?.sku ?? '',
    brand: product?.brand ?? '',
    unit: product?.unit ?? '',
    price: String(product?.price ?? ''),
    stock: String(product?.stock ?? '0'),
    category_id: String(product?.category_id ?? ''),
    is_active: product?.is_active ?? true,
  });
  const [error, setError] = useState('');
  const [newCatName, setNewCatName] = useState('');
  const [addingCat, setAddingCat] = useState(false);

  const mutation = useMutation({
    mutationFn: () => {
      const payload = {
        retailer_id: retailerId,
        name: form.name,
        sku: form.sku || null,
        brand: form.brand || null,
        unit: form.unit || null,
        price: parseFloat(form.price),
        stock: parseInt(form.stock),
        category_id: form.category_id ? parseInt(form.category_id) : null,
        is_active: form.is_active,
      };
      return isEdit ? updateProduct(product!.id, payload) : createProduct(payload);
    },
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['products'] }); onClose(); },
    onError: () => setError('Failed to save product. Check all required fields.'),
  });

  function set(k: string, v: string | boolean) { setForm((f) => ({ ...f, [k]: v })); }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm">
      <div className="w-full max-w-lg rounded-2xl border p-6 shadow-2xl" style={{ background: '#0b2213', borderColor: 'var(--card-border)' }}>
        <div className="mb-5 flex items-center justify-between">
          <h2 className="text-lg font-semibold" style={{ color: 'var(--text-primary)' }}>{isEdit ? 'Edit Product' : 'Add Product'}</h2>
          <button onClick={onClose} className="text-xl leading-none" style={{ color: 'var(--text-muted)' }}>✕</button>
        </div>
        <div className="grid gap-3 sm:grid-cols-2">
          <div className="sm:col-span-2">
            <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wider" style={labelStyle}>Product Name *</label>
            <input className={inputCls} style={inputStyle} value={form.name} onChange={(e) => set('name', e.target.value)} placeholder="Almarai Full Fat Milk 1L" />
          </div>
          <div>
            <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wider" style={labelStyle}>SKU</label>
            <input className={inputCls} style={inputStyle} value={form.sku} onChange={(e) => set('sku', e.target.value)} placeholder="MILK-1L" />
          </div>
          <div>
            <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wider" style={labelStyle}>Brand</label>
            <input className={inputCls} style={inputStyle} value={form.brand} onChange={(e) => set('brand', e.target.value)} placeholder="Almarai" />
          </div>
          <div>
            <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wider" style={labelStyle}>Price (AED) *</label>
            <input type="number" step="0.01" className={inputCls} style={inputStyle} value={form.price} onChange={(e) => set('price', e.target.value)} placeholder="9.95" />
          </div>
          <div>
            <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wider" style={labelStyle}>Initial Stock *</label>
            <input type="number" className={inputCls} style={inputStyle} value={form.stock} onChange={(e) => set('stock', e.target.value)} placeholder="50" />
          </div>
          <div>
            <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wider" style={labelStyle}>Unit</label>
            <input className={inputCls} style={inputStyle} value={form.unit} onChange={(e) => set('unit', e.target.value)} placeholder="1L, 500g, piece…" />
          </div>
          <div>
            <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wider" style={labelStyle}>Category</label>
            <div className="flex gap-2">
              <select className={inputCls} style={inputStyle} value={form.category_id} onChange={(e) => set('category_id', e.target.value)}>
                <option value="">— None —</option>
                {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
              </select>
              {!addingCat ? (
                <button
                  type="button"
                  onClick={() => setAddingCat(true)}
                  className="shrink-0 rounded-xl border px-3 text-xs font-medium transition hover:border-[var(--accent)]"
                  style={{ borderColor: 'var(--card-border)', background: 'rgba(0,0,0,0.20)', color: 'var(--text-muted)' }}
                  title="Add new category"
                >
                  + New
                </button>
              ) : (
                <div className="flex shrink-0 gap-1">
                  <input
                    autoFocus
                    value={newCatName}
                    onChange={(e) => setNewCatName(e.target.value)}
                    placeholder="Category name"
                    className="w-32 rounded-xl border px-2.5 py-2 text-xs outline-none focus:border-[var(--accent)]"
                    style={{ background: 'rgba(0,0,0,0.30)', borderColor: 'var(--card-border)', color: 'var(--text-primary)' }}
                    onKeyDown={async (e) => {
                      if (e.key === 'Enter' && newCatName.trim()) {
                        const created = await createCategory(newCatName.trim(), retailerId);
                        await qc.invalidateQueries({ queryKey: ['categories'] });
                        set('category_id', String(created.id));
                        setNewCatName('');
                        setAddingCat(false);
                      } else if (e.key === 'Escape') {
                        setAddingCat(false);
                        setNewCatName('');
                      }
                    }}
                  />
                  <button
                    type="button"
                    onClick={async () => {
                      if (!newCatName.trim()) return;
                      const created = await createCategory(newCatName.trim(), retailerId);
                      await qc.invalidateQueries({ queryKey: ['categories'] });
                      set('category_id', String(created.id));
                      setNewCatName('');
                      setAddingCat(false);
                    }}
                    className="rounded-xl px-2.5 text-xs font-semibold"
                    style={{ background: 'var(--accent)', color: 'var(--accent-foreground)' }}
                  >
                    ✓
                  </button>
                  <button
                    type="button"
                    onClick={() => { setAddingCat(false); setNewCatName(''); }}
                    className="rounded-xl px-2 text-xs"
                    style={{ color: 'var(--text-muted)' }}
                  >
                    ✕
                  </button>
                </div>
              )}
            </div>
          </div>
          <div className="sm:col-span-2 flex items-center gap-3 rounded-xl border px-4 py-3" style={{ borderColor: 'var(--card-border)', background: 'rgba(0,0,0,0.20)' }}>
            <span className="flex-1 text-sm" style={{ color: 'var(--text-medium)' }}>Active (visible to WhatsApp bot)</span>
            <button type="button" role="switch" aria-checked={form.is_active} onClick={() => set('is_active', !form.is_active)}
              className="relative inline-flex h-6 w-11 rounded-full border-2 border-transparent transition-colors"
              style={{ background: form.is_active ? 'var(--accent)' : 'rgba(255,255,255,0.15)' }}>
              <span className="pointer-events-none inline-block size-5 transform rounded-full bg-white shadow transition-transform"
                style={{ transform: form.is_active ? 'translateX(20px)' : 'translateX(0)' }} />
            </button>
          </div>
        </div>
        {error && <p className="mt-3 rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2 text-sm text-red-400">{error}</p>}
        <div className="mt-5 flex justify-end gap-3">
          <button onClick={onClose} className="rounded-xl px-4 py-2 text-sm font-medium" style={{ background: 'rgba(255,255,255,0.08)', color: 'var(--text-medium)' }}>Cancel</button>
          <button onClick={() => mutation.mutate()} disabled={mutation.isPending}
            className="rounded-xl px-5 py-2 text-sm font-semibold disabled:opacity-60"
            style={{ background: 'var(--accent)', color: 'var(--accent-foreground)' }}>
            {mutation.isPending ? 'Saving…' : isEdit ? 'Save Changes' : 'Add Product'}
          </button>
        </div>
      </div>
    </div>
  );
}

// ─── Stock adjust modal ───────────────────────────────────────────────────────
function StockModal({ product, onClose }: { product: Product; onClose: () => void }) {
  const qc = useQueryClient();
  const [adjustment, setAdjustment] = useState('');
  const [reason, setReason] = useState('');
  const [error, setError] = useState('');

  const mutation = useMutation({
    mutationFn: () => adjustStock(product.id, parseInt(adjustment), reason || undefined),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['products'] }); onClose(); },
    onError: () => setError('Adjustment failed.'),
  });

  const preview = product.stock + (parseInt(adjustment) || 0);

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm">
      <div className="w-full max-w-sm rounded-2xl border p-6 shadow-2xl" style={{ background: '#0b2213', borderColor: 'var(--card-border)' }}>
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Adjust Stock</h2>
          <button onClick={onClose} style={{ color: 'var(--text-muted)' }}>✕</button>
        </div>
        <div className="mb-4 rounded-xl border px-4 py-3" style={{ borderColor: 'var(--card-border)', background: 'rgba(37,211,102,0.06)' }}>
          <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{product.name}</p>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Current stock: <strong style={{ color: 'var(--accent)' }}>{product.stock}</strong></p>
        </div>
        <div className="space-y-3">
          <div>
            <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wider" style={labelStyle}>
              Adjustment <span style={{ color: 'var(--text-muted)' }}>(+ to add, − to remove)</span>
            </label>
            <input type="number" className={inputCls} style={inputStyle} value={adjustment}
              onChange={(e) => setAdjustment(e.target.value)} placeholder="+50 or -5" />
            {adjustment && (
              <p className="mt-1 text-xs" style={{ color: preview < 0 ? '#ef4444' : 'var(--accent)' }}>
                → New stock: {Math.max(0, preview)}
              </p>
            )}
          </div>
          <div>
            <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wider" style={labelStyle}>Reason</label>
            <select className={inputCls} style={inputStyle} value={reason} onChange={(e) => setReason(e.target.value)}>
              <option value="">— Select reason —</option>
              <option value="restock">Restock / Delivery received</option>
              <option value="damage">Damage / Spoilage</option>
              <option value="correction">Stock count correction</option>
              <option value="manual_adjustment">Manual adjustment</option>
            </select>
          </div>
        </div>
        {error && <p className="mt-3 text-sm text-red-400">{error}</p>}
        <div className="mt-5 flex justify-end gap-3">
          <button onClick={onClose} className="rounded-xl px-4 py-2 text-sm" style={{ background: 'rgba(255,255,255,0.08)', color: 'var(--text-medium)' }}>Cancel</button>
          <button onClick={() => mutation.mutate()} disabled={!adjustment || mutation.isPending}
            className="rounded-xl px-5 py-2 text-sm font-semibold disabled:opacity-50"
            style={{ background: 'var(--accent)', color: 'var(--accent-foreground)' }}>
            {mutation.isPending ? 'Saving…' : 'Apply Adjustment'}
          </button>
        </div>
      </div>
    </div>
  );
}

// ─── Main page ────────────────────────────────────────────────────────────────
export default function InventoryPage() {
  const user = useAuthStore((s) => s.user);
  const retailerId = user?.retailer_id ?? 1;
  const [search, setSearch] = useState('');
  const [editProduct, setEditProduct] = useState<Product | null | 'new'>( null);
  const [stockProduct, setStockProduct] = useState<Product | null>(null);
  const qc = useQueryClient();

  const { data: productsData, isLoading } = useQuery({
    queryKey: ['products', retailerId, search],
    queryFn: () => fetchProducts(retailerId, search),
  });

  const { data: categories = [] } = useQuery({
    queryKey: ['categories'],
    queryFn: fetchCategories,
  });

  const deleteMutation = useMutation({
    mutationFn: deleteProduct,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['products'] }),
  });

  const products: Product[] = productsData?.data ?? [];
  const lowStockCount = products.filter((p) => p.stock <= 15).length;

  return (
    <div className="space-y-5">
      {/* Header bar */}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-xl font-semibold" style={{ color: 'var(--text-primary)' }}>Inventory</h2>
          <p className="text-sm" style={{ color: 'var(--text-muted)' }}>
            {products.length} products
            {lowStockCount > 0 && (
              <span className="ml-2 rounded-full px-2 py-0.5 text-xs font-medium" style={{ background: 'rgba(249,115,22,0.20)', color: '#f97316' }}>
                {lowStockCount} low stock
              </span>
            )}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search products…"
            className="rounded-xl border px-3.5 py-2 text-sm outline-none focus:border-[var(--accent)] focus:ring-2 focus:ring-[var(--accent)]/20"
            style={{ background: 'rgba(0,0,0,0.25)', borderColor: 'var(--card-border)', color: 'var(--text-primary)', width: 200 }}
          />
          <button
            onClick={() => setEditProduct('new')}
            className="flex items-center gap-1.5 rounded-xl px-4 py-2 text-sm font-semibold"
            style={{ background: 'var(--accent)', color: 'var(--accent-foreground)' }}
          >
            + Add Product
          </button>
        </div>
      </div>

      <Card>
        {isLoading ? (
          <div className="flex h-40 items-center justify-center">
            <div className="size-8 animate-spin rounded-full border-2 border-[var(--accent)] border-t-transparent" />
          </div>
        ) : products.length === 0 ? (
          <div className="py-16 text-center">
            <p className="mb-2 text-4xl">📦</p>
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>
              {search ? 'No products match your search.' : 'No products yet. Add your first product.'}
            </p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b text-left text-xs font-semibold uppercase tracking-wider border-[var(--card-border)]" style={{ color: 'var(--text-muted)' }}>
                  <th className="py-3 pr-4">Product</th>
                  <th className="py-3 pr-4">SKU</th>
                  <th className="py-3 pr-4">Category</th>
                  <th className="py-3 pr-4">Price</th>
                  <th className="py-3 pr-4">Stock</th>
                  <th className="py-3 pr-4">Status</th>
                  <th className="py-3 pr-4">Active</th>
                  <th className="py-3">Actions</th>
                </tr>
              </thead>
              <tbody>
                {products.map((p) => {
                  const badge = stockBadge(p.stock);
                  return (
                    <tr key={p.id} className="border-b border-[var(--card-border)] transition hover:bg-[rgba(37,211,102,0.04)]">
                      <td className="py-3 pr-4">
                        <p className="font-medium" style={{ color: 'var(--text-primary)' }}>{p.name}</p>
                        {p.brand && <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{p.brand}</p>}
                      </td>
                      <td className="py-3 pr-4 font-mono text-xs" style={{ color: 'var(--text-muted)' }}>{p.sku ?? '—'}</td>
                      <td className="py-3 pr-4 text-xs" style={{ color: 'var(--text-medium)' }}>{p.category?.name ?? '—'}</td>
                      <td className="py-3 pr-4 font-semibold" style={{ color: 'var(--accent)' }}>AED {Number(p.price).toFixed(2)}</td>
                      <td className="py-3 pr-4 font-mono font-bold" style={{ color: 'var(--text-primary)' }}>{p.stock}</td>
                      <td className="py-3 pr-4">
                        <span className="rounded-full px-2.5 py-1 text-xs font-medium" style={{ background: badge.bg, color: badge.color }}>
                          {badge.label}
                        </span>
                      </td>
                      <td className="py-3 pr-4">
                        <span className={`rounded-full px-2.5 py-1 text-xs font-medium`}
                          style={{ background: p.is_active ? 'rgba(37,211,102,0.12)' : 'rgba(255,255,255,0.06)', color: p.is_active ? '#25d366' : 'var(--text-muted)' }}>
                          {p.is_active ? 'Active' : 'Hidden'}
                        </span>
                      </td>
                      <td className="py-3">
                        <div className="flex items-center gap-1.5">
                          <button onClick={() => setStockProduct(p)}
                            className="rounded-lg px-2.5 py-1.5 text-xs font-medium transition"
                            style={{ background: 'rgba(37,211,102,0.12)', color: '#25d366' }}>
                            ± Stock
                          </button>
                          <button onClick={() => setEditProduct(p)}
                            className="rounded-lg px-2.5 py-1.5 text-xs font-medium transition"
                            style={{ background: 'rgba(255,255,255,0.08)', color: 'var(--text-medium)' }}>
                            Edit
                          </button>
                          <button
                            onClick={() => { if (confirm(`Delete "${p.name}"?`)) deleteMutation.mutate(p.id); }}
                            className="rounded-lg px-2.5 py-1.5 text-xs font-medium transition"
                            style={{ background: 'rgba(239,68,68,0.12)', color: '#f87171' }}>
                            Delete
                          </button>
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {editProduct && (
        <ProductModal
          product={editProduct === 'new' ? null : editProduct}
          retailerId={retailerId}
          categories={categories}
          onClose={() => setEditProduct(null)}
        />
      )}
      {stockProduct && (
        <StockModal product={stockProduct} onClose={() => setStockProduct(null)} />
      )}
    </div>
  );
}

