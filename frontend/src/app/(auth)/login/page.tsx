'use client';

import { login } from '@/lib/api';
import { useAuthStore } from '@/store/auth-store';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import type { AxiosError } from 'axios';

const DEMO_ACCOUNTS = [
  { label: 'Super Admin', role: 'owner', email: 'owner@peetyai.com', password: 'password', icon: '👑', color: '#f59e0b' },
  { label: 'Retailer Admin', role: 'retailer', email: 'admin@freshmart.ae', password: 'password', icon: '🏪', color: '#25d366' },
  { label: 'Staff', role: 'staff', email: 'staff@freshmart.ae', password: 'password', icon: '👤', color: '#22d3ee' },
];

export default function LoginPage() {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const [selected, setSelected] = useState<number | null>(null);
  const setAuth = useAuthStore((s) => s.setAuth);
  const router = useRouter();

  function pickDemo(idx: number) {
    setSelected(idx);
    setEmail(DEMO_ACCOUNTS[idx].email);
    setPassword(DEMO_ACCOUNTS[idx].password);
    setError('');
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError('');
    setLoading(true);
    try {
      const res = await login(email, password);
      setAuth(res.token, {
        id: res.user.id,
        name: res.user.name,
        email: res.user.email,
        role: res.user.role as 'owner' | 'retailer' | 'staff',
        retailer_id: res.user.retailer_id,
      });
      router.push('/dashboard');
    } catch (err) {
      const axiosErr = err as AxiosError<{ message?: string }>;
      const status = axiosErr.response?.status;
      const apiMessage = axiosErr.response?.data?.message;

      if (status && status >= 500) {
        setError('Server is unavailable (API error). Please try again in a moment.');
      } else if (apiMessage) {
        setError(apiMessage);
      } else {
        setError('Invalid email or password.');
      }
    } finally {
      setLoading(false);
    }
  }

  return (
    <div className="w-full max-w-md">
      {/* Logo */}
      <div className="mb-8 text-center">
        <div
          className="mx-auto mb-4 flex size-16 items-center justify-center rounded-2xl text-3xl font-bold shadow-[0_0_40px_rgba(37,211,102,0.45)]"
          style={{ background: 'var(--accent)', color: 'var(--accent-foreground)' }}
        >
          P
        </div>
        <h1 className="text-3xl font-bold" style={{ color: 'var(--text-primary)' }}>PeetyAI</h1>
        <p className="mt-1.5 text-sm" style={{ color: 'var(--text-muted)' }}>
          AI-Powered WhatsApp Grocery SaaS
        </p>
      </div>

      {/* Role selector */}
      <div className="mb-5">
        <p className="mb-2 text-xs font-semibold uppercase tracking-widest" style={{ color: 'var(--text-muted)' }}>
          Select your role to demo
        </p>
        <div className="grid grid-cols-3 gap-2">
          {DEMO_ACCOUNTS.map((acc, i) => (
            <button
              key={i}
              type="button"
              onClick={() => pickDemo(i)}
              className="flex flex-col items-center gap-1.5 rounded-2xl border px-2 py-3 text-xs font-medium transition"
              style={{
                borderColor: selected === i ? acc.color : 'var(--card-border)',
                background: selected === i ? `${acc.color}18` : 'var(--card-bg)',
                color: selected === i ? acc.color : 'var(--text-muted)',
                boxShadow: selected === i ? `0 0 0 1px ${acc.color}` : 'none',
              }}
            >
              <span className="text-xl">{acc.icon}</span>
              {acc.label}
            </button>
          ))}
        </div>
      </div>

      {/* Form card */}
      <div
        className="rounded-2xl border p-6 backdrop-blur-xl"
        style={{ background: 'var(--card-bg)', borderColor: 'var(--card-border)' }}
      >
        <form onSubmit={handleSubmit} className="space-y-4">
          <div>
            <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>
              Email
            </label>
            <input
              type="email"
              required
              autoComplete="email"
              value={email}
              onChange={(e) => { setEmail(e.target.value); setSelected(null); }}
              placeholder="you@example.com"
              className="w-full rounded-xl border px-3.5 py-2.5 text-sm outline-none transition focus:ring-2 focus:ring-[var(--accent)]/25"
              style={{ background: 'rgba(0,0,0,0.30)', borderColor: 'var(--card-border)', color: 'var(--text-primary)' }}
            />
          </div>

          <div>
            <label className="mb-1.5 block text-xs font-semibold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>
              Password
            </label>
            <input
              type="password"
              required
              autoComplete="current-password"
              value={password}
              onChange={(e) => { setPassword(e.target.value); setSelected(null); }}
              placeholder="••••••••"
              className="w-full rounded-xl border px-3.5 py-2.5 text-sm outline-none transition focus:ring-2 focus:ring-[var(--accent)]/25"
              style={{ background: 'rgba(0,0,0,0.30)', borderColor: 'var(--card-border)', color: 'var(--text-primary)' }}
            />
          </div>

          {error && (
            <p className="rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2 text-sm text-red-400">{error}</p>
          )}

          <button
            type="submit"
            disabled={loading}
            className="mt-2 w-full rounded-xl py-2.5 text-sm font-semibold transition disabled:opacity-60"
            style={{ background: 'var(--accent)', color: 'var(--accent-foreground)' }}
          >
            {loading ? 'Signing in…' : 'Sign In →'}
          </button>
        </form>
      </div>

      <p className="mt-4 text-center text-xs" style={{ color: 'var(--text-muted)' }}>
        All demo accounts use password: <span className="font-mono font-semibold" style={{ color: 'var(--text-medium)' }}>password</span>
      </p>
    </div>
  );
}
