import type { LucideIcon } from 'lucide-react';
import { Check, Moon, Sun } from 'lucide-react';
import type { Appearance } from '@/hooks/use-appearance';
import { useAppearance } from '@/hooks/use-appearance';

const OPTIONS: {
    value: Appearance;
    icon: LucideIcon;
    label: string;
    description: string;
}[] = [
    {
        value: 'light',
        icon: Sun,
        label: 'Light',
        description: 'The standard PONGSKILOG look. Best in bright stores.',
    },
    {
        value: 'dark',
        icon: Moon,
        label: 'Dark',
        description: 'Easier on the eyes in dim rooms and at night.',
    },
];

/** Light or Dark for this device (default Light). Customer-facing screens always stay Light. */
export default function AppearanceToggleTab() {
    const { appearance, updateAppearance } = useAppearance();

    return (
        <div
            role="radiogroup"
            aria-label="Appearance"
            className="grid gap-2 sm:grid-cols-2"
        >
            {OPTIONS.map(({ value, icon: Icon, label, description }) => {
                const selected = appearance === value;

                return (
                    <button
                        key={value}
                        type="button"
                        role="radio"
                        aria-checked={selected}
                        onClick={() => updateAppearance(value)}
                        className={`flex min-h-16 items-start gap-3 rounded-2xl border p-3.5 text-left transition-colors focus-visible:ring-2 focus-visible:ring-neutral-950 focus-visible:outline-none ${selected ? 'border-neutral-950 bg-white' : 'border-neutral-200 bg-white hover:bg-neutral-50'}`}
                    >
                        <span
                            className={`flex size-10 shrink-0 items-center justify-center rounded-xl ${value === 'dark' ? 'theme-static bg-neutral-900 text-white' : 'bg-neutral-100 text-neutral-900'}`}
                        >
                            <Icon className="size-5" aria-hidden="true" />
                        </span>
                        <span className="min-w-0 flex-1">
                            <span className="flex items-center gap-2 text-sm font-bold text-neutral-950">
                                {label}
                                {selected && (
                                    <Check
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                )}
                            </span>
                            <span className="mt-0.5 block text-xs leading-5 text-neutral-500">
                                {description}
                            </span>
                        </span>
                    </button>
                );
            })}
        </div>
    );
}
