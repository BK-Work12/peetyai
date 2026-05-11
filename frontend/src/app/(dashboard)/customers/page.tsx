'use client';

import { Card } from '@/components/ui/card';
import { fetchCustomerDetail, fetchCustomers, fetchOrderDetail, updateOrderStatus } from '@/lib/api';
import type { Customer, OrderDetail, OrderStatus } from '@/lib/types';
import { useAuthStore } from '@/store/auth-store';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';

const ORDER_STATUSES: OrderStatus[] = ['placed', 'picking', 'packed', 'dispatched', 'delivered'];
const statusColor: Record<OrderStatus, string> = {
  placed: '#60a5fa', picking: '#f59e0b', packed: '#a78bfa',
  dispatched: '#f97316', delivered: '#25d366',
};

// ─── Inline Order Detail (opens inside customer drawer) ───────────────────────
function InlineOrderDetail({ orderId, onClose }: { orderId: number; onClose: () => void }) {
  const qc = useQueryClient();
  const [note, setNote] = useState('');
  const [newStatus, setNewStatus] = useState<OrderStatus | ''>('');

  const { data: order, isLoading } = useQuery<OrderDetail>({
    queryKey: ['order', orderId],
    queryFn: () => fetchOrderDetail(orderId),
  });

  const mutation = useMutation({
    mutationFn: () => updateOrderStatus(orderId, newStatus || order!.status, note || undefined),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['orders'] });
      qc.invalidateQueries({ queryKey: ['order', orderId] });
      setNote('');
      setNewStatus('');
    },
  });

  return (
    <div className="fixed inset-0 z-[60] flex items-start justify-end bg-black/60 backdrop-blur-sm" onClick={onClose}>
      <aside
        className="relative flex h-full w-full max-w-lg flex-col overflow-y-auto border-l shadow-2xl"
        style={{ background: '#071a0d', borderColor: 'var(--card-border)' }}
        onClick={(e) => e.stopPropagation()}
      >
        <div className="sticky top-0 z-10 flex items-center justify-between border-b px-5 py-4" style={{ background: '#071a0d', borderColor: 'var(--card-border)' }}>
          <div>
            <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Order #{orderId}</h2>
            {order && (
              <span className="mt-0.5 inline-block rounded-full px-2.5 py-0.5 text-xs font-medium capitalize"
                style={{ background: `${statusColor[order.status]}20`, color: statusColor[order.status] }}>
                {order.status}
              </span>
            )}
          </div>
          <button onClick={onClose} className="rounded-xl p-2 text-xl leading-none transition hover:bg-white/10" style={{ color: 'var(--text-muted)' }}>✕</button>
        </div>

        {isLoading ? (
          <div className="flex flex-1 items-center justify-center">
            <div className="size-8 animate-spin rounded-full border-2 border-[var(--accent)] border-t-transparent" />
          </div>
        ) : order ? (
          <div className="flex-1 space-y-5 p-5">
            {/* Items */}
            <section>
              <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>
                Items ({order.items?.length ?? 0})
              </h3>
              <div className="space-y-2 rounded-2xl border p-4" style={{ borderColor: 'var(--card-border)', background: 'rgba(0,0,0,0.20)' }}>
                {(order.items ?? []).map((item) => (
                  <div key={item.id} className="flex items-center justify-between text-sm">
                    <span style={{ color: 'var(--text-medium)' }}>{item.product_name} × {item.qty}</span>
                    <span className="font-medium" style={{ color: 'var(--text-primary)' }}>AED {Number(item.subtotal).toFixed(2)}</span>
                  </div>
                ))}
                <div className="mt-2 border-t pt-2" style={{ borderColor: 'var(--card-border)' }}>
                  <div className="flex justify-between font-semibold">
                    <span style={{ color: 'var(--text-medium)' }}>Total</span>
                    <span style={{ color: 'var(--accent)' }}>AED {Number(order.total).toFixed(2)}</span>
                  </div>
                </div>
              </div>
            </section>

            {/* Status timeline */}
            {(order.status_logs ?? []).length > 0 && (
              <section>
                <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>Timeline</h3>
                <ol className="relative space-y-3 border-l pl-5" style={{ borderColor: 'var(--card-border)' }}>
                  {(order.status_logs ?? []).map((log) => (
                    <li key={log.id} className="relative">
                      <span className="absolute -left-[21px] top-1 size-2.5 rounded-full border-2 border-[var(--accent)] bg-[#071a0d]" />
                      <p className="text-sm font-medium capitalize" style={{ color: 'var(--text-primary)' }}>{log.status}</p>
                      {log.note && <p className="text-xs" style={{ color: 'var(--text-medium)' }}>{log.note}</p>}
                      <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                        {new Date(log.created_at).toLocaleString()} {log.user ? `· ${log.user.name}` : ''}
                      </p>
                    </li>
                  ))}
                </ol>
              </section>
            )}

            {/* Update Status */}
            <section className="rounded-2xl border p-4" style={{ borderColor: 'var(--card-border)', background: 'rgba(37,211,102,0.04)' }}>
              <h3 className="mb-3 text-xs font-semibold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>Update Status</h3>
              <div className="space-y-3">
                <div className="flex flex-wrap gap-2">
                  {ORDER_STATUSES.map((s) => (
                    <button key={s} onClick={() => setNewStatus(s)}
                      className="rounded-full px-3 py-1 text-xs font-medium capitalize transition"
                      style={{
                        background: newStatus === s ? `${statusColor[s]}30` : 'rgba(255,255,255,0.07)',
                        color: newStatus === s ? statusColor[s] : 'var(--text-medium)',
                        border: `1px solid ${newStatus === s ? statusColor[s] : 'transparent'}`,
                      }}>
                      {s}
                    </button>
                  ))}
                </div>
                <textarea rows={2} value={note} onChange={(e) => setNote(e.target.value)}
                  placeholder="Optional note…"
                  className="w-full resize-none rounded-xl border px-3.5 py-2.5 text-sm outline-none focus:border-[var(--accent)]"
                  style={{ background: 'rgba(0,0,0,0.30)', borderColor: 'var(--card-border)', color: 'var(--text-primary)' }}
                />
                <button onClick={() => mutation.mutate()} disabled={(!newStatus && !note) || mutation.isPending}
                  className="w-full rounded-xl py-2.5 text-sm font-semibold disabled:opacity-50"
                  style={{ background: 'var(--accent)', color: 'var(--accent-foreground)' }}>
                  {mutation.isPending ? 'Saving…' : 'Save Update'}
                </button>
              </div>
            </section>
          </div>
        ) : null}
      </aside>
    </div>
  );
}

// ─── Customer detail drawer ───────────────────────────────────────────────────
function CustomerDetailPanel({ customerId, onClose }: { customerId: number; onClose: () => void }) {
  const [openOrderId, setOpenOrderId] = useState<number | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['customer', customerId],
    queryFn: () => fetchCustomerDetail(customerId),
  });

  const customer = data?.customer ?? data;
  const orders: Array<{ id: number; status: string; total: number; created_at: string }> = data?.orders?.data ?? data?.orders ?? [];
  const messages: Array<{ id: number; direction: string; body: string | null; created_at: string }> =
    (data?.messages ?? [])
      .map((m: any) => ({
        id: Number(m.id),
        direction: String(m.direction ?? 'in'),
        body: m.body ? String(m.body) : null,
        created_at: String(m.created_at ?? ''),
      }))
      .filter((m: { id: number; direction: string; body: string | null; created_at: string }) => !!m.body)
      .sort((a: { created_at: string }, b: { created_at: string }) => new Date(a.created_at).getTime() - new Date(b.created_at).getTime());

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-end bg-black/50 backdrop-blur-sm" onClick={onClose}>
      <aside
        className="relative flex h-full w-full max-w-md flex-col overflow-y-auto border-l shadow-2xl"
        style={{ background: '#0b2213', borderColor: 'var(--card-border)' }}
        onClick={(e) => e.stopPropagation()}
      >
        <div className="sticky top-0 z-10 flex items-center justify-between border-b px-5 py-4" style={{ background: '#0b2213', borderColor: 'var(--card-border)' }}>
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Customer Detail</h2>
          <button onClick={onClose} className="rounded-xl p-2 text-xl leading-none transition hover:bg-white/10" style={{ color: 'var(--text-muted)' }}>✕</button>
        </div>

        {isLoading ? (
          <div className="flex flex-1 items-center justify-center">
            <div className="size-8 animate-spin rounded-full border-2 border-[var(--accent)] border-t-transparent" />
          </div>
        ) : customer ? (
          <div className="flex-1 space-y-5 p-5">
            {/* Info */}
            <div className="flex items-center gap-4">
              <div className="flex size-14 items-center justify-center rounded-2xl text-xl font-bold"
                style={{ background: 'rgba(37,211,102,0.15)', color: 'var(--accent)' }}>
                {(customer.name ?? 'G')[0].toUpperCase()}
              </div>
              <div>
                <p className="text-lg font-semibold" style={{ color: 'var(--text-primary)' }}>{customer.name}</p>
                <p className="text-sm" style={{ color: 'var(--text-medium)' }}>{customer.phone}</p>
                {customer.email && <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{customer.email}</p>}
              </div>
            </div>

            {/* Stats */}
            <div className="grid grid-cols-2 gap-3">
              <div className="rounded-2xl border p-4 text-center" style={{ borderColor: 'var(--card-border)', background: 'rgba(0,0,0,0.20)' }}>
                <p className="text-2xl font-bold" style={{ color: 'var(--accent)' }}>{customer.orders_count ?? orders.length}</p>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Total Orders</p>
              </div>
              <div className="rounded-2xl border p-4 text-center" style={{ borderColor: 'var(--card-border)', background: 'rgba(0,0,0,0.20)' }}>
                <p className="text-2xl font-bold" style={{ color: 'var(--accent)' }}>
                  AED {Number(customer.orders_sum_total ?? 0).toFixed(0)}
                </p>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Lifetime Value</p>
              </div>
            </div>

            {/* Address */}
            {customer.address && (
              <div className="rounded-xl border px-4 py-3" style={{ borderColor: 'var(--card-border)', background: 'rgba(0,0,0,0.15)' }}>
                <p className="text-xs font-semibold uppercase tracking-wider mb-1" style={{ color: 'var(--text-muted)' }}>Address</p>
                <p className="text-sm" style={{ color: 'var(--text-medium)' }}>{customer.address}</p>
              </div>
            )}

            {/* Order history */}
            {orders.length > 0 && (
              <section>
                <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>
                  Recent Orders <span className="normal-case font-normal">(click to view detail)</span>
                </h3>
                <div className="space-y-2">
                  {orders.slice(0, 10).map((o) => (
                    <button
                      key={o.id}
                      onClick={() => setOpenOrderId(o.id)}
                      className="flex w-full items-center justify-between rounded-xl border px-4 py-3 text-left transition hover:border-[var(--accent)]/40"
                      style={{ borderColor: 'var(--card-border)', background: 'rgba(0,0,0,0.20)' }}
                    >
                      <div>
                        <p className="text-sm font-medium" style={{ color: 'var(--text-primary)' }}>Order #{o.id}</p>
                        <p className="text-xs capitalize" style={{ color: 'var(--text-muted)' }}>
                          {o.status} · {new Date(o.created_at).toLocaleDateString()}
                        </p>
                      </div>
                      <div className="flex items-center gap-2">
                        <p className="font-semibold" style={{ color: 'var(--accent)' }}>AED {Number(o.total).toFixed(2)}</p>
                        <span style={{ color: 'var(--text-muted)' }}>›</span>
                      </div>
                    </button>
                  ))}
                </div>
              </section>
            )}

            {/* Chat history */}
            <section>
              <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>Chat History</h3>

              {messages.length === 0 ? (
                <div className="rounded-xl border px-4 py-3 text-sm" style={{ borderColor: 'var(--card-border)', background: 'rgba(0,0,0,0.15)', color: 'var(--text-muted)' }}>
                  No chat history yet.
                </div>
              ) : (
                <div className="max-h-80 space-y-2 overflow-y-auto rounded-xl border p-3" style={{ borderColor: 'var(--card-border)', background: 'rgba(0,0,0,0.15)' }}>
                  {messages.map((m) => {
                    const isIncoming = m.direction === 'in';
                    return (
                      <div key={m.id} className={`flex ${isIncoming ? 'justify-start' : 'justify-end'}`}>
                        <div
                          className="max-w-[85%] rounded-2xl px-3 py-2"
                          style={{
                            background: isIncoming ? 'rgba(255,255,255,0.08)' : 'rgba(37,211,102,0.15)',
                            border: isIncoming ? '1px solid var(--card-border)' : '1px solid rgba(37,211,102,0.35)',
                          }}
                        >
                          <p className="whitespace-pre-wrap text-sm" style={{ color: 'var(--text-primary)' }}>{m.body}</p>
                          <p className="mt-1 text-[11px]" style={{ color: 'var(--text-muted)' }}>
                            {isIncoming ? 'Customer' : 'AI/Store'} · {new Date(m.created_at).toLocaleString()}
                          </p>
                        </div>
                      </div>
                    );
                  })}
                </div>
              )}
            </section>
          </div>
        ) : (
          <div className="flex flex-1 items-center justify-center">
            <p style={{ color: 'var(--text-muted)' }}>Customer not found.</p>
          </div>
        )}
      </aside>

      {/* Nested order detail panel */}
      {openOrderId !== null && (
        <InlineOrderDetail orderId={openOrderId} onClose={() => setOpenOrderId(null)} />
      )}
    </div>
  );
}

// ─── Customers list ───────────────────────────────────────────────────────────
export default function CustomersPage() {
  const user = useAuthStore((s) => s.user);
  const retailerId = user?.retailer_id ?? 1;
  const [page, setPage] = useState(1);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [search, setSearch] = useState('');

  const { data, isLoading } = useQuery({
    queryKey: ['customers', retailerId, page],
    queryFn: () => fetchCustomers(retailerId, page),
  });

  const customers: Customer[] = data?.data ?? [];
  const total = data?.total ?? 0;
  const lastPage = data?.last_page ?? 1;

  const filtered = search
    ? customers.filter((c) =>
        c.name.toLowerCase().includes(search.toLowerCase()) ||
        c.phone.includes(search)
      )
    : customers;

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-xl font-semibold" style={{ color: 'var(--text-primary)' }}>Customers</h2>
          <p className="text-sm" style={{ color: 'var(--text-muted)' }}>{total} total customers</p>
        </div>
        <input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Search by name or phone…"
          className="rounded-xl border px-3.5 py-2 text-sm outline-none focus:border-[var(--accent)]"
          style={{ background: 'rgba(0,0,0,0.25)', borderColor: 'var(--card-border)', color: 'var(--text-primary)', width: 220 }}
        />
      </div>

      {isLoading ? (
        <div className="flex h-40 items-center justify-center">
          <div className="size-8 animate-spin rounded-full border-2 border-[var(--accent)] border-t-transparent" />
        </div>
      ) : filtered.length === 0 ? (
        <Card>
          <div className="py-16 text-center">
            <p className="mb-2 text-4xl">👤</p>
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>
              {search ? 'No customers match your search.' : 'No customers yet. They\'ll appear here after first WhatsApp order.'}
            </p>
          </div>
        </Card>
      ) : (
        <>
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            {filtered.map((c) => (
              <button key={c.id} onClick={() => setSelectedId(c.id)} className="w-full text-left">
                <Card className="space-y-3 transition hover:border-[var(--accent)]/40">
                  <div className="flex items-center gap-3">
                    <div className="flex size-10 items-center justify-center rounded-xl text-sm font-bold"
                      style={{ background: 'rgba(37,211,102,0.15)', color: 'var(--accent)' }}>
                      {(c.name ?? 'G')[0].toUpperCase()}
                    </div>
                    <div className="min-w-0 flex-1">
                      <p className="truncate font-semibold" style={{ color: 'var(--text-primary)' }}>{c.name}</p>
                      <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{c.phone}</p>
                    </div>
                  </div>
                  <div className="flex justify-between text-sm">
                    <span style={{ color: 'var(--text-muted)' }}>{c.orders_count ?? 0} orders</span>
                    <span className="font-semibold" style={{ color: 'var(--accent)' }}>
                      AED {Number(c.orders_sum_total ?? 0).toFixed(0)}
                    </span>
                  </div>
                  <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                    Joined {new Date(c.created_at).toLocaleDateString()}
                  </p>
                </Card>
              </button>
            ))}
          </div>

          {/* Pagination */}
          {lastPage > 1 && (
            <div className="flex items-center justify-center gap-3 pt-2">
              <button disabled={page === 1} onClick={() => setPage((p) => p - 1)}
                className="rounded-xl px-4 py-2 text-sm disabled:opacity-40"
                style={{ background: 'rgba(255,255,255,0.08)', color: 'var(--text-medium)' }}>
                ← Prev
              </button>
              <span className="text-sm" style={{ color: 'var(--text-muted)' }}>Page {page} / {lastPage}</span>
              <button disabled={page === lastPage} onClick={() => setPage((p) => p + 1)}
                className="rounded-xl px-4 py-2 text-sm disabled:opacity-40"
                style={{ background: 'rgba(255,255,255,0.08)', color: 'var(--text-medium)' }}>
                Next →
              </button>
            </div>
          )}
        </>
      )}

      {selectedId !== null && (
        <CustomerDetailPanel customerId={selectedId} onClose={() => setSelectedId(null)} />
      )}
    </div>
  );
}

