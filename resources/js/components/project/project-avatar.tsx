import { cn } from '@/lib/utils';

export interface ProjectColorPair {
    name: string;
    dark: string;
    light: string;
}

/**
 * Curated pairs of dark and light shades for each project avatar.
 * Creates an authentic enterprise dual-tone look with a horizontal cross,
 * eliminating the generic flat single-color AI template appearance.
 */
export const PROJECT_COLOR_PAIRS: ProjectColorPair[] = [
    { name: 'ocean', dark: '#1e3a8a', light: '#38bdf8' }, // Navy Blue & Electric Sky
    { name: 'emerald', dark: '#064e3b', light: '#34d399' }, // Deep Forest & Bright Mint
    { name: 'violet', dark: '#4c1d95', light: '#c084fc' }, // Royal Violet & Soft Lavender
    { name: 'amber', dark: '#78350f', light: '#facc15' }, // Deep Umber & Warm Gold
    { name: 'rose', dark: '#881337', light: '#fb7185' }, // Deep Crimson & Coral Pink
    { name: 'cyan', dark: '#164e63', light: '#22d3ee' }, // Dark Teal & Bright Cyan
    { name: 'indigo', dark: '#1e1b4b', light: '#818cf8' }, // Midnight Indigo & Periwinkle
    { name: 'orange', dark: '#7c2d12', light: '#fb923c' }, // Burnt Orange & Tangerine
    { name: 'fuchsia', dark: '#701a75', light: '#f472b6' }, // Mulberry Plum & Blossom Pink
    { name: 'teal-lime', dark: '#134e4a', light: '#a3e635' }, // Deep Teal & Vibrant Lime
    { name: 'slate', dark: '#0f172a', light: '#94a3b8' }, // Charcoal Slate & Silver Ice
    { name: 'ruby-gold', dark: '#7f1d1d', light: '#f59e0b' }, // Dark Ruby & Golden Flame
];

/**
 * Clean initials from project name (handles parentheticals like "Superman (Payment)" => "SP").
 */
export function getProjectInitials(name: string): string {
    const cleaned = name.replace(/[^\p{L}\p{N}\s]/gu, '').trim();
    const words = (cleaned || name).split(/\s+/).filter(Boolean);

    if (words.length === 0) {
        return name.slice(0, 2).toUpperCase();
    }

    return words
        .slice(0, 2)
        .map((word) => word[0]?.toUpperCase() ?? '')
        .join('');
}

/**
 * Deterministically assign a dark-light color pair based on project ID or name.
 */
export function getProjectColorPair(
    id: number | string,
    name?: string,
): ProjectColorPair {
    if (typeof id === 'number' && id > 0) {
        return PROJECT_COLOR_PAIRS[id % PROJECT_COLOR_PAIRS.length];
    }

    let hash = 0;
    const str = name || String(id);
    for (let i = 0; i < str.length; i++) {
        hash = (hash << 5) - hash + str.charCodeAt(i);
        hash |= 0;
    }

    return PROJECT_COLOR_PAIRS[Math.abs(hash) % PROJECT_COLOR_PAIRS.length];
}

type ProjectAvatarProps = {
    id: number | string;
    name: string;
    className?: string;
};

export function ProjectAvatar({ id, name, className }: ProjectAvatarProps) {
    const pair = getProjectColorPair(id, name);

    return (
        <span
            aria-hidden
            style={{
                backgroundImage: `linear-gradient(115deg, ${pair.dark} 0%, ${pair.dark} 47%, ${pair.light} 53%, ${pair.light} 100%)`,
            }}
            className={cn(
                'relative flex size-5 shrink-0 select-none items-center justify-center overflow-hidden rounded font-bold text-white shadow-xs ring-1 ring-inset ring-black/15 dark:ring-white/15',
                className,
            )}
        >
            <span className="drop-shadow-[0_1px_1.5px_rgba(0,0,0,0.7)]">
                {getProjectInitials(name)}
            </span>
        </span>
    );
}
