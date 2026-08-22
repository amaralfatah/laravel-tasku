import { Loader2, Search, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { search as searchUnits } from '@/routes/org-units';
import type { OrgUnitHit } from '@/types/organization';

/** Shortest term the server will answer, mirrored here to save a round trip. */
const MIN_TERM = 2;

const DEBOUNCE_MS = 250;

/**
 * Name search across the whole org tree.
 *
 * With tens of thousands of units imported from SAP, opening branch after
 * branch is not a way to find anything — this is. Results carry their ancestor
 * trail so two units with the same name stay tellable apart.
 */
export function OrgUnitSearch({
    onSelect,
    excludeSubtreeOf,
    placeholder = 'Cari unit…',
    autoFocus = false,
    emptyHint,
}: {
    onSelect: (unit: OrgUnitHit) => void;
    /** Hide the unit at this path and everything under it. */
    excludeSubtreeOf?: string | null;
    placeholder?: string;
    autoFocus?: boolean;
    emptyHint?: string;
}) {
    const [term, setTerm] = useState('');
    const [hits, setHits] = useState<OrgUnitHit[] | null>(null);
    const [searching, setSearching] = useState(false);

    // Only the newest request may write to state; a slow earlier one is dropped.
    const requestId = useRef(0);

    useEffect(() => {
        const trimmed = term.trim();

        if (trimmed.length < MIN_TERM) {
            setHits(null);
            setSearching(false);

            return;
        }

        const id = ++requestId.current;
        const controller = new AbortController();

        setSearching(true);

        const timer = window.setTimeout(() => {
            fetch(searchUnits({ query: { q: trimmed } }).url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            })
                .then((response) => (response.ok ? response.json() : { units: [] }))
                .then((payload: { units: OrgUnitHit[] }) => {
                    if (id === requestId.current) {
                        setHits(payload.units);
                    }
                })
                .catch(() => {
                    if (id === requestId.current) {
                        setHits([]);
                    }
                })
                .finally(() => {
                    if (id === requestId.current) {
                        setSearching(false);
                    }
                });
        }, DEBOUNCE_MS);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [term]);

    const visible = (hits ?? []).filter(
        (hit) =>
            !excludeSubtreeOf || !hit.path.startsWith(excludeSubtreeOf),
    );

    return (
        <div className="space-y-2">
            <div className="relative">
                <Search
                    className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    aria-hidden="true"
                />
                <Input
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                    placeholder={placeholder}
                    autoFocus={autoFocus}
                    aria-label="Cari unit organisasi"
                    className="pl-9"
                />
                {term !== '' && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="absolute top-1/2 right-1 size-7 -translate-y-1/2"
                        aria-label="Bersihkan pencarian"
                        onClick={() => setTerm('')}
                    >
                        <X className="size-4" />
                    </Button>
                )}
            </div>

            {searching && (
                <p className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Loader2 className="size-3.5 animate-spin" aria-hidden="true" />
                    Mencari…
                </p>
            )}

            {!searching && hits !== null && visible.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    Tidak ada unit yang cocok.
                </p>
            )}

            {!searching && hits === null && emptyHint && (
                <p className="text-sm text-muted-foreground">{emptyHint}</p>
            )}

            {visible.length > 0 && (
                <ul className="max-h-64 overflow-y-auto rounded-md border">
                    {visible.map((hit) => (
                        <li key={hit.id}>
                            <button
                                type="button"
                                onClick={() => onSelect(hit)}
                                className={cn(
                                    'flex w-full flex-col items-start gap-0.5 px-3 py-2 text-left',
                                    'hover:bg-muted focus-visible:bg-muted focus-visible:outline-none',
                                )}
                            >
                                <span className="font-medium">{hit.name}</span>
                                {hit.trail.length > 0 && (
                                    <span className="truncate text-xs text-muted-foreground">
                                        {hit.trail.join(' › ')}
                                    </span>
                                )}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
