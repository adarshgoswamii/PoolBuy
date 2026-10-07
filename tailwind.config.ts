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
          DEFAULT: "#E06214",
          dark: "#C4510D",
          light: "#FFF4ED",
        },
        surface: {
          page: "#FAF8F5",
          card: "#FFFFFF",
          warm: "#F4EFEA",
          muted: "#F8FAFC",
        },
      },
      fontFamily: {
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
