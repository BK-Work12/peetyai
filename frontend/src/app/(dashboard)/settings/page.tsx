'use client';

import { Card } from '@/components/ui/card';
import { fetchRetailerSettings, saveRetailerSettings, testWhatsAppConnection } from '@/lib/api';
import type { RetailerProfile, RetailerSettings, WhatsAppStatus } from '@/lib/types';
import { useAuthStore } from '@/store/auth-store';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';

type Tab = 'store' | 'whatsapp' | 'ai' | 'notifications';

const TABS: { id: Tab; label: string; icon: string }[] = [
  { id: 'store',         label: 'Store Profile',   icon: '🏪' },
  { id: 'whatsapp',      label: 'WhatsApp API',     icon: '📱' },
  { id: 'ai',            label: 'AI Config',        icon: '🤖' },
  { id: 'notifications', label: 'Notifications',    icon: '🔔' },
];

function Field({ label, hint, children }: { label: string; hint?: string; children: React.ReactNode }) {
  return (
    <div className="space-y-1.5">
      <label className="block text-xs font-semibold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>
        {label}
      </label>
      {children}
      {hint && <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{hint}</p>}
    </div>
  );
}

function Input({ value, onChange, placeholder, type = 'text' }: {
  value: string; onChange: (v: string) => void; placeholder?: string; type?: string;
}) {
  return (
    <input
      type={type}
      value={value}
      onChange={(e) => onChange(e.target.value)}
      placeholder={placeholder}
      className="w-full rounded-xl border px-3.5 py-2.5 text-sm outline-none transition focus:border-[var(--accent)] focus:ring-2 focus:ring-[var(--accent)]/20"
      style={{ background: 'rgba(0,0,0,0.30)', borderColor: 'var(--card-border)', color: 'var(--text-primary)' }}
    />
  );
}

function Toggle({ checked, onChange, label }: { checked: boolean; onChange: (v: boolean) => void; label: string }) {
  return (
    <label className="flex cursor-pointer items-center justify-between rounded-xl border px-4 py-3" style={{ borderColor: 'var(--card-border)', background: 'rgba(0,0,0,0.20)' }}>
      <span className="text-sm" style={{ color: 'var(--text-medium)' }}>{label}</span>
      <button
        type="button"
        role="switch"
        aria-checked={checked}
        onClick={() => onChange(!checked)}
        className="relative inline-flex h-6 w-11 shrink-0 rounded-full border-2 border-transparent transition-colors focus:outline-none"
        style={{ background: checked ? 'var(--accent)' : 'rgba(255,255,255,0.15)' }}
      >
        <span
          className="pointer-events-none inline-block size-5 transform rounded-full bg-white shadow-lg transition-transform"
          style={{ transform: checked ? 'translateX(20px)' : 'translateX(0)' }}
        />
      </button>
    </label>
  );
}

export default function SettingsPage() {
  const user = useAuthStore((s) => s.user);
  const queryClient = useQueryClient();
  const retailerId = user?.retailer_id ?? 1;

  const [tab, setTab] = useState<Tab>('store');
  const [saved, setSaved] = useState(false);

  // Local form state
  const [profile, setProfile] = useState<Partial<RetailerProfile>>({});
  const [settings, setSettings] = useState<RetailerSettings>({});
  const [whatsAppStatus, setWhatsAppStatus] = useState<WhatsAppStatus | null>(null);
  const [hydrated, setHydrated] = useState(false);

  const { data, isLoading } = useQuery({
    queryKey: ['retailer-settings', retailerId],
    queryFn: () => fetchRetailerSettings(retailerId),
    enabled: !!retailerId,
  });

  useEffect(() => {
    if (data && !hydrated) {
      setProfile(data.retailer);
      setSettings(data.settings ?? {});
      setWhatsAppStatus(data.whatsapp_status ?? null);
      setHydrated(true);
    }
  }, [data, hydrated]);

  const mutation = useMutation({
    mutationFn: () => saveRetailerSettings(retailerId, { ...profile, settings }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['retailer-settings', retailerId] });
      setSaved(true);
      setTimeout(() => setSaved(false), 3000);
    },
  });

  const testMutation = useMutation({
    mutationFn: () => testWhatsAppConnection(retailerId),
    onSuccess: (result) => {
      setWhatsAppStatus(result.whatsapp_status);
      queryClient.invalidateQueries({ queryKey: ['retailer-settings', retailerId] });
    },
  });

  function setWA(key: keyof NonNullable<RetailerSettings['whatsapp']>, value: string) {
    setSettings((s) => ({ ...s, whatsapp: { ...s.whatsapp, [key]: value } }));
  }

  function setAI(key: keyof NonNullable<RetailerSettings['ai']>, value: string | number) {
    setSettings((s) => ({ ...s, ai: { ...s.ai, [key]: value } }));
  }

  function setNotif(key: keyof NonNullable<RetailerSettings['notifications']>, value: boolean | number) {
    setSettings((s) => ({ ...s, notifications: { ...s.notifications, [key]: value } }));
  }

  const statusTone = !whatsAppStatus?.configured
    ? { label: 'Missing', bg: 'rgba(239,68,68,0.12)', color: '#fca5a5' }
    : whatsAppStatus.valid
      ? { label: 'Valid', bg: 'rgba(37,211,102,0.15)', color: 'var(--accent)' }
      : { label: 'Invalid', bg: 'rgba(245,158,11,0.14)', color: '#fbbf24' };

  if (isLoading) {
    return (
      <div className="flex h-64 items-center justify-center">
        <div className="size-8 animate-spin rounded-full border-2 border-[var(--accent)] border-t-transparent" />
      </div>
    );
  }

  return (
    <div className="space-y-5">
      {/* Tab bar */}
      <div className="flex gap-1 rounded-2xl border p-1" style={{ borderColor: 'var(--card-border)', background: 'var(--card-bg)' }}>
        {TABS.map((t) => (
          <button
            key={t.id}
            onClick={() => setTab(t.id)}
            className="flex flex-1 items-center justify-center gap-2 rounded-xl px-3 py-2 text-sm font-medium transition"
            style={{
              background: tab === t.id ? 'var(--accent)' : 'transparent',
              color: tab === t.id ? 'var(--accent-foreground)' : 'var(--text-muted)',
            }}
          >
            <span>{t.icon}</span>
            <span className="hidden sm:inline">{t.label}</span>
          </button>
        ))}
      </div>

      {/* Store Profile */}
      {tab === 'store' && (
        <Card className="space-y-5">
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Store Profile</h2>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Store Name">
              <Input value={profile.name ?? ''} onChange={(v) => setProfile((p) => ({ ...p, name: v }))} placeholder="Fresh Mart" />
            </Field>
            <Field label="Email">
              <Input type="email" value={profile.email ?? ''} onChange={(v) => setProfile((p) => ({ ...p, email: v }))} placeholder="store@example.com" />
            </Field>
            <Field label="Phone">
              <Input value={profile.phone ?? ''} onChange={(v) => setProfile((p) => ({ ...p, phone: v }))} placeholder="+971501234567" />
            </Field>
            <Field label="Delivery Radius (km)">
              <Input type="number" value={String(profile.delivery_radius_km ?? '')} onChange={(v) => setProfile((p) => ({ ...p, delivery_radius_km: Number(v) }))} placeholder="8" />
            </Field>
            <Field label="Address" >
              <Input value={profile.address ?? ''} onChange={(v) => setProfile((p) => ({ ...p, address: v }))} placeholder="Al Quoz, Dubai, UAE" />
            </Field>
          </div>
        </Card>
      )}

      {/* WhatsApp API */}
      {tab === 'whatsapp' && (
        <div className="space-y-4">
          <Card className="space-y-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div className="flex items-center gap-3">
                <div className="flex size-10 items-center justify-center rounded-xl text-xl" style={{ background: 'rgba(37,211,102,0.15)' }}>📱</div>
                <div>
                  <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>WhatsApp Business API</h2>
                  <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Meta Cloud API credentials for this store</p>
                </div>
              </div>
              <div className="flex items-center gap-2">
                <span
                  className="rounded-full px-3 py-1 text-xs font-semibold"
                  style={{ background: statusTone.bg, color: statusTone.color }}
                >
                  {statusTone.label}
                </span>
                <button
                  type="button"
                  onClick={() => testMutation.mutate()}
                  disabled={testMutation.isPending}
                  className="rounded-xl px-3 py-2 text-xs font-semibold transition disabled:opacity-60"
                  style={{ background: 'rgba(37,211,102,0.15)', color: 'var(--accent)' }}
                >
                  {testMutation.isPending ? 'Testing…' : 'Test connection'}
                </button>
              </div>
            </div>
            <div className="rounded-2xl border px-4 py-3 text-sm" style={{ borderColor: 'var(--card-border)', background: 'rgba(0,0,0,0.20)' }}>
              <p style={{ color: 'var(--text-primary)' }}>
                {whatsAppStatus?.message ?? 'Connection status will appear here.'}
              </p>
              <div className="mt-2 flex flex-wrap gap-3 text-xs" style={{ color: 'var(--text-muted)' }}>
                <span>Primary source: {whatsAppStatus?.default_source ?? 'unknown'}</span>
                <span>Active source: {whatsAppStatus?.credential_source ?? 'unknown'}</span>
                {whatsAppStatus?.display_phone_number && <span>Phone: {whatsAppStatus.display_phone_number}</span>}
                {whatsAppStatus?.verified_name && <span>Name: {whatsAppStatus.verified_name}</span>}
              </div>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Phone Number ID" hint="Found in Meta Business Manager → WhatsApp → Phone Numbers">
                <Input value={settings.whatsapp?.phone_number_id ?? ''} onChange={(v) => setWA('phone_number_id', v)} placeholder="123456789012345" />
              </Field>
              <Field label="Business Account ID" hint="Meta Business Account (WABA) ID">
                <Input value={settings.whatsapp?.business_account_id ?? ''} onChange={(v) => setWA('business_account_id', v)} placeholder="987654321098765" />
              </Field>
              <Field label="Access Token" hint="Permanent token from System User in Meta Business Manager">
                <Input type="password" value={settings.whatsapp?.access_token ?? ''} onChange={(v) => setWA('access_token', v)} placeholder="EAAxxxxxxxxxxxxxxx" />
              </Field>
              <Field label="Webhook Verify Token" hint="Any random string — must match your Meta App webhook config">
                <Input value={settings.whatsapp?.verify_token ?? ''} onChange={(v) => setWA('verify_token', v)} placeholder="my_secret_verify_token" />
              </Field>
            </div>
          </Card>

          <Card className="space-y-3">
            <h3 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Latest Delivery Failure</h3>
            {whatsAppStatus?.last_failure ? (
              <div className="space-y-3 rounded-2xl border px-4 py-3" style={{ borderColor: 'var(--card-border)', background: 'rgba(0,0,0,0.20)' }}>
                <div className="flex flex-wrap gap-3 text-xs" style={{ color: 'var(--text-muted)' }}>
                  <span>Message #{whatsAppStatus.last_failure.message_id}</span>
                  <span>Credential source: {whatsAppStatus.last_failure.credential_source}</span>
                  <span>{new Date(whatsAppStatus.last_failure.created_at).toLocaleString()}</span>
                </div>
                <p className="text-sm break-words" style={{ color: 'var(--text-primary)' }}>
                  {whatsAppStatus.last_failure.send_error}
                </p>
                {whatsAppStatus.last_failure.credential_attempts?.length ? (
                  <div className="space-y-2">
                    <p className="text-xs font-semibold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>
                      Credential attempts
                    </p>
                    {whatsAppStatus.last_failure.credential_attempts.map((attempt, index) => (
                      <div key={`${attempt.source}-${index}`} className="rounded-xl border px-3 py-2 text-xs" style={{ borderColor: 'var(--card-border)' }}>
                        <span style={{ color: 'var(--text-primary)' }}>{attempt.source}</span>
                        <span style={{ color: attempt.success ? 'var(--accent)' : '#fca5a5' }}> {attempt.success ? 'success' : 'failed'}</span>
                        {attempt.error && <p className="mt-1 break-words" style={{ color: 'var(--text-muted)' }}>{attempt.error}</p>}
                      </div>
                    ))}
                  </div>
                ) : null}
              </div>
            ) : (
              <div className="rounded-2xl border px-4 py-3 text-sm" style={{ borderColor: 'var(--card-border)', background: 'rgba(0,0,0,0.20)', color: 'var(--text-muted)' }}>
                No failed outbound WhatsApp delivery recorded yet.
              </div>
            )}
          </Card>

          <Card className="space-y-3">
            <h3 className="text-sm font-semibold" style={{ color: 'var(--text-primary)' }}>Webhook URL</h3>
            <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
              Set this as the Callback URL in your Meta App → Webhooks → WhatsApp Business Account
            </p>
            <div
              className="flex items-center gap-2 rounded-xl border px-4 py-3"
              style={{ borderColor: 'var(--card-border)', background: 'rgba(0,0,0,0.30)' }}
            >
              <code className="flex-1 text-xs break-all" style={{ color: 'var(--accent)' }}>
                {`${process.env.NEXT_PUBLIC_API_BASE_URL ?? '/api'}/webhooks/whatsapp`}
              </code>
              <button
                type="button"
                onClick={() => navigator.clipboard.writeText(`${process.env.NEXT_PUBLIC_API_BASE_URL ?? '/api'}/webhooks/whatsapp`)}
                className="shrink-0 rounded-lg px-2.5 py-1 text-xs font-medium transition"
                style={{ background: 'rgba(37,211,102,0.15)', color: 'var(--accent)' }}
              >
                Copy
              </button>
            </div>
          </Card>
        </div>
      )}

      {/* AI Config */}
      {tab === 'ai' && (
        <Card className="space-y-5">
          <div className="flex items-center gap-3">
            <div className="flex size-10 items-center justify-center rounded-xl text-xl" style={{ background: 'rgba(37,211,102,0.15)' }}>🤖</div>
            <div>
              <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>AI Configuration</h2>
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>OpenAI integration for natural language order processing</p>
            </div>
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="OpenAI API Key" hint="Your sk-… key from platform.openai.com">
              <Input type="password" value={settings.ai?.openai_api_key ?? ''} onChange={(v) => setAI('openai_api_key', v)} placeholder="sk-proj-…" />
            </Field>
            <Field label="Model">
              <select
                value={settings.ai?.model ?? 'gpt-4o-mini'}
                onChange={(e) => setAI('model', e.target.value)}
                className="w-full rounded-xl border px-3.5 py-2.5 text-sm outline-none transition focus:border-[var(--accent)]"
                style={{ background: 'rgba(0,0,0,0.30)', borderColor: 'var(--card-border)', color: 'var(--text-primary)' }}
              >
                <option value="gpt-4o">GPT-4o (Best quality)</option>
                <option value="gpt-4o-mini">GPT-4o Mini (Fast & cheap)</option>
                <option value="gpt-4-turbo">GPT-4 Turbo</option>
                <option value="gpt-3.5-turbo">GPT-3.5 Turbo (Economy)</option>
              </select>
            </Field>
            <Field label={`Temperature: ${settings.ai?.temperature ?? 0.4}`} hint="0 = deterministic, 1 = creative. Recommended: 0.3–0.5">
              <input
                type="range"
                min={0}
                max={1}
                step={0.05}
                value={settings.ai?.temperature ?? 0.4}
                onChange={(e) => setAI('temperature', parseFloat(e.target.value))}
                className="w-full accent-[#25d366]"
              />
            </Field>
          </div>
          <Field
            label="AI System Prompt"
            hint="Custom instructions for how the bot should interpret messages. Keep product/order actions explicit."
          >
            <textarea
              rows={8}
              value={settings.ai?.system_prompt ?? ''}
              onChange={(e) => setAI('system_prompt', e.target.value)}
              placeholder="Example: Prioritize matching products by brand first. If ambiguous, ask user to choose from numbered options. Keep responses concise."
              className="w-full resize-y rounded-xl border px-3.5 py-2.5 text-sm outline-none transition focus:border-[var(--accent)] focus:ring-2 focus:ring-[var(--accent)]/20"
              style={{ background: 'rgba(0,0,0,0.30)', borderColor: 'var(--card-border)', color: 'var(--text-primary)' }}
            />
          </Field>
        </Card>
      )}

      {/* Notifications */}
      {tab === 'notifications' && (
        <Card className="space-y-4">
          <h2 className="text-base font-semibold" style={{ color: 'var(--text-primary)' }}>Notification Preferences</h2>
          <div className="space-y-3">
            <Toggle
              checked={settings.notifications?.email_on_new_order ?? true}
              onChange={(v) => setNotif('email_on_new_order', v)}
              label="Email on new WhatsApp order"
            />
            <Toggle
              checked={settings.notifications?.email_on_low_stock ?? true}
              onChange={(v) => setNotif('email_on_low_stock', v)}
              label="Email on low stock alert"
            />
          </div>
          <Field label="Low Stock Threshold" hint="Alert when product stock falls at or below this number">
            <Input
              type="number"
              value={String(settings.notifications?.low_stock_threshold ?? 10)}
              onChange={(v) => setNotif('low_stock_threshold', Number(v))}
              placeholder="10"
            />
          </Field>
        </Card>
      )}

      {/* Save bar */}
      <div className="flex items-center justify-between rounded-2xl border px-5 py-3" style={{ borderColor: 'var(--card-border)', background: 'var(--card-bg)' }}>
        {saved ? (
          <p className="text-sm font-medium" style={{ color: 'var(--accent)' }}>✓ Settings saved successfully</p>
        ) : (
          <p className="text-sm" style={{ color: 'var(--text-muted)' }}>Unsaved changes will be lost on navigation.</p>
        )}
        <button
          onClick={() => mutation.mutate()}
          disabled={mutation.isPending}
          className="rounded-xl px-5 py-2 text-sm font-semibold transition disabled:opacity-60"
          style={{ background: 'var(--accent)', color: 'var(--accent-foreground)' }}
        >
          {mutation.isPending ? 'Saving…' : 'Save Settings'}
        </button>
      </div>
    </div>
  );
}

