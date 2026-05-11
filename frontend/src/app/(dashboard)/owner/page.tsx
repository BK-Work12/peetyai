'use client';

import { Card } from '@/components/ui/card';
import {
  createRetailer,
  fetchOwnerRetailers,
  fetchOwnerSummary,
  updateRetailer,
} from '@/lib/api';
import type { RetailerRow } from '@/lib/types';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';

function StatCard({ label, value, sub }: { label: string; value: string | number; sub?: string }) {
  return (
    <Card>
      <p className="text-xs font-semibold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>{label}</p>
      <p className="mt-2 text-3xl font-bold" style={{ color: 'var(--accent)' }}>{value}</p>
      {sub && <p className="mt-1 text-xs" style={{ color: 'var(--text-muted)' }}>{sub}</p>}
    </Card>
  );
}

function StatusBadge({ active }: { active: boolean }) {
  return (
    <span
      className="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium"
      style={{
        background: active ? 'rgba(37,211,102,0.15)' : 'rgba(239,68,68,0.15)',
        color: active ? '#25d366' : '#f87171',
      }}
    >
      <span className="size-1.5 rounded-full" style={{ background: active ? '#25d366' : '#f87171' }} />
      {active ? 'Active' : 'Suspended'}
    </span>
  );
}

function AddRetailerModal({ onClose, onSuccess }: { onClose: () => void; onSuccess: () => void }) {
  const [form, setForm] = useState({
    name: '', email: '', phone: '', address: '',
    delivery_radius_km: 5, commission_rate: 5,
    admin_name: '', admin_email: '', admin_password: '',
  });
  const [error, setError] = useState('');

  const mutation = useMutation({
    mutationFn: () => createRetailer(form),
    onSuccess: () => { onSuccess(); onClose(); },
    onError: () => setError('Failed to create retailer. Check all fields.'),
  });

  function set(key: string, value: string | number) {
    setForm((f) => ({ ...f, [key]: value }));
  }

  const inputCls = 'w-full rounded-xl border px-3.5 py-2.5 text-sm outline-none transition focus:border-[var(--accent)] focus:ring-2 focus:ring-[var(--accent)]/20';
  const inputStyle = { background: 'rgba(0,0,0,0.30)', borderColor: 'var(--card-border)', color: 'var(--text-primary)' };
  const labelCls = 'block mb-1.5 text-xs font-semibold uppercase tracking-wider';

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm">
      <div
        className="w-full max-w-lg rounded-2xl border p-6 shadow-2xl"
        style={{ background: '#0b2213', borderColor: 'var(--card-border)' }}
      >
        <div className="mb-5 flex items-center justify-between">
          <h2 className="text-lg font-semibold" style={{ color: 'var(--text-primary)' }}>Add New Retailer</h2>
          <button onClick={onClose} className="text-xl leading-none" style={{ color: 'var(--text-muted)' }}>✕</button>
        </div>

        <div className="space-y-4 overflow-y-auto" style={{ maxHeight: '65vh' }}>
          <p className="text-xs font-bold uppercase tracking-widest" style={{ color: 'var(--accent)' }}>Store Info</p>
          <div className="grid gap-3 sm:grid-cols-2">
            <div><label className={labelCls} style={{ color: 'var(--text-muted)' }}>Store Name</label>
              <input className={inputCls} style={inputStyle} value={form.name} onChange={(e) => set('name', e.target.value)} placeholder="Urban Grocer" /></div>
            <div><label className={labelCls} style={{ color: 'var(--text-muted)' }}>Store Email</label>
              <input type="email" className={inputCls} style={inputStyle} value={form.email} onChange={(e) => set('email', e.target.value)} placeholder="store@example.ae" /></div>
            <div><label className={labelCls} style={{ color: 'var(--text-muted)' }}>Phone</label>
              <input className={inputCls} style={inputStyle} value={form.phone} onChange={(e) => set('phone', e.target.value)} placeholder="+971501234567" /></div>
            <div><label className={labelCls} style={{ color: 'var(--text-muted)' }}>Address</label>
              <input className={inputCls} style={inputStyle} value={form.address} onChange={(e) => set('address', e.target.value)} placeholder="Downtown, Dubai" /></div>
            <div><label className={labelCls} style={{ color: 'var(--text-muted)' }}>Delivery Radius (km)</label>
              <input type="number" className={inputCls} style={inputStyle} value={form.delivery_radius_km} onChange={(e) => set('delivery_radius_km', Number(e.target.value))} /></div>
            <div><label className={labelCls} style={{ color: 'var(--text-muted)' }}>Commission %</label>
              <input type="number" className={inputCls} style={inputStyle} value={form.commission_rate} onChange={(e) => set('commission_rate', Number(e.target.value))} /></div>
          </div>

          <p className="mt-2 text-xs font-bold uppercase tracking-widest" style={{ color: 'var(--accent)' }}>Admin Account</p>
          <div className="grid gap-3 sm:grid-cols-2">
            <div><label className={labelCls} style={{ color: 'var(--text-muted)' }}>Admin Name</label>
              <input className={inputCls} style={inputStyle} value={form.admin_name} onChange={(e) => set('admin_name', e.target.value)} placeholder="John Doe" /></div>
            <div><label className={labelCls} style={{ color: 'var(--text-muted)' }}>Admin Email</label>
              <input type="email" className={inputCls} style={inputStyle} value={form.admin_email} onChange={(e) => set('admin_email', e.target.value)} placeholder="john@urbangrocer.ae" /></div>
            <div className="sm:col-span-2"><label className={labelCls} style={{ color: 'var(--text-muted)' }}>Password</label>
              <input type="password" className={inputCls} style={inputStyle} value={form.admin_password} onChange={(e) => set('admin_password', e.target.value)} placeholder="Min 8 characters" /></div>
          </div>
        </div>

        {error && <p className="mt-3 rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2 text-sm text-red-400">{error}</p>}

        <div className="mt-5 flex justify-end gap-3">
          <button onClick={onClose} className="rounded-xl px-4 py-2 text-sm font-medium" style={{ background: 'rgba(255,255,255,0.08)', color: 'var(--text-medium)' }}>
            Cancel
          </button>
          <button
            onClick={() => mutation.mutate()}
            disabled={mutation.isPending}
            className="rounded-xl px-5 py-2 text-sm font-semibold disabled:opacity-60"
            style={{ background: 'var(--accent)', color: 'var(--accent-foreground)' }}
          >
            {mutation.isPending ? 'Creating…' : 'Create Retailer'}
          </button>
        </div>
      </div>
    </div>
  );
}

export default function OwnerPage() {
  const queryClient = useQueryClient();
  const [showModal, setShowModal] = useState(false);

  const { data: summary } = useQuery({
    queryKey: ['owner-summary'],
    queryFn: fetchOwnerSummary,
  });

  const { data: retailersData, isLoading } = useQuery({
    queryKey: ['owner-retailers'],
    queryFn: fetchOwnerRetailers,
  });

  const toggleMutation = useMutation({
    mutationFn: ({ id, active }: { id: number; active: boolean }) =>
      updateRetailer(id, { active }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['owner-retailers'] }),
  });

  const retailers: RetailerRow[] = retailersData?.data ?? [];

  return (
    <div className="space-y-6">
      {/* Stats */}
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Total Retailers" value={summary?.retailers ?? '–'} />
        <StatCard label="Active Retailers" value={summary?.active_retailers ?? '–'} />
        <StatCard label="Total Orders" value={summary?.orders ?? '–'} />
        <StatCard
          label="Platform GMV"
          value={summary ? `$${summary.gmv.toLocaleString('en', { minimumFractionDigits: 0 })}` : '–'}
          sub={summary ? `Est. commission: $${summary.estimated_commission.toLocaleString()}` : undefined}
        />
      </div>

      {/* Retailers table */}
      <Card>
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Retailers</h2>
          <button
            onClick={() => setShowModal(true)}
            className="flex items-center gap-2 rounded-xl px-4 py-2 text-sm font-semibold"
            style={{ background: 'var(--accent)', color: 'var(--accent-foreground)' }}
          >
            <span>+</span> Add Retailer
          </button>
        </div>

        {isLoading ? (
          <div className="flex h-32 items-center justify-center">
            <div className="size-7 animate-spin rounded-full border-2 border-[var(--accent)] border-t-transparent" />
          </div>
        ) : retailers.length === 0 ? (
          <div className="py-12 text-center" style={{ color: 'var(--text-muted)' }}>
            <p className="text-4xl mb-2">🏪</p>
            <p className="text-sm">No retailers yet. Add your first one.</p>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b text-left text-xs font-semibold uppercase tracking-wider border-[var(--card-border)]" style={{ color: 'var(--text-muted)' }}>
                  <th className="py-3 pr-4">Retailer</th>
                  <th className="py-3 pr-4">Contact</th>
                  <th className="py-3 pr-4">Orders</th>
                  <th className="py-3 pr-4">GMV</th>
                  <th className="py-3 pr-4">Commission</th>
                  <th className="py-3 pr-4">Status</th>
                  <th className="py-3">Actions</th>
                </tr>
              </thead>
              <tbody>
                {retailers.map((r) => (
                  <tr key={r.id} className="border-b border-[var(--card-border)]" style={{ color: 'var(--text-medium)' }}>
                    <td className="py-3 pr-4">
                      <p className="font-medium" style={{ color: 'var(--text-primary)' }}>{r.name}</p>
                      <p className="text-xs" style={{ color: 'var(--text-muted)' }}>/{r.id}</p>
                    </td>
                    <td className="py-3 pr-4">
                      <p>{r.email}</p>
                      <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{r.phone}</p>
                    </td>
                    <td className="py-3 pr-4 font-mono">{r.orders_count}</td>
                    <td className="py-3 pr-4 font-mono">${Number(r.orders_sum_total ?? 0).toLocaleString('en', { minimumFractionDigits: 0 })}</td>
                    <td className="py-3 pr-4">{r.commission_rate}%</td>
                    <td className="py-3 pr-4"><StatusBadge active={r.active} /></td>
                    <td className="py-3">
                      <button
                        onClick={() => toggleMutation.mutate({ id: r.id, active: !r.active })}
                        disabled={toggleMutation.isPending}
                        className="rounded-lg px-3 py-1.5 text-xs font-medium transition disabled:opacity-50"
                        style={{
                          background: r.active ? 'rgba(239,68,68,0.15)' : 'rgba(37,211,102,0.15)',
                          color: r.active ? '#f87171' : '#25d366',
                        }}
                      >
                        {r.active ? 'Suspend' : 'Activate'}
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {showModal && (
        <AddRetailerModal
          onClose={() => setShowModal(false)}
          onSuccess={() => queryClient.invalidateQueries({ queryKey: ['owner-retailers', 'owner-summary'] })}
        />
      )}
    </div>
  );
}

