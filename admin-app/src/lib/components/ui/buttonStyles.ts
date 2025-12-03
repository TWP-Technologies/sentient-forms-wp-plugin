import { cva } from 'class-variance-authority';

export const buttonStyles = cva(
	'sf:inline-flex sf:items-center sf:justify-center sf:gap-2 sf:rounded sf:font-medium sf:transition-colors sf:focus:outline-hidden sf:focus-visible:ring-2 sf:focus-visible:ring-offset-2 sf:disabled:opacity-50 sf:disabled:cursor-not-allowed',
	{
		variants: {
			variant: {
				primary: 'sf:bg-primary-600 sf:text-white sf:hover:bg-primary-700 sf:focus-visible:ring-primary-600',
				secondary: 'sf:bg-white sf:text-slate-900 sf:border sf:border-slate-200 sf:hover:bg-slate-100 sf:focus-visible:ring-slate-200',
				ghost: 'sf:bg-transparent sf:text-slate-700 sf:hover:bg-slate-100',
				danger: 'sf:bg-danger-500 sf:text-white sf:hover:bg-danger-600 sf:focus-visible:ring-danger-500'
			},
			size: {
				sm: 'sf:h-8 sf:px-3 sf:text-sm',
				md: 'sf:h-10 sf:px-4 sf:text-sm',
				lg: 'sf:h-11 sf:px-6 sf:text-base'
			}
		},
		defaultVariants: {
			variant: 'primary',
			size: 'md'
		}
	}
);
