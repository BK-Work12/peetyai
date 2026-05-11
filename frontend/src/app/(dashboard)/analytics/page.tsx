'use client';

import { AnalyticsCharts } from '@/components/dashboard/AnalyticsCharts';
import { ProductSelector } from '@/components/dashboard/ProductSelector';

const options = [
  { id: 1, name: 'Evian 1.5L', brand: 'Evian' },
  { id: 2, name: 'Al Ain 1.5L', brand: 'Al Ain' },
];

export default function AnalyticsPage() {
  return (
    <div className="space-y-5">
      <AnalyticsCharts />
      <ProductSelector options={options} onSelect={() => {}} />
    </div>
  );
}
