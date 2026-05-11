export type OrderStatus = 'placed' | 'picking' | 'packed' | 'dispatched' | 'delivered';

export type DashboardOrder = {
  id: number;
  customer: string;
  total: number;
  status: OrderStatus;
  created_at: string;
  items_count: number;
};

export type OrderDetail = {
  id: number;
  total: number;
  status: OrderStatus;
  notes: string | null;
  created_at: string;
  customer: { id: number; name: string; phone: string } | null;
  items: Array<{ id: number; product_name: string; qty: number; unit_price: number; subtotal: number }>;
  status_logs: Array<{ id: number; status: string; note: string | null; created_at: string; user?: { name: string } }>;
};

export type DashboardStat = {
  label: string;
  value: string;
  delta: string;
};

export type ProductOption = {
  id: number;
  name: string;
  brand?: string;
};

export type CustomerSnapshot = {
  id: number;
  name: string;
  phone: string;
  lifetimeValue: number;
  lastOrderAt: string;
};

export type Product = {
  id: number;
  retailer_id: number;
  category_id: number | null;
  name: string;
  sku: string | null;
  brand: string | null;
  unit: string | null;
  price: number;
  stock: number;
  is_active: boolean;
  priority: number;
  category?: { id: number; name: string } | null;
};

export type Customer = {
  id: number;
  retailer_id: number;
  name: string;
  phone: string;
  email: string | null;
  address: string | null;
  orders_count: number;
  orders_sum_total: number | null;
  created_at: string;
};

export type RetailerRow = {
  id: number;
  name: string;
  email: string;
  phone: string;
  active: boolean;
  commission_rate: number;
  delivery_radius_km: number;
  orders_count: number;
  orders_sum_total: number | null;
  created_at: string;
};

export type OwnerSummary = {
  retailers: number;
  active_retailers: number;
  orders: number;
  gmv: number;
  estimated_commission: number;
};

export type RetailerSettings = {
  whatsapp?: {
    phone_number_id?: string;
    access_token?: string;
    verify_token?: string;
    business_account_id?: string;
  };
  ai?: {
    openai_api_key?: string;
    model?: string;
    temperature?: number;
    system_prompt?: string;
  };
  notifications?: {
    email_on_new_order?: boolean;
    email_on_low_stock?: boolean;
    low_stock_threshold?: number;
  };
};

export type WhatsAppAttempt = {
  source: string;
  success: boolean;
  error?: string;
};

export type WhatsAppFailure = {
  message_id: number;
  credential_source: string;
  credential_attempts: WhatsAppAttempt[];
  send_error: string;
  provider_error_code?: number | null;
  created_at: string;
};

export type WhatsAppStatus = {
  configured: boolean;
  default_source: string;
  valid: boolean;
  message: string;
  credential_source: string;
  attempts?: WhatsAppAttempt[];
  phone_number_id?: string;
  display_phone_number?: string;
  verified_name?: string;
  last_failure?: WhatsAppFailure | null;
};

export type RetailerProfile = {
  id: number;
  name: string;
  slug: string;
  phone: string;
  email: string;
  address: string;
  delivery_radius_km: number;
  commission_rate: number;
  active: boolean;
};

