export type UserRole = 'admin' | 'seller' | 'buyer';

export type PoolStatus = 'draft' | 'active' | 'reached' | 'closed' | 'fulfilled' | 'cancelled' | 'expired';

export interface Profile {
  id: string;
  email: string;
  role: UserRole;
  company_name?: string;
  contact_name?: string;
  gstin?: string;
  phone?: string;
  is_verified: boolean;
  created_at: string;
}

export interface FilterSchemaOption {
  key: string;
  label: string;
  type: 'single_select' | 'multi_select' | 'color_palette' | 'badge_select' | 'range_slider';
  options: string[];
}

export interface Subcategory {
  id: string;
  category_id: string;
  name: string;
  slug: string;
  description?: string;
  filter_schema: {
    filters: FilterSchemaOption[];
  };
  sort_order: number;
  is_active: boolean;
}

export interface Category {
  id: string;
  name: string;
  slug: string;
  description?: string;
  icon?: string;
  sort_order: number;
  is_active: boolean;
  subcategories?: Subcategory[];
}

export interface Product {
  id: string;
  seller_id: string;
  category_id: string;
  subcategory_id: string;
  title: string;
  slug: string;
  sku?: string;
  description: string;
  images: string[];
  base_price: number;
  mrp?: number;
  stock_quantity: number;
  attributes: Record<string, any>;
  specifications?: Record<string, string>;
  is_approved: boolean;
  is_active: boolean;
  created_at: string;
  seller?: Partial<Profile>;
}

export interface PoolTier {
  id: string;
  pool_id: string;
  tier_number: number;
  min_qty: number;
  max_qty?: number | null;
  unit_price: number;
}

export interface Pool {
  id: string;
  pool_code: string;
  product_id: string;
  seller_id: string;
  moq: number;
  reserved_qty: number;
  min_per_buyer: number;
  max_per_buyer?: number;
  status: PoolStatus;
  current_tier_price: number;
  expires_at: string;
  product?: Product;
  tiers?: PoolTier[];
  seller?: Partial<Profile>;
  commitments_count?: number;
}

export interface Commitment {
  id: string;
  pool_id: string;
  buyer_id: string;
  quantity: number;
  locked_unit_price: number;
  final_unit_price?: number;
  status: 'pending' | 'confirmed' | 'refunded' | 'cancelled';
  shipping_address: {
    address_line: string;
    city: string;
    state: string;
    postal_code: string;
    country: string;
  };
  created_at: string;
  pool?: Pool;
  buyer?: Profile;
}

export interface Order {
  id: string;
  order_number: string;
  pool_id: string;
  buyer_id: string;
  commitment_id?: string;
  quantity: number;
  unit_price: number;
  subtotal: number;
  platform_fee: number; // 2%
  gst_amount: number;   // 18%
  total_amount: number;
  order_status: 'processing' | 'manufacturing' | 'shipped' | 'delivered' | 'cancelled';
  payment_status: 'pending' | 'escrow_hold' | 'settled' | 'refunded';
  created_at: string;
}
