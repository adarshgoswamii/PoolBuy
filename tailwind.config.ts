import type { Config } from "tailwindcss";

const config: Config = {
  darkMode: ["class"],
  content: [
    "./src/pages/**/*.{js,ts,jsx,tsx,mdx}",
    "./src/components/**/*.{js,ts,jsx,tsx,mdx}",
    "./src/app/**/*.{js,ts,jsx,tsx,mdx}",
  ],
  theme: {
    extend: {
      colors: {
        primary: {
          DEFAULT: "#0F1D36",
          dark: "#091224",
          light: "#1A2D4F",
          50: "#EEF3F9",
        },
        gold: {
          DEFAULT: "#C89D46",
          dark: "#B98A32",
          light: "#FBF6EB",
        },
        accent: {
          DEFAULT: "#DC2626",
          dark: "#B91C1C",
          light: "#FEF2F2",
          50: "#FEF2F2",
          100: "#FEE2E2",
          200: "#FECACA",
          300: "#FCA5A5",
          400: "#F87171",
          500: "#EF4444",
          600: "#DC2626",
          700: "#B91C1C",
          800: "#991B1B",
          900: "#7F1D1D",
        },
        surface: {
          page: "#FAF8F5",
          card: "#FFFFFF",
          warm: "#F4EFEA",
          muted: "#F8FAFC",
          hero: "#F5EFEB",
          heroDark: "#ECE5DC",
        },
      },
      borderRadius: {
        btn: "30px",
        card: "14px",
      },
      fontFamily: {
        display: ["'Playfair Display'", "Georgia", "serif"],
        serif: ["'Playfair Display'", "Georgia", "serif"],
        sans: ["Inter", "system-ui", "-apple-system", "sans-serif"],
        mono: ["JetBrains Mono", "monospace"],
      },
      boxShadow: {
        subtle: "0 1px 3px rgba(15,29,54,.04)",
        card: "0 4px 14px rgba(15,29,54,.07)",
        elevated: "0 12px 32px rgba(15,29,54,.12)",
      },
    },
  },
  plugins: [],
};

export default config;
