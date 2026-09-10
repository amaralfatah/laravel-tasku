import { cn } from '@/lib/utils';

/**
 * 12 curated vibrant colors for project avatars, reminiscent of Jira's project icons.
 */
export const PROJECT_COLOR_CLASSES = [
    'bg-blue-600 dark:bg-blue-500 text-white',
    'bg-emerald-600 dark:bg-emerald-500 text-white',
    'bg-violet-600 dark:bg-violet-500 text-white',
    'bg-amber-600 dark:bg-amber-500 text-white',
    'bg-rose-600 dark:bg-rose-500 text-white',
    'bg-indigo-600 dark:bg-indigo-500 text-white',
    'bg-cyan-600 dark:bg-cyan-500 text-white',
    'bg-orange-600 dark:bg-orange-500 text-white',
    'bg-pink-600 dark:bg-pink-500 text-white',
    'bg-teal-600 dark:bg-teal-500 text-white',
    'bg-purple-600 dark:bg-purple-500 text-white',
    'bg-lime-600 dark:bg-lime-500 text-white',
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
 * Deterministically assign a vibrant color based on project ID or name.
 */
export function getProjectColor(id: number | string, name?: string): string {
    if (typeof id === 'number' && id > 0) {
        return PROJECT_COLOR_CLASSES[id % PROJECT_COLOR_CLASSES.length];
    }

    let hash = 0;
    const str = name || String(id);
    for (let i = 0; i < str.length; i++) {
        hash = (hash << 5) - hash + str.charCodeAt(i);
        hash |= 0;
    }

    return PROJECT_COLOR_CLASSES[Math.abs(hash) % PROJECT_COLOR_CLASSES.length];
}

type ProjectAvatarProps = {
    id: number | string;
    name: string;
    className?: string;
};

export function ProjectAvatar({ id, name, className }: ProjectAvatarProps) {
    return (
        <span
            aria-hidden
            className={cn(
                'flex size-5 shrink-0 select-none items-center justify-center rounded font-semibold shadow-xs',
                getProjectColor(id, name),
                className,
            )}
        >
            {getProjectInitials(name)}
        </span>
    );
}
