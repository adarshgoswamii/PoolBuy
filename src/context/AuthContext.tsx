'use client';

import React, { createContext, useContext, useEffect, useState } from 'react';
import { Profile, UserRole } from '@/lib/supabase/types';
import { INITIAL_PROFILES } from '@/lib/mock-data';

interface AuthContextType {
  user: Profile | null;
  role: UserRole;
  isAuthenticated: boolean;
  isLoading: boolean;
  login: (email: string, role?: UserRole) => Promise<void>;
  logout: () => void;
  switchRole: (newRole: UserRole) => void;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<Profile | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    // Check saved session in local storage
    const savedUser = localStorage.getItem('poolbuy_user');
    if (savedUser) {
      try {
        setUser(JSON.parse(savedUser));
      } catch {
        setUser(INITIAL_PROFILES[3]); // Default to buyer
      }
    } else {
      // Default to buyer session for instant interactive browsing
      setUser(INITIAL_PROFILES[3]);
    }
    setIsLoading(false);
  }, []);

  const login = async (email: string, preferredRole?: UserRole) => {
    setIsLoading(true);
    const existing = INITIAL_PROFILES.find((p) => p.email.toLowerCase() === email.toLowerCase());
    const targetUser: Profile = existing || {
      id: `usr-${Date.now()}`,
      email,
      role: preferredRole || 'buyer',
      company_name: 'Demo Enterprise Ltd.',
      contact_name: email.split('@')[0],
      is_verified: true,
      created_at: new Date().toISOString(),
    };

    setUser(targetUser);
    localStorage.setItem('poolbuy_user', JSON.stringify(targetUser));
    setIsLoading(false);
  };

  const logout = () => {
    localStorage.removeItem('poolbuy_user');
    setUser(null);
  };

  const switchRole = (newRole: UserRole) => {
    const matchingProfile = INITIAL_PROFILES.find((p) => p.role === newRole);
    if (matchingProfile) {
      setUser(matchingProfile);
      localStorage.setItem('poolbuy_user', JSON.stringify(matchingProfile));
    }
  };

  return (
    <AuthContext.Provider
      value={{
        user,
        role: user?.role || 'buyer',
        isAuthenticated: !!user,
        isLoading,
        login,
        logout,
        switchRole,
      }}
    >
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
}
