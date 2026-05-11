'use client';

import { createOrder, fetchChatThread, fetchChats, fetchProducts } from '@/lib/api';
import type { Product } from '@/lib/types';
import { useAuthStore } from '@/store/auth-store';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import axios from 'axios';
import { useEffect, useRef, useState } from 'react';

// ─── Types ────────────────────────────────────────────────────────────────────
type ChatCustomer = {
  id: number;
  name: string;
  phone: string;
  messages_count: number;
  latest_message: { id: number; body: string; direction: string; created_at: string } | null;
};

type ChatMessage = { id: number; direction: string; body: string; created_at: string };

type QtyMap = Record<number, number>;

// ─── Conversation thread ──────────────────────────────────────────────────────
function ThreadPanel({ customerId, retailerId, onClose }: { customerId: number; retailerId: number; onClose: () => void }) {
  const bottomRef = useRef<HTMLDivElement>(null);
  const qc = useQueryClient();
  const [showOrderBuilder, setShowOrderBuilder] = useState(false);
  const [productSearch, setProductSearch] = useState('');
  const [selected, setSelected] = useState<QtyMap>({});
  const [orderNote, setOrderNote] = useState('');
  const [feedback, setFeedback] = useState<string | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['chat-thread', customerId],
    queryFn: () => fetchChatThread(customerId),
    refetchInterval: 10_000,
  });

  const { data: productsData, isLoading: loadingProducts } = useQuery({
    queryKey: ['chat-order-products', retailerId],
    queryFn: () => fetchProducts(retailerId),
    staleTime: 60_000,
    enabled: showOrderBuilder,
  });

  const customer = data?.customer;
  const messages: ChatMessage[] = data?.messages ?? [];
  const products: Product[] = productsData?.data ?? [];

  const filteredProducts = productSearch.trim() === ''
    ? products
    : products.filter((p) => {
        const q = productSearch.toLowerCase();
        return p.name.toLowerCase().includes(q) || (p.brand ?? '').toLowerCase().includes(q);
      });

  const selectedRows = products
    .filter((p) => (selected[p.id] ?? 0) > 0)
    .map((p) => ({
      product: p,
      qty: selected[p.id],
      lineTotal: Number(p.price) * selected[p.id],
    }));

  const totalItems = selectedRows.reduce((sum, row) => sum + row.qty, 0);
  const totalAmount = selectedRows.reduce((sum, row) => sum + row.lineTotal, 0);

  const updateQty = (productId: number, nextQty: number) => {
    setSelected((prev) => {
      const safe = Math.max(0, Math.min(99, nextQty));
      if (safe === 0) {
        const clone = { ...prev };
        delete clone[productId];
        return clone;
      }

      return {
        ...prev,
        [productId]: safe,
      };
    });
  };

  const submitOrderMutation = useMutation({
    mutationFn: async () => {
      if (!customer?.phone) {
        throw new Error('Customer phone is missing.');
      }

      const items = selectedRows.map((row) => ({
        product_id: row.product.id,
        qty: row.qty,
      }));

      if (items.length === 0) {
        throw new Error('Please add at least one item.');
      }

      return createOrder({
        retailer_id: retailerId,
        phone: customer.phone,
        items,
        notes: orderNote.trim() || undefined,
      });
    },
    onSuccess: (createdOrder) => {
      const orderId = createdOrder?.id;
      setSelected({});
      setOrderNote('');
      setFeedback(orderId ? `Order #${orderId} submitted successfully.` : 'Order submitted successfully.');
      qc.invalidateQueries({ queryKey: ['orders'] });
      qc.invalidateQueries({ queryKey: ['chats', retailerId] });
    },
    onError: (error: unknown) => {
      if (axios.isAxiosError(error)) {
        const message = (error.response?.data as { message?: string } | undefined)?.message;
        setFeedback(message ?? error.message ?? 'Could not submit order.');
        return;
      }

      if (error instanceof Error) {
        setFeedback(error.message);
        return;
      }

      setFeedback('Could not submit order.');
    },
  });

  useEffect(() => {
    bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
  }, [messages.length]);

  return (
    <div
      className="flex h-full flex-col border-l"
      style={{ borderColor: 'var(--card-border)', background: '#0b2213' }}
    >
      {/* Thread header */}
      <div
        className="flex items-center gap-3 border-b px-5 py-4"
        style={{ borderColor: 'var(--card-border)', background: '#0b2213' }}
      >
        <button
          onClick={onClose}
          className="rounded-xl p-2 text-xl leading-none transition hover:bg-white/10 lg:hidden"
          style={{ color: 'var(--text-muted)' }}
        >
          ←
        </button>
        <div
          className="flex size-10 shrink-0 items-center justify-center rounded-2xl text-sm font-bold"
          style={{ background: 'rgba(37,211,102,0.15)', color: 'var(--accent)' }}
        >
          {(customer?.name ?? 'G')[0].toUpperCase()}
        </div>
        <div className="min-w-0 flex-1">
          <p className="truncate font-semibold" style={{ color: 'var(--text-primary)' }}>
            {customer?.name ?? '…'}
          </p>
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{customer?.phone ?? ''}</p>
        </div>
        <button
          onClick={onClose}
          className="hidden rounded-xl p-2 text-xl leading-none transition hover:bg-white/10 lg:block"
          style={{ color: 'var(--text-muted)' }}
        >
          ✕
        </button>
      </div>

      <div className="border-b px-4 py-3" style={{ borderColor: 'var(--card-border)' }}>
        <button
          onClick={() => setShowOrderBuilder((prev) => !prev)}
          className="rounded-xl px-3 py-1.5 text-xs font-semibold"
          style={{
            background: showOrderBuilder ? 'rgba(37,211,102,0.18)' : 'rgba(255,255,255,0.08)',
            color: showOrderBuilder ? 'var(--accent)' : 'var(--text-medium)',
            border: showOrderBuilder ? '1px solid rgba(37,211,102,0.45)' : '1px solid var(--card-border)',
          }}
        >
          {showOrderBuilder ? 'Hide Order Builder' : 'Open Order Builder'}
        </button>
      </div>

      {showOrderBuilder && (
        <div className="border-b p-4" style={{ borderColor: 'var(--card-border)', background: 'rgba(37,211,102,0.04)' }}>
          <div className="mb-3 flex items-end gap-2">
            <div className="flex-1">
              <p className="mb-1 text-[11px] font-semibold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>
                Quick Select Products
              </p>
              <input
                value={productSearch}
                onChange={(e) => setProductSearch(e.target.value)}
                placeholder="Search products..."
                className="w-full rounded-xl border px-3 py-2 text-sm outline-none focus:border-[var(--accent)]"
                style={{ background: 'rgba(0,0,0,0.30)', borderColor: 'var(--card-border)', color: 'var(--text-primary)' }}
              />
            </div>
          </div>

          <div className="max-h-44 space-y-2 overflow-y-auto pr-1">
            {loadingProducts ? (
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Loading products…</p>
            ) : filteredProducts.length === 0 ? (
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No products found.</p>
            ) : (
              filteredProducts.slice(0, 20).map((p) => {
                const qty = selected[p.id] ?? 0;
                return (
                  <div
                    key={p.id}
                    className="flex items-center justify-between rounded-xl border px-3 py-2"
                    style={{ borderColor: 'var(--card-border)', background: 'rgba(0,0,0,0.20)' }}
                  >
                    <div className="min-w-0 pr-2">
                      <p className="truncate text-sm font-medium" style={{ color: 'var(--text-primary)' }}>{p.name}</p>
                      <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                        {p.brand ? `${p.brand} · ` : ''}AED {Number(p.price).toFixed(2)}
                      </p>
                    </div>
                    <div className="flex items-center gap-1.5">
                      <button
                        onClick={() => updateQty(p.id, qty - 1)}
                        className="size-7 rounded-lg text-sm font-bold"
                        style={{ background: 'rgba(255,255,255,0.08)', color: 'var(--text-primary)' }}
                      >
                        −
                      </button>
                      <span className="min-w-6 text-center text-sm font-semibold" style={{ color: qty > 0 ? 'var(--accent)' : 'var(--text-muted)' }}>
                        {qty}
                      </span>
                      <button
                        onClick={() => updateQty(p.id, qty + 1)}
                        className="size-7 rounded-lg text-sm font-bold"
                        style={{ background: 'rgba(37,211,102,0.22)', color: 'var(--accent)' }}
                      >
                        +
                      </button>
                    </div>
                  </div>
                );
              })
            )}
          </div>

          <div className="mt-3 rounded-xl border p-3" style={{ borderColor: 'var(--card-border)', background: 'rgba(0,0,0,0.22)' }}>
            <div className="flex items-center justify-between text-sm">
              <span style={{ color: 'var(--text-medium)' }}>Items</span>
              <span className="font-semibold" style={{ color: 'var(--text-primary)' }}>{totalItems}</span>
            </div>
            <div className="mt-1 flex items-center justify-between text-sm">
              <span style={{ color: 'var(--text-medium)' }}>Total</span>
              <span className="font-semibold" style={{ color: 'var(--accent)' }}>AED {totalAmount.toFixed(2)}</span>
            </div>

            {selectedRows.length > 0 && (
              <div className="mt-2 max-h-20 space-y-1 overflow-y-auto border-t pt-2" style={{ borderColor: 'var(--card-border)' }}>
                {selectedRows.map((row) => (
                  <p key={row.product.id} className="text-xs" style={{ color: 'var(--text-muted)' }}>
                    {row.qty} x {row.product.name}
                  </p>
                ))}
              </div>
            )}
          </div>

          <textarea
            rows={2}
            value={orderNote}
            onChange={(e) => setOrderNote(e.target.value)}
            placeholder="Optional order note"
            className="mt-3 w-full resize-none rounded-xl border px-3 py-2 text-sm outline-none focus:border-[var(--accent)]"
            style={{ background: 'rgba(0,0,0,0.30)', borderColor: 'var(--card-border)', color: 'var(--text-primary)' }}
          />

          <button
            onClick={() => submitOrderMutation.mutate()}
            disabled={totalItems === 0 || submitOrderMutation.isPending}
            className="mt-3 w-full rounded-xl py-2.5 text-sm font-semibold disabled:opacity-50"
            style={{ background: 'var(--accent)', color: 'var(--accent-foreground)' }}
          >
            {submitOrderMutation.isPending ? 'Submitting…' : 'Submit Order'}
          </button>

          {feedback && (
            <p className="mt-2 text-xs" style={{ color: 'var(--text-muted)' }}>{feedback}</p>
          )}
        </div>
      )}

      {/* Messages */}
      <div className="flex-1 space-y-3 overflow-y-auto p-4">
        {isLoading ? (
          <div className="flex h-full items-center justify-center">
            <div className="size-8 animate-spin rounded-full border-2 border-[var(--accent)] border-t-transparent" />
          </div>
        ) : messages.length === 0 ? (
          <div className="flex h-full items-center justify-center">
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>No messages yet.</p>
          </div>
        ) : (
          messages.map((m) => {
            const isIn = m.direction === 'in';
            return (
              <div key={m.id} className={`flex ${isIn ? 'justify-start' : 'justify-end'}`}>
                <div
                  className="max-w-[78%] rounded-2xl px-3.5 py-2.5 shadow-sm"
                  style={{
                    background: isIn ? 'rgba(255,255,255,0.07)' : 'rgba(37,211,102,0.16)',
                    border: isIn
                      ? '1px solid var(--card-border)'
                      : '1px solid rgba(37,211,102,0.35)',
                  }}
                >
                  <p className="whitespace-pre-wrap text-sm leading-relaxed" style={{ color: 'var(--text-primary)' }}>
                    {m.body}
                  </p>
                  <p className="mt-1 text-[11px]" style={{ color: 'var(--text-muted)' }}>
                    {isIn ? 'Customer' : 'AI/Bot'} ·{' '}
                    {new Date(m.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}{' '}
                    {new Date(m.created_at).toLocaleDateString()}
                  </p>
                </div>
              </div>
            );
          })
        )}
        <div ref={bottomRef} />
      </div>
    </div>
  );
}

// ─── Chats Page ───────────────────────────────────────────────────────────────
export default function ChatsPage() {
  const user = useAuthStore((s) => s.user);
  const retailerId = user?.retailer_id ?? 1;
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [search, setSearch] = useState('');

  const { data, isLoading } = useQuery({
    queryKey: ['chats', retailerId],
    queryFn: () => fetchChats(retailerId),
    refetchInterval: 15_000,
  });

  const customers: ChatCustomer[] = data?.data ?? [];

  const filtered = search
    ? customers.filter(
        (c) =>
          c.name?.toLowerCase().includes(search.toLowerCase()) ||
          c.phone?.includes(search)
      )
    : customers;

  const selectedCustomer = customers.find((c) => c.id === selectedId) ?? null;

  return (
    <div className="flex h-[calc(100vh-100px)] overflow-hidden rounded-2xl border" style={{ borderColor: 'var(--card-border)' }}>
      {/* ── Inbox list ── */}
      <div
        className={`flex flex-col border-r ${selectedId ? 'hidden lg:flex' : 'flex'} w-full lg:w-80 shrink-0`}
        style={{ borderColor: 'var(--card-border)', background: 'rgba(0,0,0,0.20)' }}
      >
        {/* Search bar */}
        <div className="border-b p-3" style={{ borderColor: 'var(--card-border)' }}>
          <h2 className="mb-2 px-1 text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>
            WhatsApp Chats
          </h2>
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search customer…"
            className="w-full rounded-xl border px-3 py-2 text-sm outline-none focus:border-[var(--accent)]"
            style={{ background: 'rgba(0,0,0,0.30)', borderColor: 'var(--card-border)', color: 'var(--text-primary)' }}
          />
        </div>

        {/* Customer list */}
        <div className="flex-1 overflow-y-auto">
          {isLoading ? (
            <div className="flex h-40 items-center justify-center">
              <div className="size-7 animate-spin rounded-full border-2 border-[var(--accent)] border-t-transparent" />
            </div>
          ) : filtered.length === 0 ? (
            <div className="py-16 text-center">
              <p className="mb-1 text-3xl">💬</p>
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                {search ? 'No results.' : 'No chats yet.'}
              </p>
            </div>
          ) : (
            filtered.map((c) => {
              const active = c.id === selectedId;
              const latest = c.latest_message;
              return (
                <button
                  key={c.id}
                  onClick={() => setSelectedId(c.id)}
                  className="flex w-full items-start gap-3 border-b px-4 py-3.5 text-left transition"
                  style={{
                    borderColor: 'var(--card-border)',
                    background: active ? 'rgba(37,211,102,0.10)' : 'transparent',
                  }}
                >
                  <div
                    className="flex size-10 shrink-0 items-center justify-center rounded-2xl text-sm font-bold"
                    style={{ background: 'rgba(37,211,102,0.15)', color: 'var(--accent)' }}
                  >
                    {(c.name ?? 'G')[0].toUpperCase()}
                  </div>
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center justify-between gap-2">
                      <p
                        className="truncate text-sm font-semibold"
                        style={{ color: active ? 'var(--accent)' : 'var(--text-primary)' }}
                      >
                        {c.name}
                      </p>
                      {latest && (
                        <p className="shrink-0 text-[10px]" style={{ color: 'var(--text-muted)' }}>
                          {new Date(latest.created_at).toLocaleDateString()}
                        </p>
                      )}
                    </div>
                    <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{c.phone}</p>
                    {latest && (
                      <p className="mt-0.5 truncate text-xs" style={{ color: 'var(--text-muted)' }}>
                        {latest.direction === 'out' ? '🤖 ' : ''}{latest.body}
                      </p>
                    )}
                  </div>
                </button>
              );
            })
          )}
        </div>
      </div>

      {/* ── Thread panel ── */}
      <div className={`flex-1 ${selectedId ? 'flex' : 'hidden lg:flex'} flex-col`}>
        {selectedId && selectedCustomer ? (
          <ThreadPanel key={selectedId} customerId={selectedId} retailerId={retailerId} onClose={() => setSelectedId(null)} />
        ) : (
          <div className="flex flex-1 flex-col items-center justify-center gap-2">
            <p className="text-4xl">💬</p>
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>
              Select a conversation to view the full chat
            </p>
          </div>
        )}
      </div>
    </div>
  );
}
