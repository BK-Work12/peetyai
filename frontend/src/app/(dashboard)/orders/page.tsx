'use client';

import { KanbanBoard } from '@/components/dashboard/KanbanBoard';
import { fetchOrderDetail, fetchOrders, updateOrderStatus } from '@/lib/api';
import type { OrderDetail, OrderStatus } from '@/lib/types';
import { useAuthStore } from '@/store/auth-store';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';

const ORDER_STATUSES: OrderStatus[] = ['placed', 'picking', 'packed', 'dispatched', 'delivered'];
const statusColor: Record<OrderStatus, string> = {
  placed: '#60a5fa', picking: '#f59e0b', packed: '#a78bfa',
  dispatched: '#f97316', delivered: '#25d366',
};

// ─── Order Detail Slide-over ──────────────────────────────────────────────────
function OrderDetailPanel({ orderId, onClose }: { orderId: number; onClose: () => void }) {
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
    <div className="fixed inset-0 z-50 flex items-start justify-end bg-black/50 backdrop-blur-sm" onClick={onClose}>
      <aside
        className="relative flex h-full w-full max-w-lg flex-col overflow-y-auto border-l shadow-2xl"
        style={{ background: '#0b2213', borderColor: 'var(--card-border)' }}
        onClick={(e) => e.stopPropagation()}
      >
        {/* Header */}
        <div className="sticky top-0 z-10 flex items-center justify-between border-b px-5 py-4" style={{ background: '#0b2213', borderColor: 'var(--card-border)' }}>
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
            {/* Customer */}
            <section className="rounded-2xl border p-4" style={{ borderColor: 'var(--card-border)', background: 'rgba(0,0,0,0.20)' }}>
              <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>Customer</h3>
              <p className="font-medium" style={{ color: 'var(--text-primary)' }}>{order.customer?.name ?? 'Guest'}</p>
              {order.customer?.phone && <p className="text-sm" style={{ color: 'var(--text-medium)' }}>{order.customer.phone}</p>}
            </section>

            {/* Items */}
            <section>
              <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>Items ({order.items?.length ?? 0})</h3>
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

            {/* Status Timeline */}
            {(order.status_logs ?? []).length > 0 && (
              <section>
                <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>Status Timeline</h3>
                <ol className="relative space-y-3 border-l pl-5" style={{ borderColor: 'var(--card-border)' }}>
                  {(order.status_logs ?? []).map((log) => (
                    <li key={log.id} className="relative">
                      <span className="absolute -left-[21px] top-1 size-2.5 rounded-full border-2 border-[var(--accent)] bg-[#0b2213]" />
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
                    <button
                      key={s}
                      onClick={() => setNewStatus(s)}
                      className="rounded-full px-3 py-1 text-xs font-medium capitalize transition"
                      style={{
                        background: newStatus === s ? `${statusColor[s]}30` : 'rgba(255,255,255,0.07)',
                        color: newStatus === s ? statusColor[s] : 'var(--text-medium)',
                        border: `1px solid ${newStatus === s ? statusColor[s] : 'transparent'}`,
                      }}
                    >
                      {s}
                    </button>
                  ))}
                </div>
                <textarea
                  rows={2}
                  value={note}
                  onChange={(e) => setNote(e.target.value)}
                  placeholder="Optional note (e.g., out for delivery via Talabat)…"
                  className="w-full resize-none rounded-xl border px-3.5 py-2.5 text-sm outline-none focus:border-[var(--accent)]"
                  style={{ background: 'rgba(0,0,0,0.30)', borderColor: 'var(--card-border)', color: 'var(--text-primary)' }}
                />
                <button
                  onClick={() => mutation.mutate()}
                  disabled={(!newStatus && !note) || mutation.isPending}
                  className="w-full rounded-xl py-2.5 text-sm font-semibold disabled:opacity-50"
                  style={{ background: 'var(--accent)', color: 'var(--accent-foreground)' }}
                >
                  {mutation.isPending ? 'Saving…' : 'Save Update'}
                </button>
              </div>
            </section>
          </div>
        ) : (
          <div className="flex flex-1 items-center justify-center">
            <p style={{ color: 'var(--text-muted)' }}>Order not found.</p>
          </div>
        )}
      </aside>
    </div>
  );
}

// ─── Orders Page ──────────────────────────────────────────────────────────────
export default function OrdersPage() {
  const user = useAuthStore((s) => s.user);
  const retailerId = user?.retailer_id ?? 1;
  const [selectedOrderId, setSelectedOrderId] = useState<number | null>(null);
  const qc = useQueryClient();

  const statusMutation = useMutation({
    mutationFn: ({ orderId, status }: { orderId: number; status: OrderStatus }) => updateOrderStatus(orderId, status),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['orders', retailerId] });
    },
  });

  const { data } = useQuery({
    queryKey: ['orders', retailerId],
    queryFn: () => fetchOrders(retailerId),
    initialData: [],
    refetchInterval: 30_000, // refresh every 30s
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    staleTime: 15_000,
  });

  return (
    <div className="space-y-4">
      <div>
        <h2 className="text-xl font-semibold" style={{ color: 'var(--text-primary)' }}>Live Orders</h2>
        <p className="text-sm" style={{ color: 'var(--text-muted)' }}>
          Drag cards to update status · click a card to view detail
        </p>
      </div>
      <KanbanBoard
        orders={data}
        onOrderClick={setSelectedOrderId}
        onMoveOrder={(orderId, status) => statusMutation.mutate({ orderId, status })}
      />
      {selectedOrderId !== null && (
        <OrderDetailPanel orderId={selectedOrderId} onClose={() => setSelectedOrderId(null)} />
      )}
    </div>
  );
}

