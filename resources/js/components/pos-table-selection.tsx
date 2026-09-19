import type { BranchTable } from '@/types/pos';

export function PosTableSelection({
    tables,
    tableId,
    onChange,
    disabled = false,
}: {
    tables: BranchTable[];
    tableId: string;
    onChange: (value: string) => void;
    disabled?: boolean;
}) {
    return (
        <fieldset disabled={disabled}>
            <legend className="mb-2 text-[10px] font-semibold tracking-wider text-neutral-500 uppercase">
                Table selection · optional
            </legend>
            <div className="flex flex-wrap gap-[7px]">
                {tables.map((table) => (
                    <button
                        key={table.id}
                        type="button"
                        aria-pressed={tableId === table.id}
                        onClick={() =>
                            onChange(tableId === table.id ? '' : table.id)
                        }
                        className={`min-h-10 rounded-full border px-3 text-xs font-semibold disabled:opacity-50 ${tableId === table.id ? 'border-neutral-950 bg-neutral-950 text-white' : 'border-neutral-300 bg-white text-neutral-950 hover:bg-neutral-50'}`}
                    >
                        {table.name}
                    </button>
                ))}
            </div>
            {tables.length === 0 && (
                <p className="mt-2 text-xs text-neutral-500">
                    No active tables are available. Continue without a table.
                </p>
            )}
        </fieldset>
    );
}
