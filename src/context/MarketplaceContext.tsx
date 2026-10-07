'use client';

import React, { createContext, useContext, useEffect, useState } from 'react';
import { Category, Commitment, Pool, Profile, Subcategory } from '@/lib/supabase/types';
import { INITIAL_CATEGORIES, INITIAL_POOLS, INITIAL_PROFILES } from '@/lib/mock-data';

interface MarketplaceContextType {
  categories: Category[];
  pools: Pool[];
  profiles: Profile[];
  commitments: Commitment[];
  joinPool: (poolId: string, quantity: number, address: any) => Promise<boolean>;
  approveSeller: (sellerId: string) => void;
  forceClosePool: (poolId: string) => void;
  repricePool: (poolId: string) => void;
  createPool: (pool: Partial<Pool>) => void;
  addCategory: (category: Partial<Category>) => void;
  updateSubcategoryFilterSchema: (subcategoryId: string, filterSchema: any) => void;
}

const MarketplaceContext = createContext<MarketplaceContextType | undefined>(undefined);

export function MarketplaceProvider({ children }: { children: React.ReactNode }) {
  const [categories, setCategories] = useState<Category[]>(INITIAL_CATEGORIES);
  const [pools, setPools] = useState<Pool[]>(INITIAL_POOLS);
  const [profiles, setProfiles] = useState<Profile[]>(INITIAL_PROFILES);
  const [commitments, setCommitments] = useState<Commitment[]>([
    {
      id: 'com-1',
      pool_id: 'pool-1',
      buyer_id: 'usr-buyer-1',
      quantity: 180,
      locked_unit_price: 420,
      final_unit_price: 340,
      status: 'confirmed',
      shipping_address: {
        address_line: 'Warehouse 4B, Okhla Phase III',
        city: 'New Delhi',
        state: 'Delhi',
        postal_code: '110020',
        country: 'India',
      },
      created_at: new Date().toISOString(),
    },
    {
      id: 'com-2',
      pool_id: 'pool-2',
      buyer_id: 'usr-buyer-2',
      quantity: 210,
      locked_unit_price: 1250,
      final_unit_price: 890,
      status: 'confirmed',
      shipping_address: {
        address_line: 'Plot 12, Whitefield Tech Zone',
        city: 'Bengaluru',
        state: 'Karnataka',
        postal_code: '560066',
        country: 'India',
      },
      created_at: new Date().toISOString(),
    },
  ]);

  // Join pool action (simulating join_pool_atomic RPC)
  const joinPool = async (poolId: string, quantity: number, address: any): Promise<boolean> => {
    const targetPool = pools.find((p) => p.id === poolId);
    if (!targetPool) return false;

    const newReservedQty = targetPool.reserved_qty + quantity;

    // Determine unlocked tier price
    let bestTierPrice = targetPool.current_tier_price;
    if (targetPool.tiers) {
      for (const tier of targetPool.tiers) {
        if (newReservedQty >= tier.min_qty) {
          bestTierPrice = tier.unit_price;
        }
      }
    }

    const isReached = newReservedQty >= targetPool.moq;

    setPools((prev) =>
      prev.map((p) => {
        if (p.id === poolId) {
          return {
            ...p,
            reserved_qty: newReservedQty,
            current_tier_price: bestTierPrice,
            status: isReached ? 'reached' : p.status,
            commitments_count: (p.commitments_count || 0) + 1,
          };
        }
        return p;
      })
    );

    const newCommitment: Commitment = {
      id: `com-${Date.now()}`,
      pool_id: poolId,
      buyer_id: 'usr-buyer-1',
      quantity,
      locked_unit_price: targetPool.current_tier_price,
      final_unit_price: bestTierPrice,
      status: 'confirmed',
      shipping_address: address,
      created_at: new Date().toISOString(),
    };

    setCommitments((prev) => [newCommitment, ...prev]);
    return true;
  };

  const approveSeller = (sellerId: string) => {
    setProfiles((prev) =>
      prev.map((p) => (p.id === sellerId ? { ...p, is_verified: true } : p))
    );
  };

  const forceClosePool = (poolId: string) => {
    setPools((prev) =>
      prev.map((p) => (p.id === poolId ? { ...p, status: 'closed' } : p))
    );
  };

  const repricePool = (poolId: string) => {
    setPools((prev) =>
      prev.map((p) => {
        if (p.id === poolId && p.tiers) {
          let lowestPrice = p.current_tier_price;
          for (const tier of p.tiers) {
            if (p.reserved_qty >= tier.min_qty) {
              lowestPrice = tier.unit_price;
            }
          }
          return { ...p, current_tier_price: lowestPrice };
        }
        return p;
      })
    );
  };

  const createPool = (newPool: Partial<Pool>) => {
    const poolToAdd: Pool = {
      id: `pool-${Date.now()}`,
      pool_code: `PB-NEW-${Math.floor(1000 + Math.random() * 9000)}`,
      product_id: newPool.product_id || 'prod-1',
      seller_id: newPool.seller_id || 'usr-seller-1',
      moq: newPool.moq || 100,
      reserved_qty: 0,
      min_per_buyer: newPool.min_per_buyer || 10,
      status: 'active',
      current_tier_price: newPool.current_tier_price || 500,
      expires_at: new Date(Date.now() + 14 * 86400000).toISOString(),
      commitments_count: 0,
      ...newPool,
    } as Pool;

    setPools((prev) => [poolToAdd, ...prev]);
  };

  const addCategory = (cat: Partial<Category>) => {
    const categoryToAdd: Category = {
      id: `cat-${Date.now()}`,
      name: cat.name || 'New Category',
      slug: cat.slug || 'new-category',
      description: cat.description || '',
      icon: cat.icon || 'category',
      sort_order: categories.length + 1,
      is_active: true,
      subcategories: [],
      ...cat,
    };
    setCategories((prev) => [...prev, categoryToAdd]);
  };

  const updateSubcategoryFilterSchema = (subcategoryId: string, filterSchema: any) => {
    setCategories((prev) =>
      prev.map((cat) => ({
        ...cat,
        subcategories: cat.subcategories?.map((sub) =>
          sub.id === subcategoryId ? { ...sub, filter_schema: filterSchema } : sub
        ),
      }))
    );
  };

  return (
    <MarketplaceContext.Provider
      value={{
        categories,
        pools,
        profiles,
        commitments,
        joinPool,
        approveSeller,
        forceClosePool,
        repricePool,
        createPool,
        addCategory,
        updateSubcategoryFilterSchema,
      }}
    >
      {children}
    </MarketplaceContext.Provider>
  );
}

export function useMarketplace() {
  const context = useContext(MarketplaceContext);
  if (!context) {
    throw new Error('useMarketplace must be used within a MarketplaceProvider');
  }
  return context;
}
