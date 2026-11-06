/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{js,jsx,ts,tsx}'],
  theme: {
    extend: {
      colors: {
        primary: {
          50: '#F1F0FD',
          100: '#E3E1FA',
          200: '#C7C3F4',
          500: '#6458D8',
          600: '#5646C8',
          700: '#4538A8',
          900: '#1E1B4B',
        },
        navy: {
          DEFAULT: '#1B2559',
          700: '#2D2F7F',
        },
        accent: {
          400: '#2DD4BF',
          600: '#0D9488',
        },
        success: { 50: '#ECFDF5', 200: '#A7F3D0', 700: '#047857' },
        warning: { 50: '#FFFBEB', 200: '#FDE68A', 700: '#B45309' },
        danger: { 50: '#FEF2F2', 200: '#FECACA', 700: '#B91C1C' },
        info: { 50: '#F0F9FF', 200: '#BAE6FD', 700: '#0369A1' },
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', 'sans-serif'],
      },
      boxShadow: {
        card: '0 12px 32px rgb(15 23 42 / 7%)',
      },
    },
  },
  plugins: [],
}
