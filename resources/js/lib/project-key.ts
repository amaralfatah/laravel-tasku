/**
 * Prefill for the project key field, mirroring `Project::generateKey()` on the
 * server: initials for a multi word name, the first three letters otherwise.
 *
 * The server still has the last word — it appends a number when the key is
 * already taken, which the browser cannot know.
 */
export const PROJECT_KEY_MAX_LENGTH = 10;

export function suggestProjectKey(name: string): string {
    const words = name
        .split(/[^A-Za-z0-9]+/)
        .filter((word) => /^[A-Za-z]/.test(word));

    if (words.length === 0) {
        return '';
    }

    const base =
        words.length > 1
            ? words.map((word) => word[0]).join('')
            : words[0].slice(0, 3);

    return base.slice(0, PROJECT_KEY_MAX_LENGTH).toUpperCase();
}

/**
 * What a key becomes as it is typed: upper case, letters and digits only.
 */
export function normalizeProjectKey(value: string): string {
    return value
        .toUpperCase()
        .replace(/[^A-Z0-9]/g, '')
        .slice(0, PROJECT_KEY_MAX_LENGTH);
}
