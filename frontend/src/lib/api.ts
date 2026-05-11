import axios from 'axios';
import type { DashboardOrder, OwnerSummary, RetailerProfile, RetailerRow, RetailerSettings, WhatsAppStatus } from '@/lib/types';

type OrdersApiResponse = {
  data?: Array<{
    id: number;
    customer?: { name?: string; phone?: string };
    total?: number | string;
    status: DashboardOrder['status'];
    created_at: string;
    items_count?: number;
    items?: Array<unknown>;
  }>;
};

const api = axios.create({
  baseURL: process.env.NEXT_PUBLIC_API_BASE_URL ?? '/api',
  timeout: 15000,
});

// Attach auth token from localStorage on every request
api.interceptors.request.use((config) => {
  if (typeof window !== 'undefined') {
    try {
      const raw = localStorage.getItem('peety-auth');
      const parsed = raw ? JSON.parse(raw) : null;
      const token: string | null = parsed?.state?.token ?? null;
      if (token) config.headers['Authorization'] = `Bearer ${token}`;
    } catch { /* ignore */ }
  }
  return config;
});

// ─── Auth ─────────────────────────────────────────────────────────────────────
export async function login(email: string, password: string) {
  const { data } = await api.post('/auth/token', { email, password, device_name: 'dashboard' });
  return data as { token: string; user: { id: number; name: string; email: string; role: string; retailer_id: number | null } };
}

// ─── Orders ──────────────────────────────────────────────────────────────────
export async function fetchOrders(retailerId: number): Promise<DashboardOrder[]> {
  const { data } = await api.get<OrdersApiResponse>('/orders', { params: { retailer_id: retailerId } });
  const payload = data?.data ?? [];
  return payload.map((o) => ({
    id: o.id,
    customer: o.customer?.name ?? o.customer?.phone ?? 'Guest',
    total: Number(o.total ?? 0),
    status: o.status,
    created_at: o.created_at,
    items_count: o.items_count ?? o.items?.length ?? 0,
  }));
}

export async function fetchOrderDetail(orderId: number) {
  const { data } = await api.get(`/orders/${orderId}`);

  const normalizedItems = Array.isArray(data?.items)
    ? data.items.map((item: any) => ({
        id: Number(item.id),
        product_name: item.product_name ?? 'Unknown',
        qty: Number(item.qty ?? item.quantity ?? 0),
        unit_price: Number(item.unit_price ?? item.price ?? 0),
        subtotal: Number(item.subtotal ?? item.line_total ?? 0),
      }))
    : [];

  const normalizedStatusLogs = Array.isArray(data?.status_logs ?? data?.statusLogs)
    ? (data.status_logs ?? data.statusLogs).map((log: any) => ({
        id: Number(log.id),
        status: log.status ?? log.to_status ?? log.toStatus ?? 'unknown',
        note: log.note ?? null,
        created_at: log.created_at ?? log.createdAt,
        user: log.user ? { name: log.user.name } : undefined,
      }))
    : [];

  return {
    ...data,
    items: normalizedItems,
    status_logs: normalizedStatusLogs,
  };
}

export async function updateOrderStatus(orderId: number, status: string, note?: string) {
  const { data } = await api.patch(`/orders/${orderId}`, { status, note });
  return data;
}

export async function createOrder(payload: {
  retailer_id: number;
  phone: string;
  items: Array<{ product_id: number; qty: number }>;
  notes?: string;
}) {
  const { data } = await api.post('/orders', payload);
  return data;
}

// ─── Dashboard ───────────────────────────────────────────────────────────────
export async function fetchRetailerStats(retailerId: number) {
  const { data } = await api.get('/dashboard/retailer', { params: { retailer_id: retailerId } });
  return data;
}

// ─── Products / Inventory ────────────────────────────────────────────────────
export async function fetchProducts(retailerId: number, search = '') {
  const { data } = await api.get('/products', { params: { retailer_id: retailerId, search, per_page: 50 } });
  return data;
}

export async function createProduct(payload: Record<string, unknown>) {
  const { data } = await api.post('/products', payload);
  return data;
}

export async function updateProduct(productId: number, payload: Record<string, unknown>) {
  const { data } = await api.put(`/products/${productId}`, payload);
  return data;
}

export async function deleteProduct(productId: number) {
  await api.delete(`/products/${productId}`);
}

export async function adjustStock(productId: number, adjustment: number, reason?: string) {
  const { data } = await api.post(`/products/${productId}/adjust-stock`, { adjustment, reason });
  return data;
}

export async function fetchCategories() {
  const { data } = await api.get('/categories');
  return data as Array<{ id: number; name: string }>;
}

// ─── Customers ───────────────────────────────────────────────────────────────
export async function fetchCustomers(retailerId: number, page = 1) {
  const { data } = await api.get('/customers', { params: { retailer_id: retailerId, page } });
  return data;
}

export async function fetchCustomerDetail(customerId: number) {
  const { data } = await api.get(`/customers/${customerId}`);
  return data;
}

// ─── Settings ────────────────────────────────────────────────────────────────
export async function fetchRetailerSettings(retailerId: number): Promise<{ retailer: RetailerProfile; settings: RetailerSettings; whatsapp_status?: WhatsAppStatus }> {
  const { data } = await api.get(`/retailers/${retailerId}/settings`);
  return data;
}

export async function saveRetailerSettings(
  retailerId: number,
  payload: Partial<RetailerProfile> & { settings?: RetailerSettings },
): Promise<{ retailer: RetailerProfile; settings: RetailerSettings }> {
  const { data } = await api.put(`/retailers/${retailerId}/settings`, payload);
  return data;
}

export async function testWhatsAppConnection(retailerId: number): Promise<{ whatsapp_status: WhatsAppStatus }> {
  const { data } = await api.post(`/retailers/${retailerId}/settings/whatsapp/test`);
  return data;
}

// ─── Owner ───────────────────────────────────────────────────────────────────
export async function fetchOwnerSummary(): Promise<OwnerSummary> {
  const { data } = await api.get('/owner/summary');
  return data;
}

export async function fetchOwnerRetailers(): Promise<{ data: RetailerRow[] }> {
  const { data } = await api.get('/owner/retailers');
  return data;
}

export async function createRetailer(payload: {
  name: string; email: string; phone: string; address?: string;
  delivery_radius_km?: number; commission_rate?: number;
  admin_name: string; admin_email: string; admin_password: string;
}) {
  const { data } = await api.post('/owner/retailers', payload);
  return data;
}

export async function updateRetailer(retailerId: number, patch: { active?: boolean; commission_rate?: number }) {
  const { data } = await api.patch(`/owner/retailers/${retailerId}`, patch);
  return data;
}

// ─── Chats ───────────────────────────────────────────────────────────────────
export async function fetchChats(retailerId: number, page = 1) {
  const { data } = await api.get('/chats', { params: { retailer_id: retailerId, page } });
  return data;
}

export async function fetchChatThread(customerId: number) {
  const { data } = await api.get(`/chats/${customerId}`);
  return data as { customer: { id: number; name: string; phone: string; address?: string }; messages: Array<{ id: number; direction: string; body: string; created_at: string }> };
}

// ─── Categories ───────────────────────────────────────────────────────────────
export async function createCategory(name: string, retailerId?: number) {
  const { data } = await api.post('/categories', { name, retailer_id: retailerId ?? null });
  return data as { id: number; name: string };
}

export default api;


