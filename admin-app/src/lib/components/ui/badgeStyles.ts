import { cva } from 'class-variance-authority';

export const badgeStyles = cva(
	'sf:inline-flex sf:items-center sf:rounded-full sf:px-2.5 sf:py-0.5 sf:text-xs sf:font-medium',
	{
		variants: {
			variant: {
				neutral: 'sf:bg-slate-100 sf:text-slate-700',
				success: 'sf:bg-success-50 sf:text-success-700',
				warning: 'sf:bg-warning-50 sf:text-warning-700',
				danger: 'sf:bg-danger-50 sf:text-danger-700',
				info: 'sf:bg-primary-50 sf:text-primary-700'
			}
		},
		defaultVariants: {
			variant: 'neutral'
		}
	}
);
