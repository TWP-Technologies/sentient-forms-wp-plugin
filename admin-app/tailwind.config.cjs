/** @type {import('tailwindcss').Config} */
const defaultTheme = require('tailwindcss/defaultTheme');

module.exports = {
	content: ['./src/**/*.{html,js,svelte,ts}'],
	prefix: 'sf-',
	theme: {
			extend: {
				colors: {
				primary: {
					50: '#EFF6FF',
					100: '#DBEAFE',
					500: '#2563EB',
					600: '#1D4ED8',
					700: '#1E40AF'
				},
				success: {
					50: '#ECFDF5',
					500: '#22C55E',
					600: '#16A34A'
				},
				warning: {
					50: '#FFFBEB',
					500: '#F59E0B',
					600: '#D97706'
				},
				danger: {
					50: '#FEF2F2',
					500: '#EF4444',
					600: '#DC2626'
				},
				muted: {
					100: '#F1F5F9',
					200: '#E2E8F0',
					400: '#94A3B8',
					500: '#64748B'
				}
			},
			fontFamily: {
				sans: ['Inter', ...defaultTheme.fontFamily.sans]
			},
			boxShadow: {
				card: '0 1px 2px 0 rgba(15, 23, 42, 0.08)',
				'inset-card': 'inset 0 1px 2px rgba(15, 23, 42, 0.06)'
			},
			borderRadius: {
				lg: '0.75rem'
			},
			spacing: {
				'18': '4.5rem'
			}
		}
	},
	plugins: [require('@tailwindcss/forms'), require('@tailwindcss/typography')]
};
