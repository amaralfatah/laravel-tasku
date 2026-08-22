import { useState } from 'react';
import { OrgUnitSearch } from '@/components/org-unit-search';
import { Button } from '@/components/ui/button';
import type { NamedRef } from '@/types/members';

/**
 * Picks one org unit by searching for it.
 *
 * A workspace fed by the SAP import holds tens of thousands of units, so the
 * old dropdown of every unit is not an option: the current pick is shown as
 * text and anything else is found by name.
 */
export function OrgUnitPicker({
    value,
    onChange,
    canChoose,
    emptyLabel = 'Belum dipilih',
    clearLabel,
    disabled = false,
}: {
    /** The unit currently picked, or null. */
    value: NamedRef | null;
    onChange: (unit: NamedRef | null) => void;
    /** False when the viewer may not place anything outside their own unit. */
    canChoose: boolean;
    emptyLabel?: string;
    /** When set, a button that clears the pick, e.g. "Semua unit". */
    clearLabel?: string;
    disabled?: boolean;
}) {
    const [open, setOpen] = useState(false);

    return (
        <div className="space-y-2">
            <div className="flex items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm">
                <span className="truncate">{value?.name ?? emptyLabel}</span>

                <div className="flex shrink-0 items-center gap-1">
                    {clearLabel && value !== null && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            disabled={disabled}
                            onClick={() => {
                                onChange(null);
                                setOpen(false);
                            }}
                        >
                            {clearLabel}
                        </Button>
                    )}

                    {canChoose && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={disabled}
                            onClick={() => setOpen((current) => !current)}
                        >
                            {open ? 'Tutup' : value ? 'Ganti' : 'Pilih unit'}
                        </Button>
                    )}
                </div>
            </div>

            {canChoose && open && (
                <OrgUnitSearch
                    autoFocus
                    placeholder="Cari unit…"
                    onSelect={(hit) => {
                        onChange({ id: hit.id, name: hit.name });
                        setOpen(false);
                    }}
                />
            )}
        </div>
    );
}
