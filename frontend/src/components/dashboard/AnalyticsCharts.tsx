'use client';

import {
  Bar,
  BarChart,
  CartesianGrid,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';
import { useEffect, useState } from 'react';

const trendData = [
  { day: 'Mon', gmv: 1900, orders: 34 },
  { day: 'Tue', gmv: 2400, orders: 40 },
  { day: 'Wed', gmv: 2200, orders: 37 },
  { day: 'Thu', gmv: 3100, orders: 53 },
  { day: 'Fri', gmv: 3600, orders: 61 },
  { day: 'Sat', gmv: 4100, orders: 70 },
];

export function AnalyticsCharts() {
  const [mounted, setMounted] = useState(false);

  useEffect(() => {
    setMounted(true);
  }, []);

  if (!mounted) {
    return <div className="h-72 rounded-2xl border border-[var(--card-border)] bg-[var(--card-bg)]" />;
  }

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <div className="h-72 rounded-2xl border p-3 border-[var(--card-border)] bg-[var(--card-bg)]">
        <p className="mb-2 text-sm font-medium" style={{ color: 'var(--text-muted)' }}>GMV Trend</p>
        <ResponsiveContainer width="100%" height="100%">
          <LineChart data={trendData}>
            <CartesianGrid stroke="var(--card-border)" strokeDasharray="3 3" />
            <XAxis dataKey="day" stroke="var(--text-muted)" />
            <YAxis stroke="var(--text-muted)" />
            <Tooltip />
            <Line type="monotone" dataKey="gmv" stroke="#25d366" strokeWidth={2.5} />
          </LineChart>
        </ResponsiveContainer>
      </div>
      <div className="h-72 rounded-2xl border p-3 border-[var(--card-border)] bg-[var(--card-bg)]">
        <p className="mb-2 text-sm font-medium" style={{ color: 'var(--text-muted)' }}>Orders Trend</p>
        <ResponsiveContainer width="100%" height="100%">
          <BarChart data={trendData}>
            <CartesianGrid stroke="var(--card-border)" strokeDasharray="3 3" />
            <XAxis dataKey="day" stroke="var(--text-muted)" />
            <YAxis stroke="var(--text-muted)" />
            <Tooltip />
            <Bar dataKey="orders" fill="#22d3ee" radius={[6, 6, 0, 0]} />
          </BarChart>
        </ResponsiveContainer>
      </div>
    </div>
  );
}
