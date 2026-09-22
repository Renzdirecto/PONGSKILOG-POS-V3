import type { OperationalItemName as ItemName } from '@/lib/pos-item-name';

export function OperationalItemName({ value }: { value: ItemName }) {
    return (
        <span aria-label={value.displayName} className="wrap-anywhere">
            {value.sizePrefix && (
                <span className="mr-1.5 inline-block rounded border border-amber-200 bg-amber-100 px-1.5 py-px text-[10px] font-bold text-amber-900">
                    <span className="sr-only">Size: </span>
                    {value.sizePrefix}
                </span>
            )}
            <span>{value.name}</span>
        </span>
    );
}
