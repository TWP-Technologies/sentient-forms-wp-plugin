import { cva } from 'class-variance-authority';

export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'dark' | 'inline';
export type ButtonSize = 'xs' | 'sm' | 'md' | 'lg';
export type ControlIntent = 'primary' | 'secondary' | 'tertiary' | 'danger';
export type ControlVariantPolicy = {
	control_id: string;
	intent: ControlIntent;
	visual_variant: ButtonVariant;
	min_affordance_score: number;
};

export const controlIntentToVariant: Record<ControlIntent, ButtonVariant> = {
	primary: 'primary',
	secondary: 'secondary',
	tertiary: 'ghost',
	danger: 'danger'
};

export function variantForIntent(intent: ControlIntent): ButtonVariant {
	return controlIntentToVariant[intent];
}

export const buttonStyles = cva(
	'sf:inline-flex sf:cursor-pointer sf:items-center sf:justify-center sf:gap-2 sf:rounded sf:border sf:font-medium sf:transition-colors sf:focus-visible:outline-none sf:focus-visible:ring-2 sf:focus-visible:ring-offset-2 sf:focus-visible:ring-offset-white sf:disabled:opacity-50 sf:disabled:cursor-not-allowed',
	{
		variants: {
			variant: {
				primary:
					'sf:border-primary-700 sf:bg-primary-600 sf:text-white sf:shadow-sm sf:hover:bg-primary-700 sf:hover:border-primary-700 sf:focus-visible:ring-primary-500',
				secondary:
					'sf:border-slate-300 sf:bg-white sf:text-slate-900 sf:shadow-sm sf:hover:bg-slate-50 sf:hover:border-slate-400 sf:focus-visible:ring-slate-600',
				ghost:
					'sf:border-slate-300 sf:bg-slate-50/70 sf:text-slate-700 sf:hover:bg-slate-100 sf:hover:border-slate-400 sf:focus-visible:ring-slate-600',
				danger:
					'sf:border-danger-600 sf:bg-danger-600 sf:text-white sf:shadow-sm sf:hover:bg-danger-500 sf:hover:border-danger-500 sf:focus-visible:ring-danger-500',
				dark:
					'sf:border-slate-900 sf:bg-slate-900 sf:text-white sf:shadow-sm sf:hover:bg-slate-800 sf:hover:border-slate-800 sf:focus-visible:ring-slate-900',
				inline:
					'sf:border-transparent sf:bg-transparent sf:p-0 sf:text-current sf:shadow-none sf:hover:bg-transparent sf:hover:border-transparent sf:focus-visible:ring-primary-500'
			},
			size: {
				xs: 'sf:h-5 sf:px-2 sf:text-xs',
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
