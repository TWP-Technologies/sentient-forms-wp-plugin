import { cva } from 'class-variance-authority';

export const buttonStyles = cva(
	'sf-inline-flex sf-items-center sf-justify-center sf-gap-2 sf-rounded sf-font-medium sf-transition-colors focus:sf-outline-none focus-visible:sf-ring-2 focus-visible:sf-ring-offset-2 disabled:sf-opacity-50 disabled:sf-cursor-not-allowed',
	{
		variants: {
			variant: {
				primary: 'sf-bg-primary-600 sf-text-white hover:sf-bg-primary-700 focus-visible:sf-ring-primary-600',
				secondary: 'sf-bg-white sf-text-slate-900 sf-border sf-border-slate-200 hover:sf-bg-slate-100 focus-visible:sf-ring-slate-200',
				ghost: 'sf-bg-transparent sf-text-slate-700 hover:sf-bg-slate-100',
				danger: 'sf-bg-danger-500 sf-text-white hover:sf-bg-danger-600 focus-visible:sf-ring-danger-500'
			},
			size: {
				sm: 'sf-h-8 sf-px-3 sf-text-sm',
				md: 'sf-h-10 sf-px-4 sf-text-sm',
				lg: 'sf-h-11 sf-px-6 sf-text-base'
			}
		},
		defaultVariants: {
			variant: 'primary',
			size: 'md'
		}
	}
);
