import { cva } from 'class-variance-authority';

export const badgeStyles = cva(
	'sf-inline-flex sf-items-center sf-rounded-full sf-px-2.5 sf-py-0.5 sf-text-xs sf-font-medium',
	{
		variants: {
			variant: {
				neutral: 'sf-bg-slate-100 sf-text-slate-600',
				success: 'sf-bg-success-50 sf-text-success-600',
				warning: 'sf-bg-warning-50 sf-text-warning-600',
				danger: 'sf-bg-danger-50 sf-text-danger-600',
				info: 'sf-bg-primary-50 sf-text-primary-600'
			}
		},
		defaultVariants: {
			variant: 'neutral'
		}
	}
);
